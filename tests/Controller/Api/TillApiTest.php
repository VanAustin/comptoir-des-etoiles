<?php

namespace App\Tests\Controller\Api;

use App\Entity\Event;
use App\Entity\Order;
use App\Entity\Product;
use App\Enum\DeviceRole;
use App\Enum\OrderStatus;
use App\Tests\FixturesTrait;
use App\Tests\ResetDatabaseTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TillApiTest extends WebTestCase
{
    use FixturesTrait;
    use ResetDatabaseTrait;

    private KernelBrowser $client;
    private Event $event;
    private string $token;
    /** @var array<string, int> identifiant d'article par nom */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetDatabase();
        self::getContainer()->get('cache.rate_limiter')->clear();

        $this->event = self::createEvent();
        $this->event->setDepositCents(100);
        $em = self::entityManager();
        $hotdog = $this->product('Hot-dog', 300, stock: 40, kitchen: true);
        $soda = $this->product('Soda', 150, stock: 60, deposit: true);
        $this->product('Café', 100);
        $crepe = $this->product('Crêpe sucre', 200, stock: 30, kitchen: true);
        $menu = $this->product('Menu Loto', 550, deposit: true);
        $menu->addComponent($hotdog)->addComponent($soda)->addComponent($crepe);
        $em->flush();
        foreach ($em->getRepository(Product::class)->findAll() as $p) {
            $this->ids[$p->getName()] = $p->getId();
        }

        [, $this->token] = self::createPairedDevice($this->event);
    }

    // --- Droits des rôles ---

    /** @return iterable<string, array{DeviceRole}> */
    public static function otherRoles(): iterable
    {
        yield 'cuisine' => [DeviceRole::Kitchen];
        yield 'affichage' => [DeviceRole::Display];
    }

    #[DataProvider('otherRoles')]
    public function testOnlyTillsCanReadTheMenuAndCreateOrders(DeviceRole $role): void
    {
        [, $token] = self::createPairedDevice($this->event, 'Autre tablette', $role);

        $this->client->request('GET', '/api/menu', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(403);

        $this->post($this->order(), $token);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->orderCount());
    }

    public function testWithoutTokenOrdersAreRefused(): void
    {
        $this->client->jsonRequest('POST', '/api/orders', $this->order());

        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, $this->orderCount());
    }

    // --- Menu ---

    public function testMenuListsProductsWithRemainingStock(): void
    {
        $this->post($this->order(['Menu Loto' => 2, 'Soda' => 3]));
        self::assertResponseStatusCodeSame(201);

        $menu = $this->getMenu();

        self::assertSame('Loto de Noël', $menu['event']['name']);
        self::assertSame(100, $menu['event']['depositCents']);
        self::assertSame('A', $menu['tillLetter']);
        self::assertSame(1, $menu['lastNumber']);
        $byName = array_column($menu['products'], null, 'name');
        self::assertSame(38, $byName['Hot-dog']['remainingStock']);
        self::assertSame(55, $byName['Soda']['remainingStock']);
        self::assertNull($byName['Café']['remainingStock']);
        self::assertSame(28, $byName['Menu Loto']['remainingStock'], 'formule : plus petit stock de ses composants');
        self::assertSame([$this->ids['Hot-dog'], $this->ids['Soda'], $this->ids['Crêpe sucre']], $byName['Menu Loto']['components']);
        self::assertTrue($byName['Soda']['hasDeposit']);
        self::assertSame(150, $byName['Soda']['priceCents']);
    }

    public function testMenuOnlyShowsTheDeviceEvent(): void
    {
        $other = self::createEvent('Carnaval', active: false);
        self::entityManager()->persist(new Product($other, 'Beignet'));
        self::entityManager()->flush();

        self::assertNotContains('Beignet', array_column($this->getMenu()['products'], 'name'));
    }

    // --- Recalcul par le serveur ---

    public function testServerRecomputesTotalAndIgnoresTabletPrices(): void
    {
        $order = $this->order(['Hot-dog' => 2, 'Café' => 1], amount: 1000);
        $order['totalCents'] = 1;
        $order['lines'][0]['unitPriceCents'] = 1;

        $data = $this->post($order);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(700, $data['totalCents']);
        self::assertSame(1000, $data['amountReceivedCents']);
        self::assertSame(300, $data['changeCents']);
        self::assertSame('A1', $data['displayNumber']);
        self::assertSame(700, self::entityManager()->getRepository(Order::class)->findOneBy([])->getTotalCents());
    }

    public function testCardPaymentHasNoChange(): void
    {
        $data = $this->post($this->order(['Hot-dog' => 1], method: 'card', amount: 5000));

        self::assertResponseStatusCodeSame(201);
        self::assertSame('card', $data['paymentMethod']);
        self::assertNull($data['amountReceivedCents']);
        self::assertNull($data['changeCents']);
    }

    public function testExactCashGivesNoChange(): void
    {
        $data = $this->post($this->order(['Hot-dog' => 1], amount: 300));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(0, $data['changeCents']);
    }

    public function testInsufficientCashIsRefused(): void
    {
        $this->post($this->order(['Hot-dog' => 1], amount: 299));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('inférieur au total', $this->error());
        self::assertSame(0, $this->orderCount());
    }

    public function testLabelsAndPricesAreFrozen(): void
    {
        $this->post($this->order(['Hot-dog' => 1]));
        $hotdog = self::entityManager()->find(Product::class, $this->ids['Hot-dog']);
        $hotdog->setName('Hot-dog XL')->setPriceCents(400);
        self::entityManager()->flush();
        self::entityManager()->clear();

        $line = self::entityManager()->getRepository(Order::class)->findOneBy([])->getLines()->first();
        self::assertSame('Hot-dog', $line->getLabel());
        self::assertSame(300, $line->getUnitPriceCents());
    }

    // --- Consigne ---

    public function testDepositIsAddedAutomatically(): void
    {
        $data = $this->post($this->order(['Soda' => 2, 'Menu Loto' => 1, 'Café' => 1], amount: 2000));

        self::assertResponseStatusCodeSame(201);
        $deposit = $this->line($data, 'consigne');
        self::assertSame('Consigne', $deposit['label']);
        self::assertSame(3, $deposit['quantity'], '2 sodas + 1 formule avec consigne');
        self::assertSame(100, $deposit['unitPriceCents']);
        self::assertSame(300 + 550 + 100 + 300, $data['totalCents']);
    }

    public function testNoDepositLineWhenEventHasNoDeposit(): void
    {
        $this->setDeposit(0);

        $data = $this->post($this->order(['Soda' => 2]));

        self::assertNull($this->line($data, 'consigne'));
        self::assertSame(300, $data['totalCents']);
    }

    public function testDepositReturnCombinedWithPurchase(): void
    {
        $data = $this->post($this->order(['Hot-dog' => 1], returns: 2, method: 'card'));

        self::assertResponseStatusCodeSame(201);
        $return = $this->line($data, 'retour_consigne');
        self::assertSame(-100, $return['unitPriceCents']);
        self::assertSame(-200, $return['totalCents']);
        self::assertSame(100, $data['totalCents']);
    }

    public function testDepositReturnAloneGivesCashBack(): void
    {
        $data = $this->post($this->order([], returns: 3, amount: null));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(-300, $data['totalCents']);
        self::assertSame(0, $data['amountReceivedCents']);
        self::assertSame(300, $data['changeCents'], 'somme à rendre au client');
        self::assertSame('served', $data['status']);
    }

    public function testNegativeTotalCannotBePaidByCard(): void
    {
        $this->post($this->order([], returns: 1, method: 'card'));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('espèces', $this->error());
    }

    public function testDepositReturnWithoutDepositIsRefused(): void
    {
        $this->setDeposit(0);

        $this->post($this->order(['Café' => 1], returns: 1));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('pas de consigne', $this->error());
    }

    // --- État ---

    public function testOrderWithKitchenItemStartsAsNew(): void
    {
        self::assertSame('new', $this->post($this->order(['Hot-dog' => 1, 'Café' => 1]))['status']);
    }

    public function testOrderWithoutKitchenItemIsServedImmediately(): void
    {
        self::assertSame('served', $this->post($this->order(['Café' => 2]))['status']);
    }

    // --- Idempotence ---

    public function testSameUuidDoesNotCreateADuplicate(): void
    {
        $order = $this->order(['Hot-dog' => 1]);
        $first = $this->post($order);
        self::assertResponseStatusCodeSame(201);

        // Même UUID, contenu différent : la commande d'origine est renvoyée telle quelle
        $order['lines'] = [['productId' => $this->ids['Café'], 'quantity' => 5]];
        $second = $this->post($order);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($first, $second);
        self::assertSame(1, $this->orderCount());
    }

    public function testUuidOfAnotherDeviceIsRefused(): void
    {
        $order = $this->order(['Hot-dog' => 1]);
        $this->post($order);
        [, $otherToken] = self::createPairedDevice($this->event, 'Caisse 2');

        $this->post($order, $otherToken);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->orderCount());
    }

    // --- Numérotation ---

    public function testLetterComesFromTheTokenNotTheRequest(): void
    {
        [, $otherToken] = self::createPairedDevice($this->event, 'Caisse 2');
        $order = $this->order(['Café' => 1]);
        $order['tillLetter'] = 'A';

        $data = $this->post($order, $otherToken);

        self::assertSame('B1', $data['displayNumber']);
    }

    public function testEachTillNumbersIndependently(): void
    {
        [, $otherToken] = self::createPairedDevice($this->event, 'Caisse 2');

        $this->post($this->order(['Café' => 1], number: 1));
        self::assertResponseStatusCodeSame(201);
        $this->post($this->order(['Café' => 1], number: 1), $otherToken);
        self::assertResponseStatusCodeSame(201);
    }

    public function testTakenNumberReturnsTheNextFreeOne(): void
    {
        $this->post($this->order(['Café' => 1], number: 1));
        $this->post($this->order(['Café' => 1], number: 7));

        $this->post($this->order(['Café' => 1], number: 1));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(8, $this->json()['nextNumber']);
        self::assertSame(2, $this->orderCount());
        self::assertSame(7, $this->getMenu()['lastNumber']);
    }

    public function testNumberingRestartsForEachEvent(): void
    {
        $this->post($this->order(['Café' => 1], number: 1));

        $next = self::createEvent('Carnaval');
        $carnival = self::entityManager()->find(Event::class, $next->getId());
        self::entityManager()->find(Event::class, $this->event->getId())->setActive(false);
        self::entityManager()->flush();
        $carnival->setDepositCents(0);
        self::entityManager()->persist((new Product($carnival, 'Beignet'))->setPriceCents(150));
        self::entityManager()->flush();
        [, $token] = self::createPairedDevice($carnival);

        self::assertSame(0, $this->getMenu($token)['lastNumber']);
        $beignet = $this->getMenu($token)['products'][0]['id'];
        $data = $this->post(['lines' => [['productId' => $beignet, 'quantity' => 1]]] + $this->order(number: 1), $token);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('A1', $data['displayNumber']);
    }

    // --- Stock : information, jamais une limite ---

    public function testStockNeverBlocksASale(): void
    {
        $data = $this->post($this->order(['Crêpe sucre' => 31], amount: 10000));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(-1, $this->stockOf('Crêpe sucre'));
        self::assertSame(-1, $this->stockOf('Menu Loto'));
        self::assertSame('new', $data['status']);
    }

    public function testCancelledOrdersGiveStockBack(): void
    {
        $this->post($this->order(['Hot-dog' => 5]));
        $order = self::entityManager()->getRepository(Order::class)->findOneBy([]);
        $order->setStatus(OrderStatus::Cancelled);
        self::entityManager()->flush();

        self::assertSame(40, $this->stockOf('Hot-dog'));
    }

    public function testFormulaSoldBeforeAChangeKeepsItsComponents(): void
    {
        $this->post($this->order(['Menu Loto' => 1]));

        // La formule perd la crêpe après la vente : la crêpe vendue reste décomptée
        $em = self::entityManager();
        $menu = $em->find(Product::class, $this->ids['Menu Loto']);
        $menu->removeComponent($em->find(Product::class, $this->ids['Crêpe sucre']));
        $em->flush();

        self::assertSame(29, $this->stockOf('Crêpe sucre'));
        self::assertSame(39, $this->stockOf('Hot-dog'));
    }

    public function testRemovedProductKeepsItsSalesInHistory(): void
    {
        $this->post($this->order(['Hot-dog' => 1]));
        $em = self::entityManager();
        $em->remove($em->find(Product::class, $this->ids['Hot-dog']));
        $em->flush();
        $em->clear();

        $line = $em->getRepository(Order::class)->findOneBy([])->getLines()->first();
        self::assertNull($line->getProduct());
        self::assertSame('Hot-dog', $line->getLabel());
    }

    // --- Heure de la commande ---

    public function testTabletTimeIsKeptWhenPlausible(): void
    {
        $at = (new \DateTimeImmutable('-20 minutes'))->setTime((int) date('H', strtotime('-20 minutes')), (int) date('i', strtotime('-20 minutes')), 0);
        $data = $this->post($this->order(['Café' => 1], createdAt: $at->format('Y-m-d\TH:i:s.000P')));

        self::assertSame($at->getTimestamp(), (new \DateTimeImmutable($data['createdAt']))->getTimestamp());
    }

    public function testImplausibleTabletTimeIsReplaced(): void
    {
        foreach (['2020-01-01T10:00:00Z', (new \DateTimeImmutable('+1 hour'))->format(\DATE_ATOM), 'hier'] as $i => $createdAt) {
            $data = $this->post($this->order(['Café' => 1], number: $i + 1, createdAt: $createdAt));
            self::assertResponseStatusCodeSame(201);
            self::assertEqualsWithDelta(time(), (new \DateTimeImmutable($data['createdAt']))->getTimestamp(), 5, $createdAt);
        }
    }

    // --- Validation ---

    /** @return iterable<string, array{callable(array<string, mixed>, array<string, int>): array<string, mixed>, string}> */
    public static function invalidOrders(): iterable
    {
        yield 'UUID absent' => [static fn (array $o) => array_diff_key($o, ['uuid' => 1]), 'Identifiant'];
        yield 'UUID mal formé' => [static fn (array $o) => ['uuid' => 'pas-un-uuid'] + $o, 'Identifiant'];
        yield 'UUID en majuscules' => [static fn (array $o) => ['uuid' => strtoupper($o['uuid'])] + $o, 'Identifiant'];
        yield 'numéro absent' => [static fn (array $o) => array_diff_key($o, ['number' => 1]), 'Numéro'];
        yield 'numéro nul' => [static fn (array $o) => ['number' => 0] + $o, 'Numéro'];
        yield 'numéro texte' => [static fn (array $o) => ['number' => '3'] + $o, 'Numéro'];
        yield 'numéro trop grand' => [static fn (array $o) => ['number' => 100000] + $o, 'Numéro'];
        yield 'paiement inconnu' => [static fn (array $o) => ['paymentMethod' => 'cheque'] + $o, 'moyen de paiement'];
        yield 'commande vide' => [static fn (array $o) => ['lines' => []] + $o, 'vide'];
        yield 'lignes pas une liste' => [static fn (array $o) => ['lines' => ['a' => 1]] + $o, 'au plus'];
        yield 'trop de lignes' => [static fn (array $o) => ['lines' => array_map(static fn (int $i) => ['productId' => $i, 'quantity' => 1], range(1, 51))] + $o, 'au plus 50'];
        yield 'quantité nulle' => [static fn (array $o, array $ids) => ['lines' => [['productId' => $ids['Café'], 'quantity' => 0]]] + $o, 'entre 1 et 99'];
        yield 'quantité 100' => [static fn (array $o, array $ids) => ['lines' => [['productId' => $ids['Café'], 'quantity' => 100]]] + $o, 'entre 1 et 99'];
        yield 'quantité décimale' => [static fn (array $o, array $ids) => ['lines' => [['productId' => $ids['Café'], 'quantity' => 1.5]]] + $o, 'invalide'];
        yield 'quantité texte' => [static fn (array $o, array $ids) => ['lines' => [['productId' => $ids['Café'], 'quantity' => '2']]] + $o, 'invalide'];
        yield 'article en double' => [static fn (array $o, array $ids) => ['lines' => [['productId' => $ids['Café'], 'quantity' => 1], ['productId' => $ids['Café'], 'quantity' => 1]]] + $o, 'deux fois'];
        yield 'article inconnu' => [static fn (array $o) => ['lines' => [['productId' => 999999, 'quantity' => 1]]] + $o, 'plus au menu'];
        yield 'retours négatifs' => [static fn (array $o) => ['depositReturns' => -1] + $o, 'gobelets'];
        yield 'retours 100' => [static fn (array $o) => ['depositReturns' => 100] + $o, 'gobelets'];
        yield 'montant reçu absent' => [static fn (array $o) => array_diff_key($o, ['amountReceivedCents' => 1]), 'montant reçu'];
        yield 'montant reçu négatif' => [static fn (array $o) => ['amountReceivedCents' => -5] + $o, 'montant reçu'];
        yield 'montant reçu décimal' => [static fn (array $o) => ['amountReceivedCents' => 10.5] + $o, 'montant reçu'];
        yield 'montant reçu démesuré' => [static fn (array $o) => ['amountReceivedCents' => 100001] + $o, 'montant reçu'];
    }

    /** @param callable(array<string, mixed>, array<string, int>): array<string, mixed> $change */
    #[DataProvider('invalidOrders')]
    public function testInvalidOrderIsRefused(callable $change, string $message): void
    {
        $this->post($change($this->order(['Café' => 1]), $this->ids));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $this->error());
        self::assertSame(0, $this->orderCount());
    }

    public function testProductOfAnotherEventIsRefused(): void
    {
        $other = self::createEvent('Carnaval', active: false);
        $beignet = new Product($other, 'Beignet');
        self::entityManager()->persist($beignet);
        self::entityManager()->flush();

        $this->post(['lines' => [['productId' => $beignet->getId(), 'quantity' => 1]]] + $this->order());

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->orderCount());
    }

    public function testUnreadableBodyIsRefused(): void
    {
        $this->client->request('POST', '/api/orders', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'CONTENT_TYPE' => 'application/json',
        ], content: '{pas du json');

        self::assertResponseStatusCodeSame(400);
    }

    // --- Outils ---

    private function product(string $name, int $price, ?int $stock = null, bool $kitchen = false, bool $deposit = false): Product
    {
        $product = (new Product($this->event, $name))
            ->setPriceCents($price)
            ->setInitialStock($stock)
            ->setGoesToKitchen($kitchen)
            ->setHasDeposit($deposit);
        self::entityManager()->persist($product);

        return $product;
    }

    /**
     * @param array<string, int> $items quantité par nom d'article
     *
     * @return array<string, mixed>
     */
    private function order(array $items = ['Café' => 1], int $number = 1, string $method = 'cash', ?int $amount = 5000, int $returns = 0, ?string $createdAt = null): array
    {
        $order = [
            'uuid' => sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFF), random_int(0, 0xFFF), random_int(0, 0xFFFFFFFFFFFF)),
            'number' => $number,
            'paymentMethod' => $method,
            'lines' => array_map(fn (string $name, int $qty) => ['productId' => $this->ids[$name] ?? 0, 'quantity' => $qty], array_keys($items), $items),
            'depositReturns' => $returns,
            'createdAt' => $createdAt ?? (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];
        if (null !== $amount) {
            $order['amountReceivedCents'] = $amount;
        }

        return $order;
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function post(array $order, ?string $token = null): array
    {
        $this->client->jsonRequest('POST', '/api/orders', $order, ['HTTP_AUTHORIZATION' => 'Bearer '.($token ?? $this->token)]);

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function getMenu(?string $token = null): array
    {
        $this->client->request('GET', '/api/menu', server: ['HTTP_AUTHORIZATION' => 'Bearer '.($token ?? $this->token)]);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    private function stockOf(string $name): ?int
    {
        return array_column($this->getMenu()['products'], 'remainingStock', 'name')[$name];
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>|null
     */
    private function line(array $order, string $kind): ?array
    {
        foreach ($order['lines'] as $line) {
            if ($kind === $line['kind']) {
                return $line;
            }
        }

        return null;
    }

    private function setDeposit(int $cents): void
    {
        self::entityManager()->find(Event::class, $this->event->getId())->setDepositCents($cents);
        self::entityManager()->flush();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function error(): string
    {
        return $this->json()['error'] ?? '';
    }

    private function orderCount(): int
    {
        return self::entityManager()->getRepository(Order::class)->count([]);
    }
}
