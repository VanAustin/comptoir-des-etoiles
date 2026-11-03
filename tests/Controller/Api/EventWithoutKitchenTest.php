<?php

namespace App\Tests\Controller\Api;

use App\Entity\Event;
use App\Enum\DeviceRole;
use App\Enum\OrderStatus;
use App\Service\DevicePairing;
use App\Service\PairingException;
use App\Tests\FixturesTrait;
use App\Tests\OrderFixturesTrait;
use App\Tests\ResetDatabaseTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Événement sans cuisine (stand de Noël) : seules les caisses fonctionnent,
 * les tablettes cuisine et affichage sont refusées par l'API.
 */
class EventWithoutKitchenTest extends WebTestCase
{
    use FixturesTrait;
    use OrderFixturesTrait;
    use ResetDatabaseTrait;

    private const MESSAGE = 'Cet événement est sans cuisine';

    private KernelBrowser $client;
    private Event $event;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetDatabase();
        self::getContainer()->get('cache.rate_limiter')->clear();

        $this->event = self::createEvent('Stand de Noël');
    }

    /** @return iterable<string, array{DeviceRole, string, string}> */
    public static function kitchenRoutes(): iterable
    {
        yield 'cuisine : commandes en cours' => [DeviceRole::Kitchen, 'GET', '/api/orders'];
        yield 'cuisine : avancer une commande' => [DeviceRole::Kitchen, 'POST', '/api/orders/2b6a1f0e-3c4d-4e5f-8a9b-0c1d2e3f4a5b/status'];
        yield 'affichage : numéros' => [DeviceRole::Display, 'GET', '/api/board'];
    }

    /** Tablette appairée avant que l'événement passe sans cuisine. */
    #[DataProvider('kitchenRoutes')]
    public function testKitchenAndDisplayDevicesAreRefused(DeviceRole $role, string $method, string $url): void
    {
        [, $token] = self::createPairedDevice($this->event, 'Tablette', $role);

        // Avec cuisine, la route lui est ouverte (la requête peut être refusée pour une autre raison)
        $this->client->request($method, $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertNotSame(403, $this->client->getResponse()->getStatusCode());

        $this->setKitchen(false);
        $this->client->request($method, $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString(self::MESSAGE, $this->json()['error'] ?? '');
    }

    public function testRefusedDeviceCanStillTellWhatItIs(): void
    {
        [, $token] = self::createPairedDevice($this->event, 'Cuisine 1', DeviceRole::Kitchen);
        $this->setKitchen(false);

        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        self::assertSame('cuisine', $this->json()['role']);
    }

    public function testTillStillWorksAndOrdersAreServedAtOnce(): void
    {
        $this->setKitchen(false);
        $hotdog = self::createProduct($this->event, 'Hot-dog', kitchen: true);
        [, $token] = self::createPairedDevice($this->event);

        $this->client->jsonRequest('POST', '/api/orders', [
            'uuid' => self::uuid(),
            'number' => 1,
            'paymentMethod' => 'card',
            'lines' => [['productId' => $hotdog->getId(), 'quantity' => 1]],
            'note' => 'Sans moutarde',
        ], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(OrderStatus::Served->value, $this->json()['status']);
        self::assertNull($this->json()['note']);
    }

    #[DataProvider('kitchenRoles')]
    public function testKitchenAndDisplayDevicesCannotBePaired(DeviceRole $role): void
    {
        [, $code] = self::createDeviceWithCode($this->event, 'Tablette', $role);
        $this->setKitchen(false);

        $this->client->jsonRequest('POST', '/api/pair', ['code' => $code]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('seules les tablettes caisse peuvent être appairées', $this->json()['error'] ?? '');
    }

    /** @return iterable<string, array{DeviceRole}> */
    public static function kitchenRoles(): iterable
    {
        yield 'cuisine' => [DeviceRole::Kitchen];
        yield 'affichage' => [DeviceRole::Display];
    }

    public function testTillCanBePaired(): void
    {
        $this->setKitchen(false);
        [, $code] = self::createDeviceWithCode($this->event);

        [$device] = self::getContainer()->get(DevicePairing::class)->pair($code);

        self::assertSame('A', $device->getTillLetter());
    }

    public function testPairingServiceRefusesKitchenDevice(): void
    {
        $this->setKitchen(false);
        [, $code] = self::createDeviceWithCode($this->event, 'Cuisine 1', DeviceRole::Kitchen);

        $this->expectException(PairingException::class);
        self::getContainer()->get(DevicePairing::class)->pair($code);
    }

    private function setKitchen(bool $withKitchen): void
    {
        self::entityManager()->find(Event::class, $this->event->getId())->setWithKitchen($withKitchen);
        self::entityManager()->flush();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
