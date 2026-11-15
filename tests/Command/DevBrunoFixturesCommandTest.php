<?php

namespace App\Tests\Command;

use App\Command\DevBrunoFixturesCommand;
use App\Entity\Device;
use App\Entity\Event;
use App\Entity\Product;
use App\Enum\DeviceRole;
use App\Repository\EventRepository;
use App\Service\DevicePairing;
use App\Service\ExampleMenu;
use App\Service\Revisions;
use App\Tests\ResetDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class DevBrunoFixturesCommandTest extends KernelTestCase
{
    use ResetDatabaseTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        self::resetDatabase();
    }

    public function testRefusedOutsideDevelopment(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:dev:bruno-fixtures'));

        self::assertSame(Command::FAILURE, $tester->execute(['etape' => 'preparer']));
        self::assertSame(0, $this->entityManager()->getRepository(Event::class)->count([]));
    }

    public function testRefusesAnyDatabaseButTheBrunoOne(): void
    {
        // En développement, mais sur une autre base (ici comptoir_test) : rien n'est modifié
        $tester = new CommandTester($this->command(DevBrunoFixturesCommand::DATABASE));

        self::assertSame(Command::FAILURE, $tester->execute(['etape' => 'preparer']));
        self::assertStringContainsString('réservée à la base comptoir_bruno', $tester->getDisplay());
        self::assertSame(0, $this->entityManager()->getRepository(Event::class)->count([]));
    }

    public function testPrepareCreatesActiveEventMenuAndDevicesTillLast(): void
    {
        $tester = new CommandTester($this->command('comptoir_test'));

        self::assertSame(Command::SUCCESS, $tester->execute(['etape' => 'preparer']));
        $lines = explode("\n", trim($tester->getDisplay()));
        self::assertCount(3, $lines);
        self::assertSame(['cuisine', 'affichage', 'caisse'], array_map(static fn ($l) => explode(' ', $l)[0], $lines));
        foreach ($lines as $line) {
            self::assertMatchesRegularExpression('/^[a-z]+ [A-Z0-9]{4}-[A-Z0-9]{4}$/', $line, 'un code par ligne, aucun jeton');
        }

        $event = $this->entityManager()->getRepository(Event::class)->findOneBy(['active' => true]);
        self::assertSame(DevBrunoFixturesCommand::EVENT_WITH_KITCHEN, $event?->getName());
        self::assertTrue($event->hasKitchen());
        self::assertGreaterThan(0, $this->entityManager()->getRepository(Product::class)->count(['event' => $event]));
        self::assertSame(3, $this->entityManager()->getRepository(Device::class)->count(['event' => $event]));
    }

    public function testPrepareOnlyOnAnEmptyDatabase(): void
    {
        $tester = new CommandTester($this->command('comptoir_test'));
        $tester->execute(['etape' => 'preparer']);

        self::assertSame(Command::FAILURE, $tester->execute(['etape' => 'preparer']));
        self::assertSame(1, $this->entityManager()->getRepository(Event::class)->count([]));
    }

    public function testEventWithoutKitchenKitchenPairedBeforeTurningKitchenOff(): void
    {
        $tester = new CommandTester($this->command('comptoir_test'));
        $tester->execute(['etape' => 'preparer']);

        self::assertSame(Command::SUCCESS, $tester->execute(['etape' => 'sans-cuisine']));
        self::assertMatchesRegularExpression('/^cuisine [A-Z0-9]{4}-[A-Z0-9]{4}$/', trim($tester->getDisplay()));
        $event = $this->entityManager()->getRepository(Event::class)->findOneBy(['active' => true]);
        self::assertSame(DevBrunoFixturesCommand::EVENT_WITHOUT_KITCHEN, $event?->getName());
        self::assertTrue($event->hasKitchen(), 'avec cuisine le temps d\'appairer la tablette cuisine');

        self::assertSame(Command::SUCCESS, $tester->execute(['etape' => 'couper-cuisine']));
        $this->entityManager()->refresh($event);
        self::assertFalse($event->hasKitchen());
        self::assertSame(DeviceRole::Kitchen, $this->entityManager()->getRepository(Device::class)->findOneBy(['event' => $event])?->getRole());
    }

    public function testStepsOutOfOrderOrUnknownAreRefused(): void
    {
        $tester = new CommandTester($this->command('comptoir_test'));

        self::assertSame(Command::FAILURE, $tester->execute(['etape' => 'sans-cuisine']));
        self::assertSame(Command::FAILURE, $tester->execute(['etape' => 'couper-cuisine']));
        self::assertSame(Command::FAILURE, $tester->execute(['etape' => 'tout-effacer']));
    }

    /** La commande telle qu'en développement, avec la base autorisée donnée. */
    private function command(string $allowedDatabase): DevBrunoFixturesCommand
    {
        $container = self::getContainer();

        return new DevBrunoFixturesCommand(
            $this->entityManager(),
            $container->get(EventRepository::class),
            $container->get(DevicePairing::class),
            $container->get(ExampleMenu::class),
            $container->get(Revisions::class),
            'dev',
            $allowedDatabase,
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
