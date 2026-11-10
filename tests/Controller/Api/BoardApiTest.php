<?php

namespace App\Tests\Controller\Api;

use App\Controller\Api\BoardController;
use App\Entity\Event;
use App\Entity\Product;
use App\Enum\DeviceRole;
use App\Enum\OrderStatus;
use App\Tests\FixturesTrait;
use App\Tests\OrderFixturesTrait;
use App\Tests\ResetDatabaseTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BoardApiTest extends WebTestCase
{
    use FixturesTrait;
    use OrderFixturesTrait;
    use ResetDatabaseTrait;

    private KernelBrowser $client;
    private Event $event;
    private string $token;
    private Product $crepe;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetDatabase();
        self::getContainer()->get('cache.rate_limiter')->clear();

        $this->event = self::createEvent();
        $this->crepe = self::createProduct($this->event, 'Crêpe sucre', kitchen: true);
        [, $this->token] = self::createPairedDevice($this->event, 'Écran Arrivées', DeviceRole::Display);
    }

    /** @return iterable<string, array{DeviceRole}> */
    public static function otherRoles(): iterable
    {
        yield 'caisse' => [DeviceRole::Till];
        yield 'cuisine' => [DeviceRole::Kitchen];
    }

    #[DataProvider('otherRoles')]
    public function testOnlyDisplaysCanReadTheBoard(DeviceRole $role): void
    {
        [, $token] = self::createPairedDevice($this->event, 'Autre tablette', $role);

        $this->client->request('GET', '/api/board', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDisplayCannotReadOrAdvanceOrders(): void
    {
        $order = self::createOrder($this->event, [[$this->crepe, 1]]);

        $this->client->request('GET', '/api/orders', server: $this->auth());
        self::assertResponseStatusCodeSame(403);

        $this->client->jsonRequest('POST', '/api/orders/'.$order->getUuid().'/status', ['status' => 'prep'], $this->auth());
        self::assertResponseStatusCodeSame(403);
    }

    public function testBoardShowsOnlyNumbersAndStates(): void
    {
        $new = self::createOrder($this->event, [[$this->crepe, 1]], createdAt: '-3 minutes');
        $prep = self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Prep, letter: 'B', createdAt: '-8 minutes');
        $ready = self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Ready);
        self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Served);
        self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Cancelled);

        $data = $this->board();

        self::assertSame([
            'ready' => [$ready->getDisplayNumber()],
            'preparing' => [$prep->getDisplayNumber(), $new->getDisplayNumber()],
        ], $data, 'ni montant, ni article, ni identifiant : seulement les numéros');
    }

    public function testOnlyTheMostRecentReadyOrdersAreShown(): void
    {
        $numbers = [];
        for ($i = 0; $i < BoardController::READY_SHOWN + 2; ++$i) {
            $numbers[] = self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Ready)->getDisplayNumber();
        }

        $ready = $this->board()['ready'];

        self::assertSame(\array_slice(array_reverse($numbers), 0, BoardController::READY_SHOWN), $ready);
    }

    public function testOnlyTheDeviceEventIsShown(): void
    {
        $other = self::createEvent('Carnaval', active: false);
        self::createOrder($other, [[self::createProduct($other, 'Beignet', kitchen: true), 1]], OrderStatus::Ready);

        self::assertSame(['ready' => [], 'preparing' => []], $this->board());
    }

    public function testUnchangedBoardAnswersNotModified(): void
    {
        $order = self::createOrder($this->event, [[$this->crepe, 1]]);
        $this->board();
        $etag = (string) $this->client->getResponse()->getEtag();

        $this->client->request('GET', '/api/board', server: $this->auth() + ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);

        // Tout changement des commandes renvoie l'écran complet, sans relire les commandes avant
        self::entityManager()->find($order::class, $order->getId())->setStatus(OrderStatus::Ready);
        self::revisions()->flushOrders($this->event);
        $this->client->request('GET', '/api/board', server: $this->auth() + ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$order->getDisplayNumber()], json_decode((string) $this->client->getResponse()->getContent(), true)['ready']);
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token];
    }

    /** @return array<string, mixed> */
    private function board(): array
    {
        $this->client->request('GET', '/api/board', server: $this->auth());
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
