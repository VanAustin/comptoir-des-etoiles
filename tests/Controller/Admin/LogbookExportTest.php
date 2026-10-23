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
        $crepe = (new Product($this->event, 'Crêpe "maison"'))->setPriceCents(200)->setInitialStock(50)->setGoesToKitchen(true);
        $soda = (new Product($this->event, 'Soda'))->setPriceCents(150)->setHasDeposit(true);
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
        $this->client->request('GET', '/admin/carnet/export/ventes');

        self::assertResponseRedirects('/login');
    }

    public function testSalesExportOpensInFrenchExcel(): void
    {
        $this->login();
        $this->place(['Crêpe' => 2, 'Soda' => 1], cash: 1000, note: '+33 6 00 00 00 00');
        $this->place([], cash: 0, returns: 1);

        $csv = $this->download('/admin/carnet/export/ventes');

        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertSame('attachment; filename=carnet-loto-de-noel-2026-12-18-ventes.csv', $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringStartsWith("\u{FEFF}Numéro;Caisse;Date;Heure;État;Paiement;Type de ligne;Article;", $csv, 'BOM pour les accents, point-virgule');
        self::assertStringEndsWith("\r\n", $csv);

        $rows = $this->rows($csv);
        self::assertCount(5, $rows, 'en-tête + crêpes, soda, consigne + retour de consigne');
        self::assertSame(['A1', 'A'], \array_slice($rows[1], 0, 2));
        self::assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4}$#', $rows[1][2]);
        self::assertMatchesRegularExpression('#^\d{2}:\d{2}$#', $rows[1][3]);
        self::assertSame(['Article', 'Crêpe "maison"', '2', '2,00', '4,00', '6,50', '10,00', '3,50', ''], \array_slice($rows[1], 6, 9));
        self::assertSame('Consigne', $rows[3][6]);
        self::assertSame(['Retour de consigne', 'Retour de consigne', '1', '-1,00', '-1,00', '-1,00'], \array_slice($rows[4], 6, 6), 'un montant négatif reste un nombre');
        self::assertSame("'+33 6 00 00 00 00", $rows[1][15], 'texte commençant par + neutralisé');
    }

    public function testCellsThatLookLikeFormulasAreNeutralised(): void
    {
        $this->login();
        $this->place(['Piège' => 1], cash: 100);

        $csv = $this->download('/admin/carnet/export/ventes');

        self::assertStringContainsString(';"\'=HYPERLINK(""http://exemple.test"")";', $csv);
        self::assertStringNotContainsString(';=HYPERLINK', $csv);
        self::assertSame('\'=HYPERLINK("http://exemple.test")', $this->rows($csv)[1][7]);
    }

    public function testCancelledOrdersAreExportedWithTheirStatus(): void
    {
        $this->login();
        $order = $this->place(['Crêpe' => 1], cash: 200);
        self::entityManager()->find(Order::class, $order->getId())->setStatus(OrderStatus::Cancelled);
        self::entityManager()->flush();

        self::assertSame('Annulée', $this->rows($this->download('/admin/carnet/export/ventes'))[1][4]);
    }

    public function testArticlesExportSummarisesSalesAndStock(): void
    {
        $this->login();
        $this->place(['Crêpe' => 3], cash: 1000);

        $csv = $this->download('/admin/carnet/export/articles');

        self::assertSame('attachment; filename=carnet-loto-de-noel-2026-12-18-articles.csv', $this->client->getResponse()->headers->get('Content-Disposition'));
        $rows = $this->rows($csv);
        self::assertSame(['Article', 'Formule', 'Retiré du menu', 'Vendus', 'Chiffre d\'affaires (€)', 'Stock de départ', 'Stock restant'], $rows[0]);
        self::assertSame(['Crêpe "maison"', 'non', 'non', '3', '6,00', '50', '47'], $rows[1]);
        self::assertSame(['Soda', 'non', 'non', '0', '0,00', '', ''], $rows[2]);
    }

    public function testUnknownExportIsNotFound(): void
    {
        $this->login();
        $this->client->request('GET', '/admin/carnet/export/tout');

        self::assertResponseStatusCodeSame(404);
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
    private function place(array $items, ?int $cash = null, int $returns = 0, ?string $note = null): Order
    {
        $payload = [
            'uuid' => \sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xFFFFFFFF), ++$this->number),
            'number' => $this->number,
            'paymentMethod' => 'cash',
            'amountReceivedCents' => $cash,
            'lines' => array_map(fn (string $name, int $q) => ['productId' => $this->ids[$name], 'quantity' => $q], array_keys($items), $items),
            'depositReturns' => $returns,
            'note' => $note,
        ];
        [$order] = self::getContainer()->get(OrderPlacement::class)->place(self::entityManager()->find(Device::class, $this->till->getId()), $payload);

        return $order;
    }
}
