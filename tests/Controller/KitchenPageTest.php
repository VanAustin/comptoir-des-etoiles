<?php

namespace App\Tests\Controller;

use App\Enum\OrderStatus;
use App\Tests\FixturesTrait;
use App\Tests\OrderFixturesTrait;
use App\Tests\ResetDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class KitchenPageTest extends WebTestCase
{
    use FixturesTrait;
    use OrderFixturesTrait;
    use ResetDatabaseTrait;

    public function testKitchenPageLoadsItsModuleAndStylesheet(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/cuisine');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//script[@type="module" and @src="/js/kitchen.js"]'));
        self::assertCount(1, $crawler->filterXPath('//link[@rel="stylesheet" and @href="/css/kitchen.css"]'));
        self::assertCount(1, $crawler->filterXPath('//div[@id="kitchen-tickets"]'));
        // « Tablette » se maintient 2 secondes : pas de lien qu'un appui accidentel suivrait
        self::assertCount(1, $crawler->filterXPath('//button[@data-hold-href="/appairer"]'));
        self::assertCount(0, $crawler->filterXPath('//nav//a[@href="/appairer"]'));
        self::assertCount(1, $crawler->filterXPath('//script[@type="module" and @src="/js/hold.js"]'));
        self::assertResponseHeaderSame('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    }

    public function testKitchenPageContainsNoOrderData(): void
    {
        $client = self::createClient();
        self::resetDatabase();
        $event = self::createEvent('Loto secret');
        $product = self::createProduct($event, 'Crêpe confidentielle', kitchen: true);
        $order = self::createOrder($event, [[$product, 1]], OrderStatus::Prep);

        $client->request('GET', '/cuisine');

        // Sans jeton, aucune commande : tout passe par l'API
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Loto secret', $content);
        self::assertStringNotContainsString('Crêpe confidentielle', $content);
        self::assertStringNotContainsString($order->getUuid(), $content);
    }

    public function testKitchenPageDoesNotShowTheAssociationLogo(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/cuisine');

        self::assertCount(0, $crawler->filterXPath('//img[contains(@src, "/logo")]'));
    }
}
