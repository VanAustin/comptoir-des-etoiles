<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PairPageTest extends WebTestCase
{
    public function testPairPageIsPublicAndLoadsItsModule(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/appairer');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//form[@id="pair-form"]//input[@id="code"]'));
        self::assertCount(1, $crawler->filterXPath('//script[@type="module" and @src="/js/pair.js"]'));
        // « Continuer » mène une caisse à son écran
        self::assertCount(1, $crawler->filterXPath('//a[@id="continue" and @data-till-url="/caisse"]'));
    }

    public function testHomeLinksToPairPage(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertCount(1, $crawler->filterXPath('//a[@href="/appairer"]'));
    }
}
