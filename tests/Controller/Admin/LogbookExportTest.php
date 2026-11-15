<?php

namespace App\Tests\Controller\Admin;

use App\Entity\AdminUser;
use App\Entity\Device;
use App\Entity\Event;
use App\Entity\Order;
use App\Entity\Product;
use App\Enum\OrderStatus;
use App\Service\OrderPlacement;
use App\Tests\FixturesTrait;
use App\Tests\ResetDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LogbookExportTest extends WebTestCase
{
    use FixturesTrait;
    use ResetDatabaseTrait;

    private KernelBrowser $client;
    private Event $event;
    /** @var array<string, int> */
    private array $ids = [];
    private Device $till;
    private int $number = 0;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetDatabase();
        $this->event = self::createEvent('Loto de Noël', '2026-12-18');
        $this->event->setDepositCents(100);
        $em = self::entityManager();
        $crepe = (new Product($this->event, 'Crêpe "maison"'))->setCategory('Sucré')->setPriceCents(200)->setInitialStock(50)->setGoesToKitchen(true);
        $soda = (new Product($this->event, 'Soda'))->setCategory('Boissons')->setPriceCents(150)->setHasDeposit(true);
        // Nom piégé : une formule Excel qui ouvrirait un lien
        $piege = (new Product($this->event, '=HYPERLINK("http://exemple.test")'))->setPriceCents(100);
        foreach ([$crepe, $soda, $piege] as $product) {
            $em->persist($product);
        }
        $em->flush();
        $this->ids = ['Crêpe' => $crepe->getId(), 'Soda' => $soda->getId(), 'Piège' => $piege->getId()];
        self::createPairedDevice($this->event);
        $this->till = $em->getRepository(Device::class)->findOneBy([]);
    }

    public function testExportIsReservedToTheAdministration(): void
    {
        $this->client->request('GET', '/admin/carnet/export/commandes');

        self::assertResponseRedirects('/login');
    }

    public function testOrdersExportOpensInFrenchExcel(): void
    {
        $this->login();
        $this->place(['Crêpe' => 2, 'Soda' => 1], cash: 1000, note: '+33 6 00 00 00 00');

        $csv = $this->download('/admin/carnet/export/commandes');

        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertSame('attachment; filename=carnet-loto-de-noel-2026-12-18-commandes.csv', $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringStartsWith("\u{FEFF}Numéro;Caisse;Date;Heure;Paiement;Articles;", $csv, 'BOM pour les accents, point-virgule');
        self::assertStringEndsWith("\r\n", $csv);

        $row = $this->rows($csv)[1];
        self::assertSame(['A1', 'A'], \array_slice($row, 0, 2));
        self::assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4}$#', $row[2]);
        self::assertMatchesRegularExpression('#^\d{2}:\d{2}$#', $row[3]);
        // 2 crêpes (4,00) + 1 soda (1,50) + 1 consigne (1,00) = 6,50 ; 10,00 reçus, 3,50 rendus
        self::assertSame(['Espèces', '2 × Crêpe "maison", 1 × Soda', '5,50', '1,00', '0,00', '6,50', '10,00', '3,50', ''], \array_slice($row, 4, 9));
        self::assertSame("'+33 6 00 00 00 00", $row[13], 'texte commençant par + neutralisé');
        self::assertSame('Table', $this->rows($csv)[0][14]);
        self::assertSame('', $row[14], 'commande au comptoir : pas de table');
    }

    public function testOrdersExportGivesTheTable(): void
    {
        $this->login();
        $order = $this->place(['Crêpe' => 1], card: true);
        self::entityManager()->find(Order::class, $order->getId())->setTableNumber(304);
        self::entityManager()->flush();

        $row = $this->rows($this->download('/admin/carnet/export/commandes'))[1];

        self::assertSame('304', $row[14]);
    }

    /** Signalé à l'usage : les montants de la commande répétés sur chaque ligne faussaient les sommes. */
    public function testEveryAmountColumnAddsUpWithoutDoubleCounting(): void
    {
        $this->login();
        // Comme D1 : 2 articles à 4,00 € et 2 gobelets rendus, payé par carte : 6,00 €
        $this->place(['Crêpe' => 2], card: true, returns: 2);
        $this->place(['Crêpe' => 1, 'Soda' => 2], cash: 1000);
        $this->place([], cash: 0, returns: 1);
        $cancelled = $this->place(['Crêpe' => 5], cash: 2000);
        self::entityManager()->find(Order::class, $cancelled->getId())->setStatus(OrderStatus::Cancelled);
        self::entityManager()->flush();

        $orders = $this->rows($this->download('/admin/carnet/export/commandes'));
        $lines = $this->rows($this->download('/admin/carnet/export/lignes'));

        self::assertSame(['A1', 'A2', 'A3'], array_column(\array_slice($orders, 1), 0), 'une ligne par commande, annulée exclue');
        // Chiffre d'affaires : 4,00 + 5,00 = 9,00 ; consignes : 2,00 encaissées, 3,00 rendues ; total : 2,00 + 7,00 − 1,00 = 8,00
        self::assertSame(900, $this->sum($orders, 'Chiffre d\'affaires (€)'));
        self::assertSame(200, $this->sum($orders, 'Consignes encaissées (€)'));
        self::assertSame(300, $this->sum($orders, 'Consignes rendues (€)'));
        self::assertSame(800, $this->sum($orders, 'Total encaissé (€)'));
        foreach (\array_slice($orders, 1) as $row) {
            self::assertSame(self::cents($row[9]), self::cents($row[6]) + self::cents($row[7]) - self::cents($row[8]), 'CA + consignes − rendues = total, commande '.$row[0]);
        }

        self::assertNotContains('Total commande (€)', $lines[0], 'le détail ne répète aucun montant de commande');
        self::assertSame(800, $this->sum($lines, 'Total ligne (€)'), 'le détail se somme au même total');
        self::assertNotContains('A4', array_column($lines, 0));
    }

    public function testCellsThatLookLikeFormulasAreNeutralised(): void
    {
        $this->login();
        $this->place(['Piège' => 1], cash: 100);

        $csv = $this->download('/admin/carnet/export/lignes');

        self::assertStringContainsString(';"\'=HYPERLINK(""http://exemple.test"")";', $csv);
        self::assertStringNotContainsString(';=HYPERLINK', $csv);
        self::assertSame('\'=HYPERLINK("http://exemple.test")', $this->rows($csv)[1][6]);
        self::assertStringNotContainsString(';=HYPERLINK', $this->download('/admin/carnet/export/commandes'));
    }

    public function testArticlesExportSummarisesSalesAndStock(): void
    {
        $this->login();
        $this->place(['Crêpe' => 3], cash: 1000);

        $csv = $this->download('/admin/carnet/export/articles');

        self::assertSame('attachment; filename=carnet-loto-de-noel-2026-12-18-articles.csv', $this->client->getResponse()->headers->get('Content-Disposition'));
        $rows = $this->rows($csv);
        self::assertSame(['Article', 'Catégorie', 'Formule', 'Retiré du menu', 'Vendus', 'Chiffre d\'affaires (€)', 'Stock de départ', 'Stock restant'], $rows[0]);
        self::assertSame(['Crêpe "maison"', 'Sucré', 'non', 'non', '3', '6,00', '50', '47'], $rows[1]);
        self::assertSame(['Soda', 'Boissons', 'non', 'non', '0', '0,00', '', ''], $rows[2]);
    }

    public function testCategoriesExportAddsUpToTheRevenue(): void
    {
        $this->login();
        $this->place(['Crêpe' => 3, 'Soda' => 2], cash: 2000);
        $this->place(['Soda' => 1], cash: 500);

        $csv = $this->download('/admin/carnet/export/categories');

        self::assertSame('attachment; filename=carnet-loto-de-noel-2026-12-18-categories.csv', $this->client->getResponse()->headers->get('Content-Disposition'));
        $rows = $this->rows($csv);
        self::assertSame(['Catégorie', 'Articles vendus', 'Chiffre d\'affaires (€)'], $rows[0]);
        self::assertSame(['Sucré', '3', '6,00'], $rows[1]);
        self::assertSame(['Boissons', '3', '4,50'], $rows[2], 'consignes exclues');
        // Même total que le chiffre d'affaires de l'export des commandes
        self::assertSame($this->sum($this->rows($this->download('/admin/carnet/export/commandes')), 'Chiffre d\'affaires (€)'), $this->sum($rows, 'Chiffre d\'affaires (€)'));
    }

    public function testUnknownExportIsNotFound(): void
    {
        $this->login();
        $this->client->request('GET', '/admin/carnet/export/tout');

        self::assertResponseStatusCodeSame(404);
    }

    /** @param list<list<string>> $rows */
    private function sum(array $rows, string $column): int
    {
        $index = array_search($column, $rows[0], true);
        self::assertIsInt($index, 'colonne '.$column);

        return array_sum(array_map(static fn (array $row): int => self::cents($row[$index]), \array_slice($rows, 1)));
    }

    /** « -1,50 » → -150, comme Excel lit la cellule */
    private static function cents(string $value): int
    {
        return '' === $value ? 0 : (int) round((float) str_replace(',', '.', $value) * 100);
    }

    private function login(): void
    {
        $admin = (new AdminUser('camille'))->setPassword('inutile-ici');
        self::entityManager()->persist($admin);
        self::entityManager()->flush();
        $this->client->loginUser($admin);
    }

    private function download(string $url): string
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /** @return list<list<string>> */
    private function rows(string $csv): array
    {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, substr($csv, \strlen("\u{FEFF}")));
        rewind($handle);
        $rows = [];
        while (false !== ($row = fgetcsv($handle, separator: ';', escape: ''))) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param array<string, int> $items */
    private function place(array $items, ?int $cash = null, int $returns = 0, ?string $note = null, bool $card = false): Order
    {
        $payload = [
            'uuid' => \sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xFFFFFFFF), ++$this->number),
            'number' => $this->number,
            'paymentMethod' => $card ? 'card' : 'cash',
            'amountReceivedCents' => $card ? null : $cash,
            'lines' => array_map(fn (string $name, int $q) => ['productId' => $this->ids[$name], 'quantity' => $q], array_keys($items), $items),
            'depositReturns' => $returns,
            'note' => $note,
        ];
        [$order] = self::getContainer()->get(OrderPlacement::class)->place(self::entityManager()->find(Device::class, $this->till->getId()), $payload);

        return $order;
    }
}
