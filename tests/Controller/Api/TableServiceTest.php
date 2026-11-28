<?php

namespace App\Tests\Controller\Api;

use App\Entity\AuditLog;
use App\Entity\Event;
use App\Entity\Order;
use App\Entity\Product;
use App\Enum\DeviceRole;
use App\Enum\OrderStatus;
use App\Service\Revisions;
use App\Tests\FixturesTrait;
use App\Tests\OrderFixturesTrait;
use App\Tests\ResetDatabaseTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Service à table (Loto) : le client paie à la caisse et donne son numéro de table,
 * un bénévole lui apporte son plat. Les boissons sont toujours remises au comptoir.
 */
class TableServiceTest extends WebTestCase
{
    use FixturesTrait;
    use OrderFixturesTrait;
    use ResetDatabaseTrait;

    private KernelBrowser $client;
    private Event $event;
    private Product $hotdog;
    private Product $soda;
    private string $till;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetDatabase();
        self::getContainer()->get('cache.rate_limiter')->clear();

        $this->event = self::createEvent('Loto de Noël');
        $this->setTableService(true);
        $this->hotdog = self::createProduct($this->event, 'Hot-dog', kitchen: true);
        $this->soda = self::createProduct($this->event, 'Soda');
        [, $this->till] = self::createPairedDevice($this->event);
    }

    public function testMenuOffersTableServiceOnlyWithKitchen(): void
    {
        $this->request('GET', '/api/menu', $this->till);
        self::assertTrue($this->json()['event']['tableService']);

        $this->setTableService(false);
        $this->request('GET', '/api/menu', $this->till);
        self::assertFalse($this->json()['event']['tableService']);

        // Sans cuisine, tout est remis au comptoir : jamais de service à table
        $this->setTableService(true);
        self::entityManager()->find(Event::class, $this->event->getId())->setWithKitchen(false);
        self::entityManager()->flush();
        $this->request('GET', '/api/menu', $this->till);
        self::assertFalse($this->json()['event']['tableService']);
    }

    public function testOrderWithAKitchenItemGoesToKitchenWithItsTable(): void
    {
        $this->placeOrder(['tableNumber' => 304, 'lines' => [['productId' => $this->hotdog->getId(), 'quantity' => 1], ['productId' => $this->soda->getId(), 'quantity' => 2]]]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(304, $this->json()['tableNumber']);
        self::assertSame(OrderStatus::New->value, $this->json()['status']);
        self::assertSame(304, $this->storedOrder()->getTableNumber());
    }

    public function testDrinksOnlyAreACounterOrderWithoutTable(): void
    {
        $this->placeOrder(['tableNumber' => 304, 'lines' => [['productId' => $this->soda->getId(), 'quantity' => 2]]]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(OrderStatus::Served->value, $this->json()['status']);
        self::assertNull($this->json()['tableNumber']);
        self::assertNull($this->storedOrder()->getTableNumber());
    }

    public function testWithoutTableNumberItIsACounterOrder(): void
    {
        $this->placeOrder(['lines' => [['productId' => $this->hotdog->getId(), 'quantity' => 1]]]);

        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->json()['tableNumber']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidTables(): iterable
    {
        yield 'zéro' => [0];
        yield 'mille' => [1000];
        yield 'négatif' => [-4];
        yield 'texte' => ['304'];
        yield 'décimal' => [30.5];
        yield 'booléen' => [true];
        yield 'liste' => [[304]];
    }

    #[DataProvider('invalidTables')]
    public function testInvalidTableNumberIsRefused(mixed $table): void
    {
        $this->placeOrder(['tableNumber' => $table, 'lines' => [['productId' => $this->hotdog->getId(), 'quantity' => 1]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Numéro de table invalide', $this->json()['error']);
        self::assertSame(0, self::entityManager()->getRepository(Order::class)->count([]));
    }

    public function testOfflineOrderWithInvalidTableIsKeptWithoutTableAndTraced(): void
    {
        // Déjà payée : jamais perdue pour un numéro de table illisible
        $this->placeOrder(['offline' => true, 'tableNumber' => 1000, 'lines' => [['productId' => $this->hotdog->getId(), 'quantity' => 1]]]);

        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->json()['tableNumber']);
        $log = self::entityManager()->getRepository(AuditLog::class)->findOneBy(['action' => 'order.table_rejected']);
        self::assertNotNull($log, 'table invalide écartée : tracée');
        self::assertStringStartsWith('tablette:', $log->getActor());
        self::assertSame('order:'.$this->json()['uuid'].' '.$this->json()['displayNumber'], $log->getSubject());
        self::assertSame(['tableNumber' => '1000'], $log->getBefore());
    }

    public function testRejectedValueIsTracedShortened(): void
    {
        $this->placeOrder(['offline' => true, 'tableNumber' => str_repeat('9', 100), 'lines' => [['productId' => $this->hotdog->getId(), 'quantity' => 1]]]);

        $log = self::entityManager()->getRepository(AuditLog::class)->findOneBy(['action' => 'order.table_rejected']);
        self::assertSame('"'.str_repeat('9', 39).'…', $log?->getBefore()['tableNumber']);
    }

    public function testValidOrAbsentTableIsNotTraced(): void
    {
        $this->placeOrder(['offline' => true, 'tableNumber' => 304, 'lines' => [['productId' => $this->hotdog->getId(), 'quantity' => 1]]]);
        $this->placeOrder(['offline' => true, 'lines' => [['productId' => $this->hotdog->getId(), 'quantity' => 1]]]);

        self::assertSame(0, self::entityManager()->getRepository(AuditLog::class)->count(['action' => 'order.table_rejected']));
    }

    public function testTableIsKeptEvenAfterTableServiceIsSwitchedOff(): void
    {
        // Encaissée hors ligne avant que le réglage soit décoché
        $this->setTableService(false);
        $this->placeOrder(['offline' => true, 'tableNumber' => 101, 'lines' => [['productId' => $this->hotdog->getId(), 'quantity' => 1]]]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(101, $this->json()['tableNumber']);
    }

    public function testKitchenSeesTheTable(): void
    {
        [, $kitchen] = self::createPairedDevice($this->event, 'Cuisine', DeviceRole::Kitchen);
        $this->placeOrder(['tableNumber' => 304, 'lines' => [['productId' => $this->hotdog->getId(), 'quantity' => 1], ['productId' => $this->soda->getId(), 'quantity' => 1]]]);

        $this->request('GET', '/api/orders', $kitchen);

        $order = $this->json()['orders'][0];
        self::assertSame(304, $order['tableNumber']);
        self::assertSame(1, $order['zone'], 'rangée 3, 3 rangées par zone');
        self::assertSame([['label' => 'Soda', 'quantity' => 1]], $order['counterLines']);
    }

    public function testKitchenSeesTheDeliveryZoneOfTheEvent(): void
    {
        [, $kitchen] = self::createPairedDevice($this->event, 'Cuisine', DeviceRole::Kitchen);
        foreach ([101, 404, 808, 12] as $table) {
            $this->placeOrder(['tableNumber' => $table, 'lines' => [['productId' => $this->hotdog->getId(), 'quantity' => 1]]]);
        }
        $this->placeOrder(['lines' => [['productId' => $this->hotdog->getId(), 'quantity' => 1]]]);

        $this->request('GET', '/api/orders', $kitchen);
        self::assertSame([101 => 1, 404 => 2, 808 => 3, 12 => null, '' => null], $this->zones());

        // Deux rangées par zone : les tickets suivent le réglage
        self::entityManager()->find(Event::class, $this->event->getId())->setRowsPerZone(2);
        self::getContainer()->get(Revisions::class)->flushMenu($this->event);
        $this->request('GET', '/api/orders', $kitchen);
        self::assertSame([101 => 1, 404 => 2, 808 => 4, 12 => null, '' => null], $this->zones());
    }

    /** @return array<int|string, int|null> zone par numéro de table (« » : au comptoir) */
    private function zones(): array
    {
        $zones = [];
        foreach ($this->json()['orders'] as $order) {
            $zones[$order['tableNumber'] ?? ''] = $order['zone'];
        }

        return $zones;
    }

    public function testArrivalsScreenNeverShowsTableOrders(): void
    {
        [, $display] = self::createPairedDevice($this->event, 'Arrivées', DeviceRole::Display);
        $counter = self::createOrder($this->event, [[$this->hotdog, 1]], OrderStatus::Ready);
        $preparing = self::createOrder($this->event, [[$this->hotdog, 1]], OrderStatus::Prep);
        $ready = self::createOrder($this->event, [[$this->hotdog, 1]], OrderStatus::Ready);
        $this->setTable($preparing, 101);
        $this->setTable($ready, 102);

        $this->request('GET', '/api/board', $display);

        self::assertSame(['ready' => [$counter->getDisplayNumber()], 'preparing' => []], $this->json());
    }

    public function testTillTrackingShowsTheTable(): void
    {
        $this->placeOrder(['tableNumber' => 808, 'lines' => [['productId' => $this->hotdog->getId(), 'quantity' => 1]]]);

        $this->request('GET', '/api/till/orders', $this->till);

        self::assertSame(808, $this->json()['orders'][0]['tableNumber']);
    }

    public function testATableOrderChangesWhatTheKitchenReceives(): void
    {
        [, $kitchen] = self::createPairedDevice($this->event, 'Cuisine', DeviceRole::Kitchen);
        $this->request('GET', '/api/orders', $kitchen);
        $etag = $this->client->getResponse()->headers->get('ETag');

        $this->placeOrder(['tableNumber' => 304, 'lines' => [['productId' => $this->hotdog->getId(), 'quantity' => 1]]]);
        $this->client->request('GET', '/api/orders', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$kitchen, 'HTTP_IF_NONE_MATCH' => $etag]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(304, $this->json()['orders'][0]['tableNumber']);
    }

    /** @param array<string, mixed> $body */
    private function placeOrder(array $body): void
    {
        static $number = 0;
        $this->client->jsonRequest('POST', '/api/orders', $body + [
            'uuid' => self::uuid(),
            'number' => ++$number,
            'paymentMethod' => 'card',
        ], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->till]);
    }

    private function request(string $method, string $url, string $token): void
    {
        $this->client->request($method, $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    private function setTableService(bool $enabled): void
    {
        self::entityManager()->find(Event::class, $this->event->getId())->setTableService($enabled);
        self::revisions()->flushMenu($this->event);
    }

    private function setTable(Order $order, int $table): void
    {
        self::entityManager()->find(Order::class, $order->getId())->setTableNumber($table);
        self::revisions()->flushOrders($this->event);
    }

    private function storedOrder(): Order
    {
        self::entityManager()->clear();

        return self::entityManager()->getRepository(Order::class)->findOneBy([]);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
