<?php

namespace App\Tests\Controller\Api;

use App\Entity\AdminUser;
use App\Entity\Device;
use App\Entity\Event;
use App\Enum\DeviceRole;
use App\EventListener\ApiGuardListener;
use App\Tests\FixturesTrait;
use App\Tests\ResetDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DeviceAuthenticationTest extends WebTestCase
{
    use FixturesTrait;
    use ResetDatabaseTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetDatabase();
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testRequestWithoutTokenIsRefused(): void
    {
        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('pas appairée', $this->error());
    }

    public function testValidTokenGivesAccess(): void
    {
        [, $token] = self::createPairedDevice(self::createEvent('Loto de Noël'), 'Écran cuisine', DeviceRole::Kitchen);

        $this->me($token);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Écran cuisine', $data['name']);
        self::assertSame('cuisine', $data['role']);
        self::assertSame('Loto de Noël', $data['event']['name']);
    }

    public function testEachRoleGetsItsSecurityRole(): void
    {
        $event = self::createEvent();
        foreach ([[DeviceRole::Till, 'ROLE_TILL'], [DeviceRole::Kitchen, 'ROLE_KITCHEN'], [DeviceRole::Display, 'ROLE_DISPLAY']] as [$role, $expected]) {
            [$device] = self::createPairedDevice($event, 'Tablette '.$role->value, $role);
            self::assertSame(['ROLE_DEVICE', $expected], $device->getRoles());
        }
    }

    public function testUnknownOrMalformedTokenIsRefused(): void
    {
        self::createPairedDevice(self::createEvent());

        $this->me(str_repeat('a', 43));
        self::assertResponseStatusCodeSame(401);

        $this->me('mal formé');
        self::assertResponseStatusCodeSame(401);
    }

    public function testTokenInUrlIsIgnored(): void
    {
        [, $token] = self::createPairedDevice(self::createEvent());

        $this->client->request('GET', '/api/me?token='.$token.'&access_token='.$token);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminSessionDoesNotOpenTheApi(): void
    {
        $admin = (new AdminUser('camille'))->setPassword('inutile-ici');
        self::entityManager()->persist($admin);
        self::entityManager()->flush();
        $this->client->loginUser($admin);

        // Première requête : la session administrateur fonctionne bien
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        // Le cookie de session est envoyé, mais l'API ne l'utilise pas
        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRevokedDeviceIsRefused(): void
    {
        [$device, $token] = self::createPairedDevice(self::createEvent());
        $this->updateDevice($device, static fn (Device $d) => $d->setRevokedAt(new \DateTimeImmutable()));

        $this->me($token);

        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('retirée', $this->error());
    }

    public function testExpiredTokenIsRefused(): void
    {
        [$device, $token] = self::createPairedDevice(self::createEvent());
        $this->updateDevice($device, static fn (Device $d) => $d->setExpiresAt(new \DateTimeImmutable('-1 second')));

        $this->me($token);

        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('expiré', $this->error());
    }

    public function testTokenOfInactiveEventIsRefused(): void
    {
        $event = self::createEvent();
        [, $token] = self::createPairedDevice($event);
        $event = self::entityManager()->find(Event::class, $event->getId());
        $event->setActive(false);
        self::entityManager()->flush();

        $this->me($token);

        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('plus l\'événement actif', $this->error());
    }

    public function testBodyLargerThan64KbIsRefused(): void
    {
        [, $token] = self::createPairedDevice(self::createEvent());

        $this->client->request('POST', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
        ], content: str_repeat('x', ApiGuardListener::MAX_BODY_BYTES + 1));

        self::assertResponseStatusCodeSame(413);
    }

    public function testEachDeviceIsLimitedTo120RequestsPerMinute(): void
    {
        $event = self::createEvent();
        [, $token] = self::createPairedDevice($event, 'Caisse 1');
        [, $otherToken] = self::createPairedDevice($event, 'Caisse 2');

        for ($i = 0; $i < 120; ++$i) {
            $this->me($token);
            self::assertResponseIsSuccessful();
        }

        $this->me($token);
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');

        // Les autres tablettes ne sont pas pénalisées
        $this->me($otherToken);
        self::assertResponseIsSuccessful();
    }

    public function testLastSeenIsRecorded(): void
    {
        [$device, $token] = self::createPairedDevice(self::createEvent());
        self::assertNull($device->getLastSeenAt());

        $this->me($token);

        self::entityManager()->clear();
        $device = self::entityManager()->find(Device::class, $device->getId());
        self::assertNotNull($device->getLastSeenAt());
    }

    private function me(string $token): void
    {
        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    private function error(): string
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? '';
    }

    /** @param callable(Device): mixed $change */
    private function updateDevice(Device $device, callable $change): void
    {
        $device = self::entityManager()->find(Device::class, $device->getId());
        $change($device);
        self::entityManager()->flush();
    }
}
