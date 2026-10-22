<?php

namespace App\Tests\Controller\Api;

use App\Entity\Event;
use App\Entity\Order;
use App\Entity\Product;
use App\Enum\DeviceRole;
use App\Enum\OrderStatus;
use App\Tests\FixturesTrait;
use App\Tests\OrderFixturesTrait;
use App\Tests\ResetDatabaseTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class KitchenApiTest extends WebTestCase
{
    use FixturesTrait;
    use OrderFixturesTrait;
    use ResetDatabaseTrait;

    private KernelBrowser $client;
    private Event $event;
    private string $token;
    private Product $crepe;
    private Product $soda;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetDatabase();
        self::getContainer()->get('cache.rate_limiter')->clear();

        $this->event = self::createEvent();
        $this->crepe = self::createProduct($this->event, 'Crêpe sucre', kitchen: true);
        $this->soda = self::createProduct($this->event, 'Soda');
        [, $this->token] = self::createPairedDevice($this->event, 'Cuisine', DeviceRole::Kitchen);
    }

    // --- Droits des rôles ---

    /** @return iterable<string, array{DeviceRole}> */
    public static function otherRoles(): iterable
    {
        yield 'caisse' => [DeviceRole::Till];
        yield 'affichage' => [DeviceRole::Display];
    }

    #[DataProvider('otherRoles')]
    public function testOnlyKitchensCanReadAndAdvanceOrders(DeviceRole $role): void
    {
        $order = self::createOrder($this->event, [[$this->crepe, 1]]);
        [, $token] = self::createPairedDevice($this->event, 'Autre tablette', $role);

        $this->client->request('GET', '/api/orders', server: $this->auth($token));
        self::assertResponseStatusCodeSame(403);

        $this->advance($order, 'prep', $token);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(OrderStatus::New, $this->reload($order)->getStatus());
    }

    public function testWithoutTokenOrdersAreNotShown(): void
    {
        self::createOrder($this->event, [[$this->crepe, 1]]);

        $this->client->request('GET', '/api/orders');

        self::assertResponseStatusCodeSame(401);
    }

    // --- Liste des commandes en cours ---

    public function testListsOpenOrdersOldestFirst(): void
    {
        $recent = self::createOrder($this->event, [[$this->crepe, 1]], createdAt: '-1 minute');
        $old = self::createOrder($this->event, [[$this->crepe, 2]], OrderStatus::Prep, createdAt: '-12 minutes');
        $ready = self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Ready, createdAt: '-5 minutes');
        self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Served);
        self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Cancelled);

        $data = $this->list();

        self::assertSame(
            [$old->getDisplayNumber(), $ready->getDisplayNumber(), $recent->getDisplayNumber()],
            array_column($data['orders'], 'displayNumber'),
        );
        self::assertSame(['prep', 'ready', 'new'], array_column($data['orders'], 'status'));
        self::assertEqualsWithDelta(time(), (new \DateTimeImmutable($data['serverTime']))->getTimestamp(), 5);
    }

    public function testTicketSeparatesKitchenAndCounterWithoutPrices(): void
    {
        self::createOrder($this->event, [[$this->crepe, 2], [$this->soda, 3]]);

        $ticket = $this->list()['orders'][0];

        self::assertSame([['label' => 'Crêpe sucre', 'quantity' => 2, 'components' => []]], $ticket['kitchenLines']);
        self::assertSame([['label' => 'Soda', 'quantity' => 3]], $ticket['counterLines']);
        $json = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Cents', $json, 'aucun montant pour la cuisine');
        self::assertStringNotContainsString('payment', $json);
    }

    public function testFormulaShowsItsCompositionForTheWholeLine(): void
    {
        $duo = self::createProduct($this->event, 'Formule Duo', kitchen: true);
        $em = self::entityManager();
        $em->find(Product::class, $duo->getId())
            ->addComponent($em->find(Product::class, $this->crepe->getId()), 2)
            ->addComponent($em->find(Product::class, $this->soda->getId()));
        $em->flush();

        self::createOrder($this->event, [[$duo, 2]]);

        self::assertSame([[
            'label' => 'Formule Duo',
            'quantity' => 2,
            'components' => [
                ['label' => 'Crêpe sucre', 'quantity' => 4],
                ['label' => 'Soda', 'quantity' => 2],
            ],
        ]], $this->list()['orders'][0]['kitchenLines']);
    }

    public function testOnlyTheDeviceEventIsShown(): void
    {
        $other = self::createEvent('Carnaval', active: false);
        self::createOrder($other, [[self::createProduct($other, 'Beignet', kitchen: true), 1]]);

        self::assertSame([], $this->list()['orders']);
    }

    public function testUnchangedListAnswersNotModified(): void
    {
        self::createOrder($this->event, [[$this->crepe, 1]]);
        $this->list();
        $etag = (string) $this->client->getResponse()->getEtag();
        self::assertNotSame('', $etag);

        $this->client->request('GET', '/api/orders', server: $this->auth() + ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
        self::assertSame('', (string) $this->client->getResponse()->getContent());

        // Apache ajoute « -gzip » à l'empreinte d'une réponse compressée
        $this->client->request('GET', '/api/orders', server: $this->auth() + ['HTTP_IF_NONE_MATCH' => substr($etag, 0, -1).'-gzip"']);
        self::assertResponseStatusCodeSame(304);

        self::createOrder($this->event, [[$this->crepe, 1]]);
        $this->client->request('GET', '/api/orders', server: $this->auth() + ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(2, $this->json()['orders']);
    }

    // --- Avancement ---

    public function testOrderAdvancesStepByStep(): void
    {
        $order = self::createOrder($this->event, [[$this->crepe, 1]]);

        foreach (['prep', 'ready', 'served'] as $status) {
            $data = $this->advance($order, $status);
            self::assertResponseStatusCodeSame(200);
            self::assertSame($status, $data['status']);
            self::assertSame($status, $this->reload($order)->getStatus()->value);
        }

        self::assertSame([], $this->list()['orders'], 'une commande remise au client quitte la cuisine');
    }

    public function testAskingTheCurrentStatusAgainChangesNothing(): void
    {
        $order = self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Prep);
        $updatedAt = $this->reload($order)->getUpdatedAt();

        $data = $this->advance($order, 'prep');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('prep', $data['status']);
        self::assertEquals($updatedAt, $this->reload($order)->getUpdatedAt());
    }

    /** @return iterable<string, array{OrderStatus, string, string}> */
    public static function refusedSteps(): iterable
    {
        yield 'sauter une étape' => [OrderStatus::New, 'ready', 'n\'est plus à cette étape'];
        yield 'revenir en arrière' => [OrderStatus::Ready, 'prep', 'n\'est plus à cette étape'];
        yield 'déjà remise' => [OrderStatus::Served, 'ready', 'déjà été remise'];
        yield 'annulée' => [OrderStatus::Cancelled, 'prep', 'annulée'];
    }

    #[DataProvider('refusedSteps')]
    public function testRefusedStepReturnsTheCurrentStatus(OrderStatus $current, string $asked, string $message): void
    {
        $order = self::createOrder($this->event, [[$this->crepe, 1]], $current);

        $data = $this->advance($order, $asked);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString($message, $data['error']);
        self::assertSame($current->value, $data['order']['status']);
        self::assertSame($current, $this->reload($order)->getStatus());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidStatuses(): iterable
    {
        yield 'annulation' => ['cancelled'];
        yield 'retour à nouvelle' => ['new'];
        yield 'inconnu' => ['done'];
        yield 'absent' => [null];
        yield 'tableau' => [['ready']];
    }

    #[DataProvider('invalidStatuses')]
    public function testInvalidStatusIsRefused(mixed $status): void
    {
        $order = self::createOrder($this->event, [[$this->crepe, 1]]);

        $this->client->jsonRequest('POST', '/api/orders/'.$order->getUuid().'/status', ['status' => $status], $this->auth());

        self::assertResponseStatusCodeSame(400);
        self::assertSame(OrderStatus::New, $this->reload($order)->getStatus());
    }

    public function testUnreadableBodyIsRefused(): void
    {
        $order = self::createOrder($this->event, [[$this->crepe, 1]]);

        $this->client->request('POST', '/api/orders/'.$order->getUuid().'/status', server: $this->auth() + ['CONTENT_TYPE' => 'application/json'], content: '{pas du json');

        self::assertResponseStatusCodeSame(400);
    }

    public function testUnknownOrderIsNotFound(): void
    {
        $this->client->jsonRequest('POST', '/api/orders/'.self::uuid().'/status', ['status' => 'prep'], $this->auth());
        self::assertResponseStatusCodeSame(404);

        $this->client->jsonRequest('POST', '/api/orders/pas-un-uuid/status', ['status' => 'prep'], $this->auth());
        self::assertResponseStatusCodeSame(404);
    }

    public function testOrderOfAnotherEventCannotBeAdvanced(): void
    {
        $other = self::createEvent('Carnaval', active: false);
        $order = self::createOrder($other, [[self::createProduct($other, 'Beignet', kitchen: true), 1]]);

        $this->advance($order, 'prep');

        self::assertResponseStatusCodeSame(404);
        self::assertSame(OrderStatus::New, $this->reload($order)->getStatus());
    }

    // --- Outils ---

    /** @return array<string, string> */
    private function auth(?string $token = null): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.($token ?? $this->token)];
    }

    /** @return array<string, mixed> */
    private function list(): array
    {
        $this->client->request('GET', '/api/orders', server: $this->auth());
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function advance(Order $order, string $status, ?string $token = null): array
    {
        $this->client->jsonRequest('POST', '/api/orders/'.$order->getUuid().'/status', ['status' => $status], $this->auth($token));

        return $this->json();
    }

    private function reload(Order $order): Order
    {
        self::entityManager()->clear();

        return self::entityManager()->find(Order::class, $order->getId());
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
