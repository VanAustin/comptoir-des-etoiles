<?php

namespace App\Tests\Controller\Admin;

use App\Entity\AdminUser;
use App\Tests\ResetDatabaseTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TableTentsControllerTest extends WebTestCase
{
    use ResetDatabaseTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetDatabase();
    }

    public function testReservedToTheAdministration(): void
    {
        $this->client->request('GET', '/admin/chevalets');

        self::assertResponseRedirects('/login');
    }

    public function testFormAloneUntilSubmitted(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/admin/chevalets');

        self::assertResponseIsSuccessful();
        self::assertSame('8', $crawler->filterXPath('//input[@name="rangees"]')->attr('value'));
        self::assertSame(0, $crawler->filterXPath('//*[contains(@class, "tent-sheet")]')->count());
        self::assertSame(1, $crawler->filterXPath('//link[contains(@href, "/css/chevalets.css")]')->count());
    }

    public function testTentsTwoPerSheetEachNumberOnBothFaces(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/admin/chevalets', ['rangees' => '2', 'tables' => '3']);

        self::assertResponseIsSuccessful();
        $sheets = $crawler->filterXPath('//*[contains(@class, "tent-sheet")]');
        self::assertSame(3, $sheets->count(), '6 chevalets, 2 par feuille');
        $numbers = $crawler->filterXPath('//*[contains(@class, "tent-face-front")]/*[@class="tent-number"]')->each(static fn ($n) => $n->text());
        self::assertSame(['101', '102', '103', '201', '202', '203'], $numbers);
        self::assertSame($numbers, $crawler->filterXPath('//*[contains(@class, "tent-face-back")]/*[@class="tent-number"]')->each(static fn ($n) => $n->text()));
        self::assertStringContainsString('Servi à table : donnez ce numéro au comptoir', $sheets->first()->text());
        self::assertSame(3, $crawler->filterXPath('//*[@class="tent-cut"]')->count(), 'une ligne de coupe par feuille complète');
        self::assertSame(1, $crawler->filterXPath('//button[@id="tents-print"]')->count());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidValues(): iterable
    {
        yield 'aucune rangée' => ['0', '8', 'rangées'];
        yield 'dix rangées' => ['10', '8', 'rangées'];
        yield 'cent tables' => ['8', '100', 'tables'];
        yield 'texte' => ['huit', '8', 'rangées'];
        yield 'décimal' => ['8', '2,5', 'tables'];
        yield 'vide' => ['8', '', 'tables'];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRefused(string $rows, string $tables, string $field): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/admin/chevalets', ['rangees' => $rows, 'tables' => $tables]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('nombre de '.$field, $crawler->filterXPath('//*[@class="field-error"]')->text());
        self::assertSame(0, $crawler->filterXPath('//*[contains(@class, "tent-sheet")]')->count());
    }

    public function testAdminHomeLinksToTents(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/admin');

        self::assertSame(1, $crawler->filterXPath('//a[@href="/admin/chevalets"]')->count());
    }

    private function login(): void
    {
        $admin = (new AdminUser('camille'))->setPassword('inutile-ici');
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->persist($admin);
        $entityManager->flush();
        $this->client->loginUser($admin);
    }
}
