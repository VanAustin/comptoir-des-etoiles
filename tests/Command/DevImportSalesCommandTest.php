<?php

namespace App\Tests\Command;

use App\Command\DevImportSalesCommand;
use App\Entity\Device;
use App\Entity\Event;
use App\Entity\Order;
use App\Entity\Product;
use App\Enum\LineKind;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Service\OrderPlacement;
use App\Service\StockCalculator;
use App\Tests\FixturesTrait;
use App\Tests\ResetDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class DevImportSalesCommandTest extends KernelTestCase
{
    use FixturesTrait;
    use ResetDatabaseTrait;

    private const EXAMPLE = __DIR__.'/../fixtures/import-ventes-exemple.json';
    private const EVENT = 'Kermesse d\'essai (bons papier)';

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        self::bootKernel();
        self::resetDatabase();
    }

    protected function tearDown(): void
    {
        array_map('unlink', $this->files);
        parent::tearDown();
    }

    public function testRefusedOutsideDevelopment(): void
    {
        // Environnement de test, comme en production : la commande refuse
        $tester = new CommandTester((new Application(self::$kernel))->find('app:dev:import-sales'));
        $tester->execute(['file' => self::EXAMPLE]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('réservée au développement', $tester->getDisplay());
        self::assertSame(0, self::entityManager()->getRepository(Event::class)->count([]));
    }

    public function testImportsTheExampleFile(): void
    {
        $tester = $this->import(self::EXAMPLE);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('OK : 3 commandes, 6 articles, 18,50 €', $display);
        self::assertStringContainsString('Données inventées.', $display);
        self::assertStringContainsString('article : Glace', $display);

        $em = self::entityManager();
        $em->clear();
        $event = $em->getRepository(Event::class)->findOneBy(['name' => self::EVENT]);
        self::assertNotNull($event);
        self::assertFalse($event->isActive());
        self::assertTrue($event->hasKitchen());
        self::assertSame(100, $event->getDepositCents());

        $products = [];
        foreach ($em->getRepository(Product::class)->findBy(['event' => $event], ['position' => 'ASC']) as $product) {
            $products[$product->getName()] = $product;
        }
        self::assertSame(['Saucisse', 'Portion de frites', 'Menu saucisses', 'Jus de pomme'], array_keys($products));
        self::assertSame(20, $products['Saucisse']->getInitialStock());
        self::assertNull($products['Menu saucisses']->getInitialStock());
        self::assertSame(2, $products['Menu saucisses']->componentQuantity($products['Saucisse']));
        self::assertTrue($products['Jus de pomme']->hasDeposit());
        self::assertFalse($products['Saucisse']->isSoldAlone());
        self::assertTrue($products['Portion de frites']->isSoldAlone(), 'vendu seul par défaut');

        $device = $em->getRepository(Device::class)->findOneBy(['event' => $event]);
        self::assertSame(DevImportSalesCommand::DEVICE_NAME, $device?->getName());
        self::assertSame('A', $device->getTillLetter());
        self::assertTrue($device->isRevoked());

        $orders = $em->getRepository(Order::class)->findBy(['event' => $event], ['number' => 'ASC']);
        $timezone = new \DateTimeZone(self::getContainer()->getParameter('app.timezone'));
        self::assertSame(['A2', 'A3', 'A5'], array_map(static fn (Order $o): string => $o->getDisplayNumber(), $orders));
        self::assertSame(
            ['2026-06-20 18:30', '2026-06-20 18:32', '2026-06-20 18:34'],
            array_map(static fn (Order $o): string => $o->getCreatedAt()->setTimezone($timezone)->format('Y-m-d H:i'), $orders),
            'dans l\'ordre des numéros, 2 minutes d\'écart',
        );
        foreach ($orders as $order) {
            self::assertSame(OrderStatus::Served, $order->getStatus());
            self::assertSame(PaymentMethod::Card, $order->getPaymentMethod());
            self::assertSame($device->getId(), $order->getDevice()?->getId());
        }
        // Recalculé par le même code que l'API : 6,00 + 2 × 1,50 + 2 consignes
        self::assertSame([1100, 250, 500], array_map(static fn (Order $o): int => $o->getTotalCents(), $orders));
        self::assertSame(1, \count(array_filter($orders[0]->getLines()->toArray(), static fn ($l): bool => LineKind::Deposit === $l->getKind())));
        self::assertSame('Bon papier : Menu lu sur le talon', $orders[0]->getNote());
        self::assertSame('Bon papier : Jus seul, sans cuisine', $orders[1]->getNote(), 'gardée même sans cuisine');
        self::assertNull($orders[2]->getNote());

        // Composants figés : le menu décompte deux saucisses
        $remaining = self::getContainer()->get(StockCalculator::class)->remaining($event, array_values($products));
        self::assertSame(18, $remaining[$products['Saucisse']->getId()]);
        self::assertSame(27, $remaining[$products['Portion de frites']->getId()]);
    }

    public function testExistingEventIsOnlyReplacedOnRequest(): void
    {
        self::assertSame(Command::SUCCESS, $this->import(self::EXAMPLE)->getStatusCode());

        $tester = $this->import(self::EXAMPLE);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--replace', $tester->getDisplay());

        $tester = $this->import(self::EXAMPLE, replace: true);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::entityManager()->clear();
        self::assertSame(1, self::entityManager()->getRepository(Event::class)->count([]));
        self::assertSame(3, self::entityManager()->getRepository(Order::class)->count([]));
        self::assertSame(4, self::entityManager()->getRepository(Product::class)->count([]));
        self::assertSame(1, self::entityManager()->getRepository(Device::class)->count([]));
    }

    public function testMismatchWithExpectedCreatesNothing(): void
    {
        $tester = $this->import($this->variant(static function (array &$data): void {
            $data['expected']['totalCents'] = 1900;
            $data['expected']['itemsByProduct']['JUS'] = 4;
        }));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('rien n\'est importé', $display);
        self::assertStringContainsString('Total : 18,50 € au lieu de 19,00 €', $display);
        self::assertStringContainsString('Jus de pomme : 3 au lieu de 4', $display);
        $this->assertNothingCreated();
    }

    public function testReplaceKeepsTheOldEventWhenTheNewImportFails(): void
    {
        self::assertSame(Command::SUCCESS, $this->import(self::EXAMPLE)->getStatusCode());

        $tester = $this->import($this->variant(static function (array &$data): void {
            $data['expected']['orders'] = 4;
        }), replace: true);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::entityManager()->clear();
        self::assertSame(3, self::entityManager()->getRepository(Order::class)->count([]), 'l\'ancien import est intact');
    }

    /** @return iterable<string, array{callable(array<string, mixed>): void, string}> */
    public static function invalidFiles(): iterable
    {
        yield 'mauvais format' => [static function (array &$d): void { $d['format'] = 'autre'; }, 'Format attendu'];
        yield 'mauvaise version' => [static function (array &$d): void { $d['version'] = 2; }, 'version 1'];
        yield 'clé inconnue' => [static function (array &$d): void { $d['orders'][0]['paid'] = true; }, 'orders[0] : clé « paid » inconnue'];
        yield 'article inconnu' => [static function (array &$d): void { $d['orders'][1]['lines'][0]['product'] = 'XX'; }, 'orders[1].lines[0] : article « XX » inconnu'];
        yield 'quantité hors limite' => [static function (array &$d): void { $d['orders'][0]['lines'][0]['qty'] = 0; }, 'orders[0].lines[0].qty'];
        yield 'quantité non entière' => [static function (array &$d): void { $d['orders'][0]['lines'][0]['qty'] = '2'; }, 'orders[0].lines[0].qty'];
        yield 'numéro en double' => [static function (array &$d): void { $d['orders'][1]['number'] = 5; }, 'A5 est déjà utilisé'];
        yield 'formule dans une formule' => [static function (array &$d): void { $d['products'][2]['components'][0]['product'] = 'MENU'; }, 'ne peut pas en contenir une autre'];
        yield 'autre lettre' => [static function (array &$d): void { $d['orders'][0]['letter'] = 'B'; }, 'seule la lettre A'];
        yield 'date invalide' => [static function (array &$d): void { $d['event']['date'] = '2026-02-30'; }, 'event.date'];
        yield 'formule non vendue seule' => [static function (array &$d): void { $d['products'][2]['soldAlone'] = false; }, 'une formule est toujours vendue seule'];
        yield 'expected incomplet' => [static function (array &$d): void { unset($d['expected']['items']); }, 'clé « items » manquante'];
    }

    /** @param callable(array<string, mixed>): void $change */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFiles')]
    public function testInvalidFileIsRefused(callable $change, string $message): void
    {
        $tester = $this->import($this->variant($change));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($message, preg_replace('/\s+/', ' ', $tester->getDisplay()));
        $this->assertNothingCreated();
    }

    public function testUnreadableJsonIsRefused(): void
    {
        $path = $this->tempFile('{"format": ');

        $tester = $this->import($path);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('n\'est pas un JSON valide', $tester->getDisplay());
    }

    private function import(string $path, bool $replace = false): CommandTester
    {
        $command = new DevImportSalesCommand(
            self::entityManager(),
            self::getContainer()->get(OrderPlacement::class),
            'dev',
            self::getContainer()->getParameter('app.timezone'),
        );
        $tester = new CommandTester($command);
        $tester->execute(['file' => $path] + ($replace ? ['--replace' => true] : []));

        return $tester;
    }

    /** @param callable(array<string, mixed>): void $change */
    private function variant(callable $change): string
    {
        $data = json_decode((string) file_get_contents(self::EXAMPLE), true);
        $change($data);

        return $this->tempFile(json_encode($data, \JSON_UNESCAPED_UNICODE));
    }

    private function tempFile(string $content): string
    {
        $path = sys_get_temp_dir().'/import-ventes-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    private function assertNothingCreated(): void
    {
        self::entityManager()->clear();
        foreach ([Event::class, Product::class, Device::class, Order::class] as $class) {
            self::assertSame(0, self::entityManager()->getRepository($class)->count([]), $class);
        }
    }
}
