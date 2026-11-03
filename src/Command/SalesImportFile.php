<?php

namespace App\Command;

use App\Entity\Order;
use App\Service\DevicePairing;

/**
 * Fichier « comptoir-import-ventes », version 1 : ventes reconstituées
 * (bons papier…) à importer en développement. Lu et validé en entier
 * avant tout enregistrement ; la première erreur arrête tout.
 */
final class SalesImportFile
{
    public const FORMAT = 'comptoir-import-ventes';
    public const VERSION = 1;
    public const MAX_BYTES = 1048576;

    private const MAX_QUANTITY = 99;
    private const MAX_NUMBER = 9999;
    private const MAX_DEPOSIT_CENTS = 10000;
    private const MAX_PRICE_CENTS = 100000;
    private const MAX_STOCK = 100000;
    private const MAX_COUNT = 1000000;
    private const MAX_TOTAL_CENTS = 100000000;

    private const ROOT_KEYS = ['format', 'version', 'source', 'event', 'assumptions', 'skipped', 'products', 'orders', 'expected'];
    private const EVENT_KEYS = ['name', 'date', 'withKitchen', 'depositCents'];
    private const PRODUCT_KEYS = ['key', 'name', 'category', 'priceCents', 'kitchen', 'deposit', 'startStock', 'stockSource', 'components', 'soldAlone'];
    private const COMPONENT_KEYS = ['product', 'qty'];
    private const ORDER_KEYS = ['letter', 'number', 'lines', 'importNote'];
    private const LINE_KEYS = ['product', 'qty'];
    private const EXPECTED_KEYS = ['orders', 'items', 'totalCents', 'itemsByProduct'];

    /**
     * @param array{name: string, date: \DateTimeImmutable, withKitchen: bool, depositCents: int}                                                                                    $event
     * @param list<array{key: string, name: string, category: string, priceCents: int, kitchen: bool, deposit: bool, startStock: ?int, soldAlone: bool, components: list<array{product: string, qty: int}>}> $products
     * @param list<array{letter: string, number: int, lines: list<array{product: string, qty: int}>, importNote: ?string}>                                                           $orders
     * @param array{orders: int, items: int, totalCents: int, itemsByProduct: array<string, int>}                                                                                   $expected
     * @param list<string>                                                                                                                                                         $assumptions
     * @param list<string>                                                                                                                                                         $skipped
     */
    private function __construct(
        public readonly array $event,
        public readonly array $products,
        public readonly array $orders,
        public readonly array $expected,
        public readonly array $assumptions,
        public readonly array $skipped,
    ) {
    }

    /** @throws \InvalidArgumentException message en français, qui dit où est l'erreur */
    public static function read(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(\sprintf('Fichier introuvable ou illisible : %s', $path));
        }
        if (filesize($path) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Le fichier dépasse 1 Mo.');
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Le fichier n\'est pas un JSON valide : '.$e->getMessage());
        }

        return self::fromArray($data);
    }

    public static function fromArray(mixed $data): self
    {
        $data = self::object($data, '(racine)', self::ROOT_KEYS, ['format', 'version', 'event', 'products', 'orders', 'expected']);
        if (self::FORMAT !== $data['format'] || self::VERSION !== $data['version']) {
            throw new \InvalidArgumentException(\sprintf('Format attendu : « %s », version %d.', self::FORMAT, self::VERSION));
        }

        $event = self::readEvent($data['event']);
        $products = self::readProducts($data['products']);
        $orders = self::readOrders($data['orders'], $products);
        $expected = self::readExpected($data['expected'], $products);

        $assumptions = self::stringList($data['assumptions'] ?? [], 'assumptions');
        $skipped = [];
        foreach (self::list($data['skipped'] ?? [], 'skipped') as $i => $entry) {
            if (!\is_array($entry) || array_is_list($entry) && [] !== $entry) {
                throw new \InvalidArgumentException(\sprintf('skipped[%d] : objet attendu.', $i));
            }
            $skipped[] = implode(', ', array_map(
                static fn (string $k, mixed $v): string => $k.' : '.(\is_scalar($v) ? (string) $v : json_encode($v, \JSON_UNESCAPED_UNICODE)),
                array_keys($entry),
                $entry,
            ));
        }

        return new self($event, array_values($products), $orders, $expected, $assumptions, $skipped);
    }

    /** @return array{name: string, date: \DateTimeImmutable, withKitchen: bool, depositCents: int} */
    private static function readEvent(mixed $value): array
    {
        $event = self::object($value, 'event', self::EVENT_KEYS, self::EVENT_KEYS);
        $name = self::text($event['name'], 'event.name', 100);
        $date = \is_string($event['date']) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $event['date']) : false;
        if (false === $date || $date->format('Y-m-d') !== $event['date']) {
            throw new \InvalidArgumentException('event.date : date attendue au format AAAA-MM-JJ.');
        }

        return [
            'name' => $name,
            'date' => $date,
            'withKitchen' => self::bool($event['withKitchen'], 'event.withKitchen'),
            'depositCents' => self::int($event['depositCents'], 'event.depositCents', 0, self::MAX_DEPOSIT_CENTS),
        ];
    }

    /** @return array<string, array{key: string, name: string, category: string, priceCents: int, kitchen: bool, deposit: bool, startStock: ?int, soldAlone: bool, components: list<array{product: string, qty: int}>}> */
    private static function readProducts(mixed $value): array
    {
        $products = [];
        $names = [];
        foreach (self::list($value, 'products', nonEmpty: true) as $i => $raw) {
            $where = \sprintf('products[%d]', $i);
            $p = self::object($raw, $where, self::PRODUCT_KEYS, ['key', 'name', 'category', 'priceCents', 'kitchen', 'deposit', 'startStock']);
            $key = self::text($p['key'], $where.'.key', 40);
            if (isset($products[$key])) {
                throw new \InvalidArgumentException(\sprintf('%s.key : « %s » est déjà utilisée.', $where, $key));
            }
            $name = self::text($p['name'], $where.'.name', 100);
            if (isset($names[mb_strtolower($name)])) {
                throw new \InvalidArgumentException(\sprintf('%s.name : « %s » est déjà utilisé.', $where, $name));
            }
            $names[mb_strtolower($name)] = true;
            if (\array_key_exists('stockSource', $p) && !\is_string($p['stockSource'])) {
                throw new \InvalidArgumentException($where.'.stockSource : texte attendu.');
            }

            $components = [];
            foreach (self::list($p['components'] ?? [], $where.'.components') as $j => $rawComponent) {
                $c = self::object($rawComponent, \sprintf('%s.components[%d]', $where, $j), self::COMPONENT_KEYS, self::COMPONENT_KEYS);
                $components[] = [
                    'product' => self::text($c['product'], \sprintf('%s.components[%d].product', $where, $j), 40),
                    'qty' => self::int($c['qty'], \sprintf('%s.components[%d].qty', $where, $j), 1, self::MAX_QUANTITY),
                ];
            }

            $products[$key] = [
                'key' => $key,
                'name' => $name,
                'category' => self::text($p['category'], $where.'.category', 40),
                'priceCents' => self::int($p['priceCents'], $where.'.priceCents', 0, self::MAX_PRICE_CENTS),
                'kitchen' => self::bool($p['kitchen'], $where.'.kitchen'),
                'deposit' => self::bool($p['deposit'], $where.'.deposit'),
                'startStock' => null === $p['startStock'] ? null : self::int($p['startStock'], $where.'.startStock', 0, self::MAX_STOCK),
                // Facultatif : false pour un article vendu seulement dans les formules
                'soldAlone' => \array_key_exists('soldAlone', $p) ? self::bool($p['soldAlone'], $where.'.soldAlone') : true,
                'components' => $components,
            ];
            if ([] !== $components && !$products[$key]['soldAlone']) {
                throw new \InvalidArgumentException($where.'.soldAlone : une formule est toujours vendue seule.');
            }
        }

        // Une formule ne contient que des articles simples, chacun une seule fois
        foreach ($products as $key => $product) {
            $seen = [];
            foreach ($product['components'] as $c) {
                $component = $products[$c['product']] ?? null;
                if (null === $component) {
                    throw new \InvalidArgumentException(\sprintf('Formule « %s » : article « %s » inconnu.', $key, $c['product']));
                }
                if ([] !== $component['components']) {
                    throw new \InvalidArgumentException(\sprintf('Formule « %s » : « %s » est une formule, une formule ne peut pas en contenir une autre.', $key, $c['product']));
                }
                if (isset($seen[$c['product']])) {
                    throw new \InvalidArgumentException(\sprintf('Formule « %s » : « %s » apparaît deux fois.', $key, $c['product']));
                }
                $seen[$c['product']] = true;
            }
        }

        return $products;
    }

    /**
     * @param array<string, array<string, mixed>> $products
     *
     * @return list<array{letter: string, number: int, lines: list<array{product: string, qty: int}>, importNote: ?string}>
     */
    private static function readOrders(mixed $value, array $products): array
    {
        $orders = [];
        $numbers = [];
        foreach (self::list($value, 'orders', nonEmpty: true) as $i => $raw) {
            $where = \sprintf('orders[%d]', $i);
            $o = self::object($raw, $where, self::ORDER_KEYS, ['letter', 'number', 'lines']);
            $letter = $o['letter'];
            if (!\is_string($letter) || 1 !== \strlen($letter) || !str_contains(DevicePairing::TILL_LETTERS, $letter)) {
                throw new \InvalidArgumentException($where.'.letter : une lettre de caisse majuscule, sans I ni O.');
            }
            $number = self::int($o['number'], $where.'.number', 1, self::MAX_NUMBER);
            if (isset($numbers[$letter.$number])) {
                throw new \InvalidArgumentException(\sprintf('%s : le numéro %s%d est déjà utilisé.', $where, $letter, $number));
            }
            $numbers[$letter.$number] = true;

            $lines = [];
            $seen = [];
            foreach (self::list($o['lines'], $where.'.lines', nonEmpty: true) as $j => $rawLine) {
                $lineWhere = \sprintf('%s.lines[%d]', $where, $j);
                $l = self::object($rawLine, $lineWhere, self::LINE_KEYS, self::LINE_KEYS);
                $product = self::text($l['product'], $lineWhere.'.product', 40);
                if (!isset($products[$product])) {
                    throw new \InvalidArgumentException(\sprintf('%s : article « %s » inconnu.', $lineWhere, $product));
                }
                if (isset($seen[$product])) {
                    throw new \InvalidArgumentException(\sprintf('%s : article « %s » déjà présent dans la commande.', $lineWhere, $product));
                }
                $seen[$product] = true;
                $lines[] = ['product' => $product, 'qty' => self::int($l['qty'], $lineWhere.'.qty', 1, self::MAX_QUANTITY)];
            }

            $orders[] = [
                'letter' => $letter,
                'number' => $number,
                'lines' => $lines,
                'importNote' => isset($o['importNote']) ? self::text($o['importNote'], $where.'.importNote', Order::NOTE_MAX_LENGTH - mb_strlen(DevImportSalesCommand::NOTE_PREFIX)) : null,
            ];
        }

        return $orders;
    }

    /**
     * @param array<string, array<string, mixed>> $products
     *
     * @return array{orders: int, items: int, totalCents: int, itemsByProduct: array<string, int>}
     */
    private static function readExpected(mixed $value, array $products): array
    {
        $e = self::object($value, 'expected', self::EXPECTED_KEYS, self::EXPECTED_KEYS);
        if (!\is_array($e['itemsByProduct']) || array_is_list($e['itemsByProduct']) && [] !== $e['itemsByProduct']) {
            throw new \InvalidArgumentException('expected.itemsByProduct : objet attendu (clé d\'article : quantité).');
        }
        $byProduct = [];
        foreach ($e['itemsByProduct'] as $key => $quantity) {
            if (!isset($products[$key])) {
                throw new \InvalidArgumentException(\sprintf('expected.itemsByProduct : article « %s » inconnu.', $key));
            }
            $byProduct[(string) $key] = self::int($quantity, 'expected.itemsByProduct.'.$key, 0, self::MAX_COUNT);
        }

        return [
            'orders' => self::int($e['orders'], 'expected.orders', 0, self::MAX_COUNT),
            'items' => self::int($e['items'], 'expected.items', 0, self::MAX_COUNT),
            'totalCents' => self::int($e['totalCents'], 'expected.totalCents', -self::MAX_TOTAL_CENTS, self::MAX_TOTAL_CENTS),
            'itemsByProduct' => $byProduct,
        ];
    }

    /**
     * Objet JSON avec uniquement des clés connues, et toutes les clés obligatoires.
     *
     * @param list<string> $allowed
     * @param list<string> $required
     *
     * @return array<string, mixed>
     */
    private static function object(mixed $value, string $where, array $allowed, array $required): array
    {
        if (!\is_array($value) || array_is_list($value) && [] !== $value) {
            throw new \InvalidArgumentException($where.' : objet attendu.');
        }
        foreach (array_keys($value) as $key) {
            if (!\in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException(\sprintf('%s : clé « %s » inconnue.', $where, $key));
            }
        }
        foreach ($required as $key) {
            if (!\array_key_exists($key, $value)) {
                throw new \InvalidArgumentException(\sprintf('%s : clé « %s » manquante.', $where, $key));
            }
        }

        return $value;
    }

    /** @return list<mixed> */
    private static function list(mixed $value, string $where, bool $nonEmpty = false): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException($where.' : liste attendue.');
        }
        if ($nonEmpty && [] === $value) {
            throw new \InvalidArgumentException($where.' : la liste est vide.');
        }

        return $value;
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $where): array
    {
        foreach (self::list($value, $where) as $i => $item) {
            if (!\is_string($item)) {
                throw new \InvalidArgumentException(\sprintf('%s[%d] : texte attendu.', $where, $i));
            }
        }

        return $value;
    }

    private static function text(mixed $value, string $where, int $maxLength): string
    {
        if (!\is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException($where.' : texte non vide attendu.');
        }
        $value = trim($value);
        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(\sprintf('%s : %d caractères au maximum.', $where, $maxLength));
        }

        return $value;
    }

    private static function int(mixed $value, string $where, int $min, int $max): int
    {
        if (!\is_int($value) || $value < $min || $value > $max) {
            throw new \InvalidArgumentException(\sprintf('%s : nombre entier attendu (%d à %d).', $where, $min, $max));
        }

        return $value;
    }

    private static function bool(mixed $value, string $where): bool
    {
        if (!\is_bool($value)) {
            throw new \InvalidArgumentException($where.' : true ou false attendu.');
        }

        return $value;
    }
}
