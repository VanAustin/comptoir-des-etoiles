<?php

namespace App\Service;

use App\Entity\Device;
use App\Entity\Order;
use App\Entity\OrderLine;
use App\Entity\Product;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enregistre une commande envoyée par une caisse.
 *
 * Le serveur ne fait pas confiance à la tablette : il ne lit que les articles,
 * les quantités, le paiement et le numéro, et recalcule tout le reste à partir
 * de la base (prix, consigne, total, monnaie). Le stock ne bloque jamais une
 * vente : c'est une information, pas une limite.
 */
class OrderPlacement
{
    public const MAX_LINES = 50;
    public const MAX_QUANTITY = 99;
    public const MAX_NUMBER = 99999;
    /** Montant reçu en espèces : 1 000 € au plus. */
    public const MAX_RECEIVED_CENTS = 100000;

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registry,
        private readonly OrderRepository $orders,
        private readonly ProductRepository $products,
        private readonly Revisions $revisions,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @param array<mixed> $payload corps JSON de la requête
     *
     * @return array{Order, bool} la commande, et true si elle vient d'être créée (false : déjà connue)
     *
     * @throws OrderRejectedException
     */
    public function place(Device $device, array $payload): array
    {
        $uuid = $payload['uuid'] ?? null;
        if (!\is_string($uuid) || !preg_match(self::UUID_PATTERN, $uuid)) {
            throw new OrderRejectedException('Identifiant de commande invalide.');
        }

        // Idempotence : une commande renvoyée (réseau coupé avant la réponse) n'est pas recréée
        $existing = $this->orders->findOneByUuid($uuid);
        if (null !== $existing) {
            return [$this->sameDeviceOrFail($existing, $device), false];
        }

        $event = $device->getEvent();
        $letter = $device->getTillLetter();
        if (null === $letter) {
            throw new OrderRejectedException('Cette tablette n\'a pas de lettre de caisse.', Response::HTTP_FORBIDDEN);
        }

        $number = $payload['number'] ?? null;
        if (!\is_int($number) || $number < 1 || $number > self::MAX_NUMBER) {
            throw new OrderRejectedException('Numéro de commande invalide.');
        }

        $method = \is_string($payload['paymentMethod'] ?? null) ? PaymentMethod::tryFrom($payload['paymentMethod']) : null;
        if (null === $method) {
            throw new OrderRejectedException('Choisissez le moyen de paiement : espèces ou carte.');
        }

        $quantities = $this->readLines($payload['lines'] ?? []);
        $returns = $payload['depositReturns'] ?? 0;
        if (!\is_int($returns) || $returns < 0 || $returns > self::MAX_QUANTITY) {
            throw new OrderRejectedException('Nombre de gobelets rendus invalide (de 0 à 99).');
        }
        if ($returns > 0 && 0 === $event->getDepositCents()) {
            throw new OrderRejectedException('Il n\'y a pas de consigne pour cet événement.');
        }
        if ([] === $quantities && 0 === $returns) {
            throw new OrderRejectedException('La commande est vide.');
        }
        $note = self::readNote($payload['note'] ?? null);

        $catalog = [];
        // Articles retirés compris : une commande encaissée hors ligne avant le retrait reste acceptée
        foreach ($this->products->findAllForEvent($event) as $product) {
            $catalog[$product->getId()] = $product;
        }

        // Commande encaissée hors ligne et envoyée plus tard : l'argent est déjà dans la caisse
        $offline = true === ($payload['offline'] ?? false);
        $rawTable = $payload['tableNumber'] ?? null;
        $table = self::readTable($rawTable, $offline);

        $order = new Order($uuid, $event, $letter, $number, $method);
        $order->setDevice($device);
        $order->setCreatedAt($this->readCreatedAt($payload['createdAt'] ?? null));

        $depositCount = 0;
        $kitchen = false;
        /** @var list<Product> $notSoldHere articles d'une catégorie que cette caisse n'affiche pas */
        $notSoldHere = [];
        foreach ($quantities as $productId => $quantity) {
            $product = $catalog[$productId] ?? null;
            if (!$product instanceof Product) {
                throw new OrderRejectedException('Un article de la commande n\'est plus au menu. Rechargez le menu, puis encaissez à nouveau.');
            }
            if (!$device->sells($product)) {
                $notSoldHere[] = $product;
            }
            OrderLine::article($order, $product, $quantity);
            // Événement sans cuisine : tout est servi au comptoir, quel que soit l'article
            $kitchen = $kitchen || ($event->hasKitchen() && $product->goesToKitchen());
            if ($product->hasDeposit()) {
                $depositCount += $quantity;
            }
        }

        if ([] !== $notSoldHere) {
            $this->checkCategories($order, $device, $notSoldHere, $offline);
        }

        $deposit = $event->getDepositCents();
        if ($deposit > 0 && $depositCount > 0) {
            OrderLine::deposit($order, $deposit, $depositCount);
        }
        if ($returns > 0) {
            OrderLine::depositReturn($order, $deposit, $returns);
        }

        $total = array_sum(array_map(static fn (OrderLine $l): int => $l->getTotalCents(), $order->getLines()->toArray()));
        $order->setTotalCents($total);
        $order->setAmountReceivedCents($this->readPayment($method, $total, $payload['amountReceivedCents'] ?? null, $offline));
        $order->setStatus($kitchen ? OrderStatus::New : OrderStatus::Served);
        // La remarque n'a de sens que pour la cuisine
        $order->setNote($kitchen ? $note : null);
        // Boissons seules : remises au comptoir, ce n'est pas une commande à table. Le réglage
        // « Service à table » n'est pas vérifié : il ne fait qu'afficher le bouton de la caisse,
        // et une commande encaissée avant qu'il soit décoché garde sa table.
        $order->setTableNumber($kitchen ? $table : null);
        if (null !== $rawTable && null === $table) {
            // Hors ligne : commande gardée sans table, valeur reçue tracée pour la retrouver après coup
            $this->auditLogger->log('order.table_rejected', 'order:'.$uuid.' '.$order->getDisplayNumber(), before: ['tableNumber' => self::describeRejected($rawTable)], actor: $device);
        }

        if ($this->orders->numberExists($event, $letter, $number)) {
            throw $this->numberTaken($this->orders, $device);
        }

        $this->em->persist($order);
        try {
            $this->revisions->flushOrders($event);
        } catch (UniqueConstraintViolationException) {
            // Deux envois simultanés : la base a tranché. Le gestionnaire d'entités
            // est fermé après l'erreur, on relit l'état avec un gestionnaire neuf.
            $this->registry->resetManager();
            /** @var OrderRepository $orders */
            $orders = $this->registry->getRepository(Order::class);
            $existing = $orders->findOneByUuid($uuid);
            if (null !== $existing) {
                return [$this->sameDeviceOrFail($existing, $device), false];
            }
            throw $this->numberTaken($orders, $device);
        }

        return [$order, true];
    }

    /**
     * Article d'une catégorie que cette caisse n'affiche pas : la vérification ne se fait pas
     * seulement dans l'écran.
     *
     * - En ligne : commande refusée (422), et la tentative tracée. Le journal est écrit tout de
     *   suite, à part : la commande refusée n'est jamais enregistrée.
     * - Hors ligne : l'argent est déjà dans la caisse (réglage changé après l'encaissement) :
     *   commande acceptée, jamais perdue, et tracée pour la retrouver après coup.
     *
     * @param list<Product> $products
     *
     * @throws OrderRejectedException
     */
    private function checkCategories(Order $order, Device $device, array $products, bool $offline): void
    {
        $subject = 'order:'.$order->getUuid().' '.$order->getDisplayNumber();
        $details = [
            'articles' => array_map(static fn (Product $p): string => $p->getName(), $products),
            'categories' => array_values(array_unique(array_map(static fn (Product $p): string => $p->getCategory(), $products))),
            'allowed' => $device->getCategories(),
        ];

        if ($offline) {
            $this->auditLogger->log('order.category_offline', $subject, after: $details, actor: $device);

            return;
        }

        $this->auditLogger->log('order.category_rejected', $subject, after: $details, actor: $device);
        // Seul le journal est géré ici : la commande et ses lignes ne sont pas persistées
        $this->em->flush();

        throw new OrderRejectedException(\sprintf('Cette caisse ne vend pas « %s ». Rechargez le menu, puis encaissez à nouveau.', $products[0]->getName()), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @return array<int, int> quantité par identifiant d'article
     */
    private function readLines(mixed $lines): array
    {
        if (!\is_array($lines) || !array_is_list($lines) || \count($lines) > self::MAX_LINES) {
            throw new OrderRejectedException(\sprintf('La commande doit contenir au plus %d articles différents.', self::MAX_LINES));
        }

        $quantities = [];
        foreach ($lines as $line) {
            $productId = \is_array($line) ? ($line['productId'] ?? null) : null;
            $quantity = \is_array($line) ? ($line['quantity'] ?? null) : null;
            if (!\is_int($productId) || !\is_int($quantity)) {
                throw new OrderRejectedException('Ligne de commande invalide.');
            }
            if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
                throw new OrderRejectedException('La quantité d\'un article doit être comprise entre 1 et 99.');
            }
            if (isset($quantities[$productId])) {
                throw new OrderRejectedException('Un même article apparaît deux fois dans la commande.');
            }
            $quantities[$productId] = $quantity;
        }

        return $quantities;
    }

    /**
     * @param bool $offline encaissée hors ligne : un montant reçu inférieur au total recalculé
     *                      (prix modifié entre-temps) est accepté et apparaît comme un écart de caisse,
     *                      plutôt que de perdre une commande déjà payée
     *
     * @return int|null montant reçu à enregistrer
     */
    private function readPayment(PaymentMethod $method, int $total, mixed $received, bool $offline = false): ?int
    {
        if (PaymentMethod::Card === $method) {
            if ($total <= 0) {
                throw new OrderRejectedException('Un total nul ou négatif (retour de consigne) se règle en espèces.');
            }

            return null;
        }

        // Total négatif (retour de consigne seul) : rien à recevoir, tout à rendre
        $received ??= $total <= 0 ? 0 : null;
        if (!\is_int($received) || $received < 0 || $received > self::MAX_RECEIVED_CENTS) {
            throw new OrderRejectedException('Indiquez le montant reçu en espèces.');
        }
        if ($received < $total && !$offline) {
            throw new OrderRejectedException('Le montant reçu est inférieur au total.');
        }

        return $received;
    }

    /**
     * Remarque pour la cuisine : texte brut, sur une ligne. Les caractères de contrôle et
     * invisibles (retours à la ligne, inversions de sens d'écriture…) sont remplacés par
     * des espaces, puis les espaces superflus retirés. Vide : pas de remarque.
     *
     * @throws OrderRejectedException
     */
    private static function readNote(mixed $note): ?string
    {
        if (null === $note) {
            return null;
        }
        if (!\is_string($note) || !mb_check_encoding($note, 'UTF-8')) {
            throw new OrderRejectedException('La remarque pour la cuisine est illisible.');
        }

        $note = trim((string) preg_replace(['/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', '/\s+/u'], ' ', $note));
        if (mb_strlen($note) > Order::NOTE_MAX_LENGTH) {
            throw new OrderRejectedException(\sprintf('La remarque pour la cuisine fait au plus %d caractères.', Order::NOTE_MAX_LENGTH));
        }

        return '' === $note ? null : $note;
    }

    /**
     * Numéro de table : entier de 1 à 999, ou absent. Hors ligne, un numéro invalide est
     * ignoré plutôt que de refuser une commande déjà payée (la caisse n'en envoie pas).
     *
     * @throws OrderRejectedException
     */
    private static function readTable(mixed $table, bool $offline): ?int
    {
        if (null === $table) {
            return null;
        }
        if (\is_int($table) && $table >= Order::TABLE_MIN && $table <= Order::TABLE_MAX) {
            return $table;
        }
        if ($offline) {
            return null;
        }

        throw new OrderRejectedException(\sprintf('Numéro de table invalide : un nombre de %d à %d.', Order::TABLE_MIN, Order::TABLE_MAX));
    }

    /** Valeur reçue, en texte court : l'historique ne doit pas grossir pour une donnée invalide. */
    private static function describeRejected(mixed $value): string
    {
        $text = json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return mb_strlen((string) $text) > 40 ? mb_substr((string) $text, 0, 40).'…' : (string) $text;
    }

    /**
     * Heure de l'encaissement sur la tablette, pour que l'attente en cuisine
     * reste juste après une coupure réseau. Une heure invraisemblable est
     * remplacée par celle du serveur.
     */
    private function readCreatedAt(mixed $value): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        if (!\is_string($value) || !preg_match(self::DATE_PATTERN, $value)) {
            return $now;
        }
        try {
            $createdAt = (new \DateTimeImmutable($value))->setTimezone($now->getTimezone());
        } catch (\Exception) {
            return $now;
        }

        return $createdAt < $now->modify('-24 hours') || $createdAt > $now->modify('+5 minutes') ? $now : $createdAt;
    }

    private function sameDeviceOrFail(Order $order, Device $device): Order
    {
        if ($order->getDevice()?->getId() !== $device->getId()) {
            throw new OrderRejectedException('Cet identifiant de commande est déjà utilisé par une autre tablette.', Response::HTTP_CONFLICT);
        }

        return $order;
    }

    private function numberTaken(OrderRepository $orders, Device $device): OrderRejectedException
    {
        $next = $orders->lastNumber($device->getEvent(), (string) $device->getTillLetter()) + 1;

        return new OrderRejectedException('Ce numéro de commande est déjà utilisé. La caisse reprend au numéro suivant.', Response::HTTP_CONFLICT, ['nextNumber' => $next]);
    }
}
