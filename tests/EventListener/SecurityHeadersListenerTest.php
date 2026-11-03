<?php

namespace App\Tests\EventListener;

use App\EventListener\SecurityHeadersListener;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SecurityHeadersListenerTest extends WebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function urls(): iterable
    {
        yield 'page' => ['/'];
        yield 'formulaire' => ['/login'];
        yield 'redirection' => ['/admin'];
        yield 'fichier' => ['/logo'];
        yield 'page introuvable' => ['/cette-page-n-existe-pas'];
    }

    /**
     * Sans debug, comme en production : en debug, Symfony retire volontairement
     * la CSP de ses pages d'erreur détaillées.
     */
    #[DataProvider('urls')]
    public function testHeadersAreSentOnEveryResponse(string $url): void
    {
        $client = self::createClient(['debug' => false]);
        $client->request('GET', $url);

        self::assertResponseHeaderSame('Content-Security-Policy', SecurityHeadersListener::CONTENT_SECURITY_POLICY);
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        self::assertResponseHeaderSame('Referrer-Policy', 'same-origin');
        self::assertResponseHeaderSame('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function testHstsOnlyOverHttps(): void
    {
        $client = self::createClient();

        $client->request('GET', '/');
        self::assertResponseNotHasHeader('Strict-Transport-Security');

        $client->request('GET', '/', server: ['HTTPS' => 'on']);
        self::assertResponseHeaderSame('Strict-Transport-Security', 'max-age=31536000');
    }

    public function testProductionErrorPageShowsNoTechnicalDetail(): void
    {
        $client = self::createClient(['debug' => false]);
        $client->request('GET', '/cette-page-n-existe-pas');

        self::assertResponseStatusCodeSame(404);
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Page introuvable', $content);
        self::assertStringNotContainsString('NotFoundHttpException', $content);
        self::assertStringNotContainsString('No route found', $content);
    }
}
