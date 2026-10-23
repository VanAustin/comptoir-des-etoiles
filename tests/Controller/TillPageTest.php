<?php

namespace App\Tests\Controller;

use App\Entity\Product;
use App\Tests\FixturesTrait;
use App\Tests\ResetDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TillPageTest extends WebTestCase
{
    use FixturesTrait;
    use ResetDatabaseTrait;

    public function testTillPageLoadsItsModuleAndStylesheet(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/caisse');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//script[@type="module" and @src="/js/till.js"]'));
        self::assertCount(1, $crawler->filterXPath('//link[@rel="stylesheet" and @href="/css/till.css"]'));
        self::assertCount(1, $crawler->filterXPath('//button[@id="till-checkout"]'));
        // « Tablette » se maintient 2 secondes : pas de lien qu'un appui accidentel suivrait
        self::assertCount(1, $crawler->filterXPath('//button[@data-hold-href="/appairer"]'));
        self::assertCount(0, $crawler->filterXPath('//nav//a[@href="/appairer"]'));
        self::assertCount(1, $crawler->filterXPath('//script[@type="module" and @src="/js/hold.js"]'));
        self::assertResponseHeaderSame('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    }

    public function testTillPageContainsNoMenuData(): void
    {
        $client = self::createClient();
        self::resetDatabase();
        $event = self::createEvent('Loto secret');
        $event->setDepositCents(100);
        self::entityManager()->persist((new Product($event, 'Crêpe confidentielle'))->setPriceCents(250));
        self::entityManager()->flush();

        $client->request('GET', '/caisse');

        // Sans jeton, rien de l'événement ni du menu : tout passe par l'API
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Loto secret', $content);
        self::assertStringNotContainsString('Crêpe confidentielle', $content);
        self::assertStringNotContainsString('2,50', $content);
    }

    public function testTillPageDoesNotShowTheAssociationLogo(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/caisse');

        self::assertCount(0, $crawler->filterXPath('//img[contains(@src, "/logo")]'));
    }
}
