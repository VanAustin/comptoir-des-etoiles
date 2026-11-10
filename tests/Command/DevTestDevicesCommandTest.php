<?php

namespace App\Tests\Command;

use App\Command\DevTestDevicesCommand;
use App\Entity\AuditLog;
use App\Entity\Device;
use App\Enum\DeviceRole;
use App\Repository\EventRepository;
use App\Service\DevicePairing;
use App\Tests\FixturesTrait;
use App\Tests\ResetDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class DevTestDevicesCommandTest extends WebTestCase
{
    use FixturesTrait;
    use ResetDatabaseTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::resetDatabase();
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testRefusedOutsideDevelopment(): void
    {
        self::createEvent();

        // Environnement de test, comme en production : la commande refuse
        $tester = new CommandTester((new Application(self::$kernel))->find('app:dev:test-devices'));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('réservée au développement', $tester->getDisplay());
        self::assertSame(0, self::entityManager()->getRepository(Device::class)->count([]));
    }

    public function testCreatesThreeDevicesWithSingleUseCodesInTheAddressFragment(): void
    {
        self::createEvent('Loto de Noël');

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $devices = self::entityManager()->getRepository(Device::class)->findBy([], ['id' => 'ASC']);
        self::assertSame([DeviceRole::Till, DeviceRole::Kitchen, DeviceRole::Display], array_map(static fn (Device $d): DeviceRole => $d->getRole(), $devices));
        foreach ($devices as $device) {
            self::assertFalse($device->isPaired(), 'pas de jeton créé par la commande');
            self::assertNull($device->getTokenHash());
            self::assertNotNull($device->getPairingCodeHash());
        }

        // Une adresse par tablette, code après le « # » (jamais envoyé au serveur par le navigateur)
        preg_match_all('~(http://\S+/appairer)#code=([A-Z0-9]{4}-[A-Z0-9]{4})~', $tester->getDisplay(), $links, \PREG_SET_ORDER);
        self::assertCount(3, $links, $tester->getDisplay());
        self::assertStringNotContainsString('token', strtolower($tester->getDisplay()));
        self::assertStringNotContainsString('jeton :', $tester->getDisplay());

        // Chaque code s'échange une fois, comme un code de l'administration
        foreach ($links as [, , $code]) {
            $this->client->jsonRequest('POST', '/api/pair', ['code' => $code]);
            self::assertResponseStatusCodeSame(201);
        }
        $this->client->jsonRequest('POST', '/api/pair', ['code' => $links[0][2]]);
        self::assertResponseStatusCodeSame(422);

        // Auteur explicite dans le journal d'audit, pas « anonyme »
        self::assertSame(3, self::entityManager()->getRepository(AuditLog::class)->count(['action' => 'device.create', 'actor' => DevTestDevicesCommand::AUDIT_ACTOR]));
    }

    public function testShowsTheActiveEventAndCreatesNothingWithoutConfirmation(): void
    {
        self::createEvent('Loto de Noël');

        $tester = $this->runCommand(inputs: ['non']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Événement actif : « Loto de Noël »', $tester->getDisplay());
        self::assertStringContainsString('Créer une caisse, une cuisine et un écran Arrivées pour cet événement ? Tapez « oui » pour confirmer', $tester->getDisplay());
        self::assertStringContainsString('Aucune tablette créée', $tester->getDisplay());
        self::assertSame(0, self::entityManager()->getRepository(Device::class)->count([]));
    }

    public function testConfirmationCreatesTheDevices(): void
    {
        self::createEvent('Loto de Noël');

        $tester = $this->runCommand(inputs: ['oui']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(3, self::entityManager()->getRepository(Device::class)->count([]));
    }

    public function testRefusedWithoutTerminalUnlessYes(): void
    {
        self::createEvent('Loto de Noël');

        $tester = $this->runCommand(yes: false, interactive: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--yes', $tester->getDisplay());
        self::assertSame(0, self::entityManager()->getRepository(Device::class)->count([]));
    }

    public function testEventWithoutKitchenGetsOnlyATill(): void
    {
        $event = self::createEvent();
        $event->setWithKitchen(false);
        self::entityManager()->flush();

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $devices = self::entityManager()->getRepository(Device::class)->findAll();
        self::assertCount(1, $devices);
        self::assertSame(DeviceRole::Till, $devices[0]->getRole());
        self::assertStringContainsString('sans cuisine', $tester->getDisplay());
    }

    public function testRefusedWithoutActiveEvent(): void
    {
        self::createEvent(active: false);

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Aucun événement actif', $tester->getDisplay());
        self::assertSame(0, self::entityManager()->getRepository(Device::class)->count([]));
    }

    public function testRefusedWhenActiveEventIsFinished(): void
    {
        self::createEvent(date: '-3 days');

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('est terminé', $tester->getDisplay());
        self::assertSame(0, self::entityManager()->getRepository(Device::class)->count([]));
    }

    /** @param list<string> $inputs réponses tapées ; sans réponse, l'option --yes */
    private function runCommand(array $inputs = [], ?bool $yes = null, bool $interactive = true): CommandTester
    {
        $container = self::getContainer();
        $command = new DevTestDevicesCommand(
            self::entityManager(),
            $container->get(EventRepository::class),
            $container->get(DevicePairing::class),
            $container->get(UrlGeneratorInterface::class),
            'dev',
        );
        $tester = new CommandTester($command);
        $tester->setInputs($inputs);
        $tester->execute(($yes ?? [] === $inputs) ? ['--yes' => true] : [], ['interactive' => $interactive]);

        return $tester;
    }
}
