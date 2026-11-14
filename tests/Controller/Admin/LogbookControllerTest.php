<?php

namespace App\Tests\Controller\Admin;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Entity\Event;
use App\Entity\Order;
use App\Entity\Product;
use App\Enum\OrderStatus;
use App\Tests\FixturesTrait;
use App\Tests\OrderFixturesTrait;
use App\Tests\ResetDatabaseTrait;
use App\Tests\RevisionsTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LogbookControllerTest extends WebTestCase
{
    use FixturesTrait;
    use OrderFixturesTrait;
    use ResetDatabaseTrait;
    use RevisionsTrait;

    private KernelBrowser $client;
    private Event $event;
    private Product $crepe;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetDatabase();
        $this->event = self::createEvent('Loto de Noël');
        $this->crepe = self::createProduct($this->event, 'Crêpe sucre', kitchen: true);
    }

    public function testLogbookIsReservedToTheAdministration(): void
    {
        $this->client->request('GET', '/admin/carnet');

        self::assertResponseRedirects('/login');
    }

    public function testShowsFiguresTillsArticlesAndHistory(): void
    {
        $this->login();
        $order = self::createOrder($this->event, [[$this->crepe, 2]], OrderStatus::Served, letter: 'B');
        $this->setTotal($order, 400, received: 500);
        self::entityManager()->find(Product::class, $this->crepe->getId())->setInitialStock(30);
        self::entityManager()->flush();

        $crawler = $this->client->request('GET', '/admin/carnet');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Loto de Noël', $content);
        self::assertStringContainsString('Caisse B', $content);
        self::assertStringContainsString('2 × Crêpe sucre', $content);
        self::assertCount(1, $crawler->filterXPath('//a[@href="/admin/carnet/commande/'.$order->getId().'/annuler"]'));
        self::assertCount(1, $crawler->filterXPath('//meter'));
    }

    public function testTableServiceFiguresAndTableInHistory(): void
    {
        $this->login();
        $soda = self::createProduct($this->event, 'Soda');
        $atTable = self::createOrder($this->event, [[$this->crepe, 1], [$soda, 1]], OrderStatus::Served);
        self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Served);
        $cancelled = self::createOrder($this->event, [[$this->crepe, 3]], OrderStatus::Cancelled);
        foreach ([$atTable, $cancelled] as $order) {
            self::entityManager()->find(Order::class, $order->getId())->setTableNumber(304);
        }
        self::entityManager()->flush();

        $crawler = $this->client->request('GET', '/admin/carnet');

        // Une commande à table (l'annulée ne compte pas) : crêpe et soda, 2,00 € chacun
        $kpi = $crawler->filterXPath('//div[contains(@class, "kpi")][span[.="Service à table"]]');
        self::assertSame(1, $kpi->count());
        self::assertStringContainsString('4,00', $kpi->text());
        self::assertStringContainsString('1 commande à table', $kpi->text());
        self::assertStringContainsString('Table 304', $crawler->filterXPath('//tr[td[contains(., "'.$atTable->getDisplayNumber().'")]]')->text());
    }

    public function testNoTableServiceFiguresForACounterOnlyEvent(): void
    {
        $this->login();
        self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Served);

        $crawler = $this->client->request('GET', '/admin/carnet');

        self::assertSame(0, $crawler->filterXPath('//div[contains(@class, "kpi")][span[.="Service à table"]]')->count());
    }

    public function testHistoryFilters(): void
    {
        $this->login();
        $a = self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Served, letter: 'A');
        $b = self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Prep, letter: 'B');

        $crawler = $this->client->request('GET', '/admin/carnet?caisse=b');
        self::assertSame([$b->getDisplayNumber()], $this->historyNumbers($crawler));

        $crawler = $this->client->request('GET', '/admin/carnet?etat=served');
        self::assertSame([$a->getDisplayNumber()], $this->historyNumbers($crawler));

        $crawler = $this->client->request('GET', '/admin/carnet?numero='.strtolower($a->getDisplayNumber()));
        self::assertSame([$a->getDisplayNumber()], $this->historyNumbers($crawler));
    }

    public function testPastEventCanBeShown(): void
    {
        $this->login();
        $past = self::createEvent('Carnaval', '-60 days', active: false);
        self::createOrder($past, [[self::createProduct($past, 'Beignet', kitchen: true), 3]], OrderStatus::Served);

        $this->client->request('GET', '/admin/carnet?evenement='.$past->getId());

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('« Carnaval »', $content);
        self::assertStringContainsString('3 × Beignet', $content);
        self::assertStringNotContainsString('Crêpe sucre</td>', $content);
    }

    public function testCancellationIsConfirmedTracedAndRemovesTheOrderFromTheKitchen(): void
    {
        $this->login();
        $order = self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Prep);

        $crawler = $this->client->request('GET', '/admin/carnet/commande/'.$order->getId().'/annuler');
        self::assertStringContainsString('Annuler la commande '.$order->getDisplayNumber().' ?', (string) $this->client->getResponse()->getContent());
        self::assertSame(OrderStatus::Prep, $this->reload($order)->getStatus(), 'rien avant confirmation');

        $before = self::revisionsOf($this->event->getId());
        $this->client->submit($crawler->selectButton('Annuler la commande')->form());

        self::assertResponseRedirects('/admin/carnet?evenement='.$this->event->getId());
        self::assertSame(OrderStatus::Cancelled, $this->reload($order)->getStatus());
        self::assertRevisionsMoved($before, 1, 0, $this->event->getId(), 'la cuisine et les Arrivées voient l\'annulation');
        self::assertSame(1, self::entityManager()->getRepository(AuditLog::class)->count(['action' => 'order.cancel']));
        self::assertSame([], self::entityManager()->getRepository(Order::class)->findOpen($this->reload($order)->getEvent()));
    }

    public function testCancellationRequiresAValidCsrfToken(): void
    {
        $this->login();
        $order = self::createOrder($this->event, [[$this->crepe, 1]]);

        $this->client->request('POST', '/admin/carnet/commande/'.$order->getId().'/annuler', ['_token' => 'faux']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(OrderStatus::New, $this->reload($order)->getStatus());
    }

    public function testCancelledOrderCannotBeCancelledAgain(): void
    {
        $this->login();
        $order = self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Cancelled);

        $this->client->request('GET', '/admin/carnet/commande/'.$order->getId().'/annuler');

        self::assertResponseRedirects('/admin/carnet?evenement='.$this->event->getId());
        self::assertSame(0, self::entityManager()->getRepository(AuditLog::class)->count(['action' => 'order.cancel']));
    }

    public function testShortfallIsVisible(): void
    {
        $this->login();
        $order = self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Served);
        $this->setTotal($order, 200, received: 150);

        $this->client->request('GET', '/admin/carnet');

        self::assertStringContainsString('Écart de caisse : '.$order->getDisplayNumber().' (il manque 0,50', (string) $this->client->getResponse()->getContent());
    }

    private function login(): void
    {
        $admin = (new AdminUser('camille'))->setPassword('inutile-ici');
        self::entityManager()->persist($admin);
        self::entityManager()->flush();
        $this->client->loginUser($admin);
    }

    private function setTotal(Order $order, int $total, int $received): void
    {
        $order = self::entityManager()->find(Order::class, $order->getId());
        $order->setTotalCents($total)->setAmountReceivedCents($received);
        self::entityManager()->flush();
    }

    private function reload(Order $order): Order
    {
        self::entityManager()->clear();

        return self::entityManager()->find(Order::class, $order->getId());
    }

    /** @return list<string> */
    private function historyNumbers(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        return $crawler->filterXPath('//table[contains(@class, "history")]//tbody//td[contains(@class, "stack-title")]')->each(static fn ($td): string => trim($td->text()));
    }
}
