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
use App\Tests\RevisionsTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TillTrackingApiTest extends WebTestCase
{
    use FixturesTrait;
    use OrderFixturesTrait;
    use ResetDatabaseTrait;
    use RevisionsTrait;

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
        // Première caisse appairée : lettre A
        [, $this->token] = self::createPairedDevice($this->event);
    }

    /** @return iterable<string, array{DeviceRole}> */
    public static function otherRoles(): iterable
    {
        yield 'cuisine' => [DeviceRole::Kitchen];
        yield 'affichage' => [DeviceRole::Display];
    }

    #[DataProvider('otherRoles')]
    public function testOnlyTillsCanFollowAndServeOrders(DeviceRole $role): void
    {
        $order = self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Ready);
        [, $token] = self::createPairedDevice($this->event, 'Autre tablette', $role);

        $this->client->request('GET', '/api/till/orders', server: $this->auth($token));
        self::assertResponseStatusCodeSame(403);

        $this->serve($order, $token);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(OrderStatus::Ready, $this->reload($order)->getStatus());
    }

    public function testListsOpenOrdersOfAllTillsWithoutAmountsOrItems(): void
    {
        $mine = self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Prep, createdAt: '-6 minutes');
        $other = self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Ready, letter: 'B', createdAt: '-4 minutes');
        self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Served);
        self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Cancelled);

        $data = $this->list();

        self::assertSame([$mine->getDisplayNumber(), $other->getDisplayNumber()], array_column($data['orders'], 'displayNumber'));
        self::assertSame(['prep', 'ready'], array_column($data['orders'], 'status'));
        self::assertSame([true, false], array_column($data['orders'], 'mine'), 'commandes de cette caisse repérées');
        // Ni montant ni article ; le numéro de table sert au filtre « À table » (service à table)
        self::assertSame(['uuid', 'displayNumber', 'status', 'createdAt', 'updatedAt', 'mine', 'tableNumber'], array_keys($data['orders'][0]));
        self::assertArrayNotHasKey('serverTime', $data, 'heure du serveur : en-tête Date');
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

        $this->client->request('GET', '/api/till/orders', server: $this->auth() + ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertResponseStatusCodeSame(304);
    }

    public function testReadyOrderIsServedAtTheCounter(): void
    {
        $order = self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Ready, letter: 'B');
        $before = self::revisionsOf($this->event->getId());

        $data = $this->serve($order);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('served', $data['status']);
        self::assertRevisionsMoved($before, 1, 0, $this->event->getId());
        self::assertSame(OrderStatus::Served, $this->reload($order)->getStatus());
        self::assertSame([], $this->list()['orders']);
    }

    public function testServingTwiceChangesNothing(): void
    {
        $order = self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Served);

        $this->serve($order);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(OrderStatus::Served, $this->reload($order)->getStatus());
    }

    /** @return iterable<string, array{OrderStatus, string}> */
    public static function notServable(): iterable
    {
        yield 'nouvelle' => [OrderStatus::New, 'pas encore prête'];
        yield 'en préparation' => [OrderStatus::Prep, 'pas encore prête'];
        yield 'annulée' => [OrderStatus::Cancelled, 'annulée'];
    }

    #[DataProvider('notServable')]
    public function testOnlyReadyOrdersCanBeServed(OrderStatus $status, string $message): void
    {
        $order = self::createOrder($this->event, [[$this->crepe, 1]], $status);

        $data = $this->serve($order);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString($message, $data['error']);
        self::assertSame($status->value, $data['order']['status']);
        self::assertSame($status, $this->reload($order)->getStatus());
    }

    public function testOrderOfAnotherEventCannotBeServed(): void
    {
        $other = self::createEvent('Carnaval', active: false);
        $order = self::createOrder($other, [[self::createProduct($other, 'Beignet', kitchen: true), 1]], OrderStatus::Ready);

        $this->serve($order);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(OrderStatus::Ready, $this->reload($order)->getStatus());
    }

    /** @return array<string, string> */
    private function auth(?string $token = null): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.($token ?? $this->token)];
    }

    /** @return array<string, mixed> */
    private function list(): array
    {
        $this->client->request('GET', '/api/till/orders', server: $this->auth());
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array<string, mixed> */
    private function serve(Order $order, ?string $token = null): array
    {
        $this->client->request('POST', '/api/till/orders/'.$order->getUuid().'/served', server: $this->auth($token));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function reload(Order $order): Order
    {
        self::entityManager()->clear();

        return self::entityManager()->find(Order::class, $order->getId());
    }
}
