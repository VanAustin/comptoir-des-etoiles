<?php

namespace App\Tests\Service;

use App\Entity\Device;
use App\Entity\Event;
use App\Entity\Order;
use App\Entity\Product;
use App\Enum\OrderStatus;
use App\Service\Logbook;
use App\Service\OrderPlacement;
use App\Tests\FixturesTrait;
use App\Tests\ResetDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Chiffres du Carnet de bord, sur des commandes passées par le vrai circuit de la caisse. */
class LogbookTest extends KernelTestCase
{
    use FixturesTrait;
    use ResetDatabaseTrait;

    private Event $event;
    /** @var array<string, Device> caisse par lettre */
    private array $tills = [];
    /** @var array<string, int> */
    private array $ids = [];
    private int $number = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        self::resetDatabase();
        $this->event = self::createEvent();
        $this->event->setDepositCents(100);

        $em = self::entityManager();
        $crepe = (new Product($this->event, 'Crêpe'))->setPriceCents(200)->setInitialStock(50)->setGoesToKitchen(true);
        $soda = (new Product($this->event, 'Soda'))->setPriceCents(150)->setInitialStock(40)->setHasDeposit(true);
        $duo = (new Product($this->event, 'Duo'))->setPriceCents(450)->setGoesToKitchen(true);
        $duo->addComponent($crepe, 2);
        foreach ([$crepe, $soda, $duo] as $product) {
            $em->persist($product);
        }
        $em->flush();
        $this->ids = ['Crêpe' => $crepe->getId(), 'Soda' => $soda->getId(), 'Duo' => $duo->getId()];

        // Deux caisses : A puis B
        [, $tokenA] = self::createPairedDevice($this->event, 'Caisse A');
        [, $tokenB] = self::createPairedDevice($this->event, 'Caisse B');
        foreach ($em->getRepository(Device::class)->findAll() as $device) {
            $this->tills[(string) $device->getTillLetter()] = $device;
        }
    }

    public function testRevenueExcludesDepositsAndCancelledOrders(): void
    {
        $this->place('A', ['Crêpe' => 2, 'Soda' => 1], cash: 1000);   // 4,00 + 1,50 + 1,00 consigne
        $this->place('B', ['Duo' => 1], card: true);                     // 4,50
        $cancelled = $this->place('A', ['Crêpe' => 5], cash: 1000);      // annulée
        $cancelled->setStatus(OrderStatus::Cancelled);
        self::entityManager()->flush();

        $totals = $this->summary()['totals'];

        self::assertSame(1000, $totals['revenue'], '4,00 + 1,50 + 4,50, sans consigne ni commande annulée');
        self::assertSame(2, $totals['orders']);
        self::assertSame(1, $totals['cancelled']);
        self::assertSame(500, $totals['basket']);
        self::assertSame([1, 650, 1, 450], [$totals['cashCount'], $totals['cashAmount'], $totals['cardCount'], $totals['cardAmount']]);
    }

    public function testEventWithoutKitchenIsSummarizedTheSameWay(): void
    {
        $this->event->setWithKitchen(false);
        self::entityManager()->flush();

        $crepes = $this->place('A', ['Crêpe' => 2, 'Soda' => 1], cash: 1000);
        $this->place('B', ['Duo' => 1], card: true);

        self::assertSame(OrderStatus::Served, $crepes->getStatus());
        $totals = $this->summary()['totals'];
        self::assertSame(1000, $totals['revenue']);
        self::assertSame(2, $totals['orders']);
        self::assertSame([1, 650, 1, 450], [$totals['cashCount'], $totals['cashAmount'], $totals['cardCount'], $totals['cardAmount']]);
    }

    public function testDepositsAreCountedApart(): void
    {
        $this->place('A', ['Soda' => 3], cash: 1000);   // 3 consignes encaissées
        $this->place('A', [], cash: 0, returns: 2);     // 2 gobelets rendus : 2,00 € rendus

        $totals = $this->summary()['totals'];

        self::assertSame(300, $totals['depositsIn']);
        self::assertSame(200, $totals['depositsOut']);
        self::assertSame(100, $totals['depositBalance']);
        self::assertSame(1, $totals['cupsNotReturned']);
        self::assertSame(450, $totals['revenue']);
        self::assertSame(450, $totals['basket'], 'le retour de consigne seul ne compte pas dans le panier moyen');
    }

    public function testExpectedCashPerTillDeductsChangeAndRefunds(): void
    {
        $this->place('A', ['Crêpe' => 1], cash: 500);            // 2,00 gardés, 3,00 rendus
        $this->place('A', ['Soda' => 1], cash: 250);             // 1,50 + 1,00 consigne
        $this->place('A', [], cash: 0, returns: 1);              // 1,00 rendu
        $this->place('A', ['Crêpe' => 1], card: true);           // carte : pas dans la caisse
        $this->place('B', ['Duo' => 2], cash: 1000);             // 9,00

        $tills = $this->summary()['tills'];

        self::assertSame(['A', 'B'], array_keys($tills));
        self::assertSame(['orders' => 4, 'cashExpected' => 350, 'card' => 200, 'shortfall' => 0], $tills['A']);
        self::assertSame(['orders' => 1, 'cashExpected' => 900, 'card' => 0, 'shortfall' => 0], $tills['B']);
    }

    public function testOfflineShortfallIsReported(): void
    {
        // Encaissée hors ligne à 1,50 € alors que la crêpe vaut 2,00 €
        $order = $this->place('B', ['Crêpe' => 1], cash: 150, offline: true);

        $summary = $this->summary();

        self::assertSame(150, $summary['tills']['B']['cashExpected'], 'la caisse ne contient que ce qui a été reçu');
        self::assertSame(50, $summary['tills']['B']['shortfall']);
        self::assertCount(1, $summary['shortfalls']);
        self::assertSame($order->getId(), $summary['shortfalls'][0]['order']->getId());
        self::assertSame(50, $summary['shortfalls'][0]['missing']);
    }

    public function testArticlesShowSalesAndStockIncludingFormulas(): void
    {
        $this->place('A', ['Crêpe' => 3, 'Duo' => 2], cash: 2000);  // 3 crêpes + 2 Duo (4 crêpes)

        $articles = array_column($this->summary()['articles'], null, 'name');

        self::assertSame(3, $articles['Crêpe']['sold']);
        self::assertSame(600, $articles['Crêpe']['revenue']);
        self::assertSame(43, $articles['Crêpe']['remaining'], '50 − 3 − 2 × 2');
        self::assertSame(2, $articles['Duo']['sold']);
        self::assertTrue($articles['Duo']['formula']);
        self::assertSame(21, $articles['Duo']['remaining'], '43 crêpes, 2 par Duo');
        self::assertSame(0, $articles['Soda']['sold']);
    }

    public function testRemovedProductKeepsItsSales(): void
    {
        $this->place('A', ['Soda' => 2], cash: 1000);
        $em = self::entityManager();
        $em->find(Product::class, $this->ids['Soda'])->archive();
        $em->flush();

        $articles = array_column($this->summary()['articles'], null, 'name');

        self::assertTrue($articles['Soda']['archived']);
        self::assertSame(2, $articles['Soda']['sold']);
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        return self::getContainer()->get(Logbook::class)->summarize(self::entityManager()->find(Event::class, $this->event->getId()));
    }

    /** @param array<string, int> $items */
    private function place(string $letter, array $items, ?int $cash = null, bool $card = false, int $returns = 0, bool $offline = false): Order
    {
        $payload = [
            'uuid' => \sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xFFFFFFFF), ++$this->number),
            'number' => $this->number,
            'paymentMethod' => $card ? 'card' : 'cash',
            'lines' => array_map(fn (string $name, int $q) => ['productId' => $this->ids[$name], 'quantity' => $q], array_keys($items), $items),
            'depositReturns' => $returns,
            'offline' => $offline,
        ];
        if (!$card) {
            $payload['amountReceivedCents'] = $cash;
        }
        [$order] = self::getContainer()->get(OrderPlacement::class)->place($this->tills[$letter], $payload);

        return $order;
    }
}
