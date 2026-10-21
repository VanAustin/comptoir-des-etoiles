<?php

namespace App\Tests\Controller\Api;

use App\Entity\AuditLog;
use App\Entity\Device;
use App\Enum\DeviceRole;
use App\Tests\FixturesTrait;
use App\Tests\ResetDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PairingControllerTest extends WebTestCase
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

    public function testPairingReturnsTokenAndStoresOnlyItsHash(): void
    {
        $event = self::createEvent(date: '2026-12-18');
        [$device, $code] = self::createDeviceWithCode($event);

        // Saisie souple : minuscules, tiret et espaces acceptés
        $response = $this->pair(' '.strtolower(substr($code, 0, 4).'-'.substr($code, 4)).' ');

        self::assertResponseStatusCodeSame(201);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $response['token']);
        self::assertSame('Caisse buvette', $response['device']['name']);
        self::assertSame('caisse', $response['device']['role']);
        self::assertSame('A', $response['device']['tillLetter']);
        self::assertSame(
            (new \DateTimeImmutable('2026-12-19 06:00', new \DateTimeZone('Europe/Paris')))->getTimestamp(),
            (new \DateTimeImmutable($response['expiresAt']))->getTimestamp(),
        );

        $device = $this->reload($device);
        self::assertSame(hash('sha256', $response['token']), $device->getTokenHash());
        self::assertNull($device->getPairingCodeHash());

        $log = self::entityManager()->getRepository(AuditLog::class)->findOneBy(['action' => 'device.pair']);
        self::assertSame($device->getUserIdentifier(), $log?->getActor());
    }

    public function testCodeCanBeUsedOnlyOnce(): void
    {
        [, $code] = self::createDeviceWithCode(self::createEvent());

        $this->pair($code);
        self::assertResponseStatusCodeSame(201);

        $response = $this->pair($code);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('pas valide', $response['error']);
    }

    public function testWrongOrMalformedCodeIsRefused(): void
    {
        self::createDeviceWithCode(self::createEvent());

        $this->pair('ABCD-EFGH');
        self::assertResponseStatusCodeSame(422);

        $this->pair('trop-court');
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', '/api/pair', server: ['CONTENT_TYPE' => 'application/json'], content: 'pas du json');
        self::assertResponseStatusCodeSame(400);
    }

    public function testExpiredCodeIsRefused(): void
    {
        [$device, $code] = self::createDeviceWithCode(self::createEvent());
        $device->setPairingCodeExpiresAt(new \DateTimeImmutable('-1 second'));
        self::entityManager()->flush();

        $response = $this->pair($code);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('expiré', $response['error']);
    }

    public function testRevokedDeviceCannotPair(): void
    {
        [$device, $code] = self::createDeviceWithCode(self::createEvent());
        $device->setRevokedAt(new \DateTimeImmutable());
        self::entityManager()->flush();

        $this->pair($code);

        self::assertResponseStatusCodeSame(422);
    }

    public function testInactiveEventIsRefused(): void
    {
        [, $code] = self::createDeviceWithCode(self::createEvent(active: false));

        $response = $this->pair($code);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('plus l\'événement actif', $response['error']);
    }

    public function testFinishedEventIsRefused(): void
    {
        [, $code] = self::createDeviceWithCode(self::createEvent(date: '-2 days'));

        $response = $this->pair($code);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('terminé', $response['error']);
    }

    public function testTillLettersAreUniqueAndNeverReused(): void
    {
        $event = self::createEvent();

        // A puis B pour deux caisses ; aucune lettre pour la cuisine
        [, $codeA] = self::createDeviceWithCode($event, 'Caisse 1');
        [, $codeKitchen] = self::createDeviceWithCode($event, 'Cuisine', DeviceRole::Kitchen);
        [, $codeB] = self::createDeviceWithCode($event, 'Caisse 2');
        self::assertSame('A', $this->pair($codeA)['device']['tillLetter']);
        self::assertNull($this->pair($codeKitchen)['device']['tillLetter']);
        self::assertSame('B', $this->pair($codeB)['device']['tillLetter']);

        // La lettre d'une caisse révoquée n'est pas réattribuée
        $caisse2 = self::entityManager()->getRepository(Device::class)->findOneBy(['name' => 'Caisse 2']);
        $caisse2->setRevokedAt(new \DateTimeImmutable());
        self::entityManager()->flush();
        [, $codeC] = self::createDeviceWithCode($event, 'Caisse 3');
        self::assertSame('C', $this->pair($codeC)['device']['tillLetter']);
    }

    public function testTillLettersSkipIAndO(): void
    {
        $event = self::createEvent();
        foreach (str_split('ABCDEFGH') as $letter) {
            $device = new Device($event, 'Caisse '.$letter, DeviceRole::Till);
            $device->setTillLetter($letter);
            self::entityManager()->persist($device);
        }
        self::entityManager()->flush();

        [, $code] = self::createDeviceWithCode($event, 'Caisse suivante');

        self::assertSame('J', $this->pair($code)['device']['tillLetter']);
    }

    public function testPairingIsLimitedTo20AttemptsPerIp(): void
    {
        [, $code] = self::createDeviceWithCode(self::createEvent());

        for ($i = 0; $i < 20; ++$i) {
            $this->pair('ABCD-EFGH');
            self::assertResponseStatusCodeSame(422);
        }

        // Même le bon code est refusé à la 21e tentative
        $response = $this->pair($code);
        self::assertResponseStatusCodeSame(429);
        self::assertStringContainsString('Trop de tentatives', $response['error']);
    }

    /** @return array<string, mixed> */
    private function pair(string $code): array
    {
        $this->client->jsonRequest('POST', '/api/pair', ['code' => $code]);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function reload(Device $device): Device
    {
        self::entityManager()->clear();

        return self::entityManager()->getRepository(Device::class)->find($device->getId());
    }
}
