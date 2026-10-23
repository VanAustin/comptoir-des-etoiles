<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PwaControllerTest extends WebTestCase
{
    public function testServiceWorkerPrecachesServiceScreensButNeverTheAdministration(): void
    {
        $client = self::createClient();
        $client->request('GET', '/sw.js');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/javascript; charset=UTF-8');
        self::assertStringContainsString('no-cache', (string) $client->getResponse()->headers->get('Cache-Control'));

        $content = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/^const VERSION = "[0-9a-f]{16}";/', $content);
        self::assertSame(1, preg_match('/^const PRECACHE = (\[.*\]);$/m', $content, $matches));
        $precache = json_decode($matches[1], true, flags: \JSON_THROW_ON_ERROR);

        foreach (['/caisse', '/cuisine', '/arrivees', '/appairer', '/demarrer', '/js/till.js', '/css/tokens.css', '/fonts/baloo-2.woff2', '/img/app/icon-192.png'] as $url) {
            self::assertContains($url, $precache);
        }
        foreach ($precache as $url) {
            self::assertDoesNotMatchRegularExpression('#^/(admin|api|login)|\.php$#', $url);
        }
        // L'API et l'administration sont explicitement exclues des requêtes interceptées
        self::assertStringContainsString('const NEVER_CACHED = ["/api/", "/admin", "/login", "/logout", "/sw.js"];', $content);
    }

    public function testManifestMakesTheApplicationInstallable(): void
    {
        $client = self::createClient();
        $client->request('GET', '/manifest.webmanifest');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/manifest+json');
        $manifest = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('/demarrer', $manifest['start_url']);
        self::assertSame('standalone', $manifest['display']);
        $sizes = array_column($manifest['icons'], 'sizes');
        self::assertContains('192x192', $sizes);
        self::assertContains('512x512', $sizes);
        foreach ($manifest['icons'] as $icon) {
            self::assertFileExists(\dirname(__DIR__, 2).'/public'.$icon['src']);
        }
    }

    public function testEveryPageLinksTheManifestAndRegistersTheServiceWorker(): void
    {
        $client = self::createClient();
        foreach (['/', '/caisse', '/cuisine', '/arrivees', '/appairer', '/demarrer'] as $url) {
            $crawler = $client->request('GET', $url);
            self::assertCount(1, $crawler->filterXPath('//link[@rel="manifest" and @href="/manifest.webmanifest"]'), $url);
            self::assertCount(1, $crawler->filterXPath('//script[@type="module" and @src="/js/sw-register.js"]'), $url);
        }
    }

    public function testStartPageSendsEachTabletToItsScreen(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/demarrer');

        self::assertResponseIsSuccessful();
        $start = $crawler->filterXPath('//*[@id="start"]');
        self::assertSame('/caisse', $start->attr('data-till-url'));
        self::assertSame('/cuisine', $start->attr('data-kitchen-url'));
        self::assertSame('/arrivees', $start->attr('data-arrivals-url'));
        self::assertSame('/appairer', $start->attr('data-pair-url'));
        self::assertCount(1, $crawler->filterXPath('//script[@type="module" and @src="/js/start.js"]'));
    }
}
