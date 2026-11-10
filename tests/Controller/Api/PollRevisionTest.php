<?php

namespace App\Tests\Controller\Api;

use App\Entity\Event;
use App\Entity\Product;
use App\Enum\DeviceRole;
use App\Enum\OrderStatus;
use App\Service\Revisions;
use App\Tests\FixturesTrait;
use App\Tests\OrderFixturesTrait;
use App\Tests\ResetDatabaseTrait;
use App\Tests\RevisionsTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Interrogations des tablettes (cuisine, Arrivées, suivi et menu de la caisse) : tant que la
 * révision n'a pas changé, une seule lecture SQL (la tablette et son événement, pour
 * l'authentification) et une réponse 304 vide. Les commandes ne sont jamais relues.
 */
class PollRevisionTest extends WebTestCase
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
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->event = self::createEvent();
        $this->crepe = self::createProduct($this->event, 'Crêpe', kitchen: true);
    }

    /** @return iterable<string, array{DeviceRole, string}> */
    public static function polledRoutes(): iterable
    {
        yield 'cuisine' => [DeviceRole::Kitchen, '/api/orders'];
        yield 'Arrivées' => [DeviceRole::Display, '/api/board'];
        yield 'suivi de la caisse' => [DeviceRole::Till, '/api/till/orders'];
        yield 'menu de la caisse' => [DeviceRole::Till, '/api/menu'];
    }

    #[DataProvider('polledRoutes')]
    public function testNotModifiedCostsASingleReadAndNeverTouchesOrders(DeviceRole $role, string $url): void
    {
        self::createOrder($this->event, [[$this->crepe, 2]]);
        [, $token] = self::createPairedDevice($this->event, 'Tablette', $role);
        $etag = $this->poll($url, $token);
        self::assertResponseStatusCodeSame(200);

        $queries = $this->queriesOf(fn () => $this->poll($url, $token, $etag));

        self::assertResponseStatusCodeSame(304);
        self::assertCount(1, $queries, implode("\n", $queries));
        // La seule lecture : la tablette par son jeton, avec son événement (révisions comprises)
        preg_match_all('/\b(?:FROM|JOIN)\s+`?(\w+)/i', $queries[0], $tables);
        self::assertSame(['device', 'event'], $tables[1]);
        self::assertStringContainsString('orders_revision', $queries[0]);
    }

    #[DataProvider('polledRoutes')]
    public function testNotModifiedIsEmptyPrivateAndWithoutCookie(DeviceRole $role, string $url): void
    {
        [, $token] = self::createPairedDevice($this->event, 'Tablette', $role);
        $etag = $this->poll($url, $token);
        $first = $this->client->getResponse();
        self::assertSame('no-cache, private', $first->headers->get('Cache-Control'));
        self::assertMatchesRegularExpression('/^"e\d+-o\d+-m\d*-v\d+"$/', $etag);

        $this->poll($url, $token, $etag);

        $response = $this->client->getResponse();
        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', (string) $response->getContent());
        self::assertSame('no-cache, private', $response->headers->get('Cache-Control'));
        self::assertSame($etag, $response->getEtag());
        // Heure du serveur pour les temps d'attente, aussi sur une 304
        self::assertNotNull($response->headers->get('Date'));
        // Pare-feu /api sans session : jamais de cookie, ni sur la 200 ni sur la 304
        self::assertSame([], $first->headers->getCookies());
        self::assertSame([], $response->headers->getCookies());
    }

    public function testApacheGzipSuffixStillMatches(): void
    {
        [, $token] = self::createPairedDevice($this->event, 'Cuisine', DeviceRole::Kitchen);
        $etag = $this->poll('/api/orders', $token);

        // mod_deflate renvoie l'empreinte suffixée par « -gzip », la tablette la renvoie telle quelle
        $this->poll('/api/orders', $token, substr($etag, 0, -1).'-gzip"');

        self::assertResponseStatusCodeSame(304);
    }

    public function testEachScreenReloadsOnlyWhenWhatItShowsMayHaveChanged(): void
    {
        [, $kitchen] = self::createPairedDevice($this->event, 'Cuisine', DeviceRole::Kitchen);
        [, $board] = self::createPairedDevice($this->event, 'Arrivées', DeviceRole::Display);
        [, $till] = self::createPairedDevice($this->event, 'Caisse', DeviceRole::Till);
        $routes = ['/api/orders' => $kitchen, '/api/board' => $board, '/api/till/orders' => $till, '/api/menu' => $till];
        $etags = [];
        foreach ($routes as $url => $token) {
            $etags[$url] = $this->poll($url, $token);
        }

        // Le menu change (prix, nom…) : la cuisine (noms des articles) et le menu de la caisse
        self::entityManager()->find(Product::class, $this->crepe->getId())->setName('Crêpe au sucre');
        self::revisions()->flushMenu($this->event);
        self::assertSame(
            ['/api/orders' => 200, '/api/board' => 304, '/api/till/orders' => 304, '/api/menu' => 200],
            $this->statuses($routes, $etags),
        );

        // Une commande arrive : tous les écrans, menu compris (stock restant, dernier numéro)
        foreach ($routes as $url => $token) {
            $etags[$url] = $this->poll($url, $token);
        }
        self::createOrder($this->event, [[$this->crepe, 1]]);
        self::assertSame(
            ['/api/orders' => 200, '/api/board' => 200, '/api/till/orders' => 200, '/api/menu' => 200],
            $this->statuses($routes, $etags),
        );
    }

    public function testOrdersArrivingTogetherEachCount(): void
    {
        $before = self::revisionsOf($this->event->getId());
        $revisions = self::getContainer()->get(Revisions::class);

        // Augmentation par la base (« + 1 » en SQL) : jamais deux fois la même révision
        $revisions->flushOrders($this->event);
        $revisions->flushOrders($this->event);

        self::assertRevisionsMoved($before, 2, 0, $this->event->getId());
    }

    public function testFailedWriteLeavesTheRevisionUnchanged(): void
    {
        $order = self::createOrder($this->event, [[$this->crepe, 1]]);
        $before = self::revisionsOf($this->event->getId());

        try {
            self::revisions()->flushOrders($this->event, static function () use ($order): void {
                $order->setStatus(OrderStatus::Prep);
                throw new \RuntimeException('coupure pendant l\'écriture');
            });
            self::fail('L\'erreur doit remonter.');
        } catch (\RuntimeException $e) {
            self::assertSame('coupure pendant l\'écriture', $e->getMessage());
        }

        // Transaction annulée : ni l'écriture ni la révision
        self::assertRevisionsMoved($before, 0, 0, $this->event->getId());
        $status = self::getContainer()->get('doctrine')->getConnection()->fetchOne('SELECT status FROM orders WHERE id = ?', [$order->getId()]);
        self::assertSame('new', $status);
    }

    /** Interroge une route ; renvoie l'empreinte reçue (celle envoyée sur une 304). */
    private function poll(string $url, string $token, ?string $etag = null): string
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        if (null !== $etag) {
            $server['HTTP_IF_NONE_MATCH'] = $etag;
        }
        $this->client->request('GET', $url, server: $server);

        return (string) ($this->client->getResponse()->getEtag() ?? $etag);
    }

    /**
     * @param array<string, string> $routes jeton par route
     * @param array<string, string> $etags
     *
     * @return array<string, int>
     */
    private function statuses(array $routes, array $etags): array
    {
        $statuses = [];
        foreach ($routes as $url => $token) {
            $this->poll($url, $token, $etags[$url]);
            $statuses[$url] = $this->client->getResponse()->getStatusCode();
        }

        return $statuses;
    }

    /**
     * Requêtes SQL envoyées pendant une requête HTTP (collecteur de Doctrine, environnement de test).
     *
     * @return list<string>
     */
    private function queriesOf(\Closure $request): array
    {
        $request();

        return array_values(array_map(
            static fn (array $query): string => $query['sql'],
            self::getContainer()->get('doctrine.debug_data_holder')->getData()['default'] ?? [],
        ));
    }
}
