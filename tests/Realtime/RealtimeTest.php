<?php

namespace App\Tests\Realtime;

use App\Entity\Event;
use App\Entity\Order;
use App\Entity\Product;
use App\Enum\DeviceRole;
use App\Enum\OrderStatus;
use App\Realtime\OrderChangePublisher;
use App\Realtime\Realtime;
use App\Realtime\RealtimeLinkListener;
use App\Tests\FixturesTrait;
use App\Tests\OrderFixturesTrait;
use App\Tests\ResetDatabaseTrait;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Mercure\HubInterface;

class RealtimeTest extends WebTestCase
{
    use FixturesTrait;
    use OrderFixturesTrait;
    use ResetDatabaseTrait;

    private KernelBrowser $client;
    private Event $event;
    private Product $crepe;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetDatabase();
        self::getContainer()->get('cache.rate_limiter')->clear();

        $this->event = self::createEvent();
        $this->crepe = self::createProduct($this->event, 'Crêpe sucre', kitchen: true);
        RecordingHub::reset();
    }

    // --- Publication : chaque changement de commande prévient les tablettes ---

    public function testNewOrderFromTillIsPublished(): void
    {
        [, $token] = self::createPairedDevice($this->event);
        RecordingHub::reset();

        $this->client->jsonRequest('POST', '/api/orders', $this->payload(), $this->auth($token));

        self::assertResponseStatusCodeSame(201);
        self::assertSame([Realtime::ordersTopic($this->event)], RecordingHub::topics());
        self::assertSame('{}', RecordingHub::$updates[0]->getData(), 'aucune donnée dans le message');
    }

    public function testKitchenStatusChangeIsPublished(): void
    {
        $order = self::createOrder($this->event, [[$this->crepe, 1]]);
        [, $token] = self::createPairedDevice($this->event, 'Cuisine', DeviceRole::Kitchen);
        RecordingHub::reset();

        $this->client->jsonRequest('POST', '/api/orders/'.$order->getUuid().'/status', ['status' => 'prep'], $this->auth($token));

        self::assertResponseIsSuccessful();
        self::assertSame([Realtime::ordersTopic($this->event)], RecordingHub::topics());
    }

    public function testServedAtTheCounterIsPublished(): void
    {
        $order = self::createOrder($this->event, [[$this->crepe, 1]], OrderStatus::Ready);
        [, $token] = self::createPairedDevice($this->event);
        RecordingHub::reset();

        $this->client->request('POST', '/api/till/orders/'.$order->getUuid().'/served', server: $this->auth($token));

        self::assertResponseIsSuccessful();
        self::assertSame([Realtime::ordersTopic($this->event)], RecordingHub::topics());
    }

    public function testAnyOrderUpdateIsPublishedOncePerFlush(): void
    {
        // Annulation depuis l'administration, ou toute autre modification future
        $first = self::createOrder($this->event, [[$this->crepe, 1]]);
        $second = self::createOrder($this->event, [[$this->crepe, 1]]);
        RecordingHub::reset();

        $em = self::entityManager();
        $em->find(Order::class, $first->getId())->setStatus(OrderStatus::Cancelled);
        $em->find(Order::class, $second->getId())->setStatus(OrderStatus::Cancelled);
        $em->flush();

        self::assertSame([Realtime::ordersTopic($this->event)], RecordingHub::topics(), 'un seul message pour le même événement');
    }

    public function testOtherChangesAreNotPublished(): void
    {
        $em = self::entityManager();
        $em->find(Product::class, $this->crepe->getId())->setPriceCents(250);
        $em->flush();

        self::assertSame([], RecordingHub::topics());
    }

    public function testUnreachableHubNeverLosesAnOrder(): void
    {
        [, $token] = self::createPairedDevice($this->event);
        RecordingHub::$unreachable = true;

        $this->client->jsonRequest('POST', '/api/orders', $this->payload(), $this->auth($token));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, self::entityManager()->getRepository(Order::class)->count([]));
    }

    public function testNothingIsPublishedWithoutHub(): void
    {
        $hub = new RecordingHub();
        $publisher = new OrderChangePublisher(new Realtime('', ''), static fn (): HubInterface => $hub, new NullLogger());
        $em = self::entityManager();
        $em->getEventManager()->addEventListener(['onFlush', 'postFlush'], $publisher);

        self::createOrder($this->event, [[$this->crepe, 1]]);

        // Le publieur du conteneur (hub configuré dans les tests) a publié, celui sans hub non
        self::assertCount(1, RecordingHub::topics());
        $em->getEventManager()->removeEventListener(['onFlush', 'postFlush'], $publisher);
    }

    // --- Découverte : où s'abonner ---

    public function testPolledRoutesTellWhereToSubscribe(): void
    {
        [, $token] = self::createPairedDevice($this->event, 'Écran', DeviceRole::Display);

        $this->client->request('GET', '/api/board', server: $this->auth($token));

        self::assertResponseIsSuccessful();
        self::assertSame([
            '<http://hub.test/.well-known/mercure>; rel="mercure"',
            '<'.Realtime::ordersTopic($this->event).'>; rel="self"',
        ], $this->client->getResponse()->headers->all('link'));
    }

    public function testNotModifiedResponseKeepsTheLinks(): void
    {
        [, $token] = self::createPairedDevice($this->event, 'Écran', DeviceRole::Display);
        $this->client->request('GET', '/api/board', server: $this->auth($token));
        $etag = $this->client->getResponse()->getEtag();

        $this->client->request('GET', '/api/board', server: $this->auth($token) + ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertResponseStatusCodeSame(304);
        self::assertCount(2, $this->client->getResponse()->headers->all('link'));
    }

    public function testNoLinksWithoutTokenOrWithoutHub(): void
    {
        $this->client->request('GET', '/api/board');
        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->client->getResponse()->headers->all('link'));

        [$device] = self::createPairedDevice($this->event, 'Écran', DeviceRole::Display);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($device);
        $response = new Response();
        $listener = new RealtimeLinkListener(new Realtime('', ''), $security);
        $listener(new ResponseEvent(self::$kernel, Request::create('/api/board'), HttpKernelInterface::MAIN_REQUEST, $response));

        self::assertSame([], $response->headers->all('link'));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'uuid' => self::uuid(),
            'number' => 1,
            'paymentMethod' => 'card',
            'lines' => [['productId' => $this->crepe->getId(), 'quantity' => 1]],
        ];
    }

    /** @return array<string, string> */
    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }
}
