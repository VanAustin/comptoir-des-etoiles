<?php

namespace App\Tests\Controller\Admin;

use App\Entity\AdminUser;
use App\Tests\FixturesTrait;
use App\Tests\ResetDatabaseTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'en-tête de chaque page de l'administration montre l'événement actif : le menu, les
 * tablettes et le carnet de bord dépendent de lui, il ne doit jamais y avoir de doute.
 */
class ActiveEventHeaderTest extends WebTestCase
{
    use FixturesTrait;
    use ResetDatabaseTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetDatabase();

        $admin = (new AdminUser('camille'))->setPassword('inutile-ici');
        self::entityManager()->persist($admin);
        self::entityManager()->flush();
        $this->client->loginUser($admin);
    }

    /** @return iterable<string, array{string}> */
    public static function adminPages(): iterable
    {
        yield 'accueil' => ['/admin'];
        yield 'menu' => ['/admin/menu'];
        yield 'tablettes' => ['/admin/tablettes'];
        yield 'événements' => ['/admin/evenements'];
        yield 'carnet de bord' => ['/admin/carnet'];
    }

    #[DataProvider('adminPages')]
    public function testEveryAdminPageShowsTheActiveEvent(string $url): void
    {
        self::createEvent('Essai sans cuisine', '+30 days', active: false);
        self::createEvent('Loto de Noël', '2030-12-18');

        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $badge = $crawler->filterXPath('//header//a[contains(concat(" ", @class, " "), " active-event ")]');
        self::assertCount(1, $badge);
        self::assertSame('Événement actif : Loto de Noël (18/12/2030)', trim(preg_replace('/\s+/', ' ', $badge->text())));
        self::assertSame('/admin/evenements', $badge->attr('href'));
    }

    public function testWithoutActiveEventTheHeaderSaysSo(): void
    {
        self::createEvent(active: false);

        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSame('Aucun événement actif', trim($crawler->filterXPath('//header//a[contains(concat(" ", @class, " "), " active-event-none ")]')->text()));
    }
}
