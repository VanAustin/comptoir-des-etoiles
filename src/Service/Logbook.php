<?php

namespace App\Service;

use App\Entity\Event;
use App\Entity\Order;
use App\Entity\OrderLine;
use App\Enum\LineKind;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;

/**
 * Chiffres du Carnet de bord, recalculés à partir des commandes (jamais stockés).
 * Les commandes annulées sont exclues partout. La consigne n'est pas du chiffre
 * d'affaires : elle est comptée à part.
 */
final class Logbook
{
    /** Ventes d'un article qui n'existe plus en base. */
    public const OTHER_CATEGORY = 'Autre';

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly ProductRepository $products,
        private readonly StockCalculator $stock,
    ) {
    }

    /**
     * @return array{
     *     orders: list<Order>,
     *     totals: array{orders: int, cancelled: int, revenue: int, basket: int|null, cashCount: int, cashAmount: int, cardCount: int, cardAmount: int, depositsIn: int, depositsOut: int, depositBalance: int, cupsNotReturned: int|null, tableOrders: int, tableRevenue: int},
     *     tills: array<string, array{orders: int, cashExpected: int, card: int, shortfall: int}>,
     *     shortfalls: list<array{order: Order, missing: int}>,
     *     articles: list<array{name: string, category: string, archived: bool, formula: bool, sold: int, revenue: int, initial: int|null, remaining: int|null}>,
     *     categories: list<array{name: string, sold: int, revenue: int}>
     * }
     */
    public function summarize(Event $event): array
    {
        $orders = $this->orders->findForEvent($event);
        $totals = ['orders' => 0, 'cancelled' => 0, 'revenue' => 0, 'basket' => null, 'cashCount' => 0, 'cashAmount' => 0, 'cardCount' => 0, 'cardAmount' => 0, 'depositsIn' => 0, 'depositsOut' => 0, 'depositBalance' => 0, 'cupsNotReturned' => null, 'tableOrders' => 0, 'tableRevenue' => 0];
        $tills = [];
        $shortfalls = [];
        $sold = [];
        $withArticles = 0;

        foreach ($orders as $order) {
            if (OrderStatus::Cancelled === $order->getStatus()) {
                ++$totals['cancelled'];
                continue;
            }
            ++$totals['orders'];
            $letter = $order->getTillLetter();
            $tills[$letter] ??= ['orders' => 0, 'cashExpected' => 0, 'card' => 0, 'shortfall' => 0];
            ++$tills[$letter]['orders'];

            $hasArticle = false;
            $articlesRevenue = 0;
            foreach ($order->getLines() as $line) {
                match ($line->getKind()) {
                    LineKind::Article => $totals['revenue'] += $line->getTotalCents(),
                    LineKind::Deposit => $totals['depositsIn'] += $line->getTotalCents(),
                    LineKind::DepositReturn => $totals['depositsOut'] -= $line->getTotalCents(),
                };
                if (LineKind::Article === $line->getKind()) {
                    $hasArticle = true;
                    $key = self::articleKey($line);
                    $sold[$key] ??= ['quantity' => 0, 'revenue' => 0, 'label' => $line->getLabel(), 'category' => $line->getProduct()?->getCategory() ?? self::OTHER_CATEGORY];
                    $sold[$key]['quantity'] += $line->getQuantity();
                    $sold[$key]['revenue'] += $line->getTotalCents();
                    $articlesRevenue += $line->getTotalCents();
                }
            }
            $withArticles += $hasArticle ? 1 : 0;
            // Service à table : chiffre d'affaires hors consignes, boissons de la commande comprises
            if (null !== $order->getTableNumber()) {
                ++$totals['tableOrders'];
                $totals['tableRevenue'] += $articlesRevenue;
            }

            $total = $order->getTotalCents();
            if (PaymentMethod::Card === $order->getPaymentMethod()) {
                ++$totals['cardCount'];
                $totals['cardAmount'] += $total;
                $tills[$letter]['card'] += $total;
                continue;
            }
            ++$totals['cashCount'];
            $totals['cashAmount'] += $total;
            // Ce qui reste dans la caisse : le total, ou moins si le client a payé moins (commande hors ligne)
            $received = $order->getAmountReceivedCents() ?? $total;
            $tills[$letter]['cashExpected'] += min($received, $total);
            if ($received < $total) {
                $tills[$letter]['shortfall'] += $total - $received;
                $shortfalls[] = ['order' => $order, 'missing' => $total - $received];
            }
        }

        $totals['basket'] = $withArticles > 0 ? intdiv($totals['revenue'], $withArticles) : null;
        $totals['depositBalance'] = $totals['depositsIn'] - $totals['depositsOut'];
        $deposit = $event->getDepositCents();
        $totals['cupsNotReturned'] = $deposit > 0 ? intdiv($totals['depositBalance'], $deposit) : null;
        ksort($tills);
        $articles = $this->articles($event, $sold);

        return [
            'orders' => $orders,
            'totals' => $totals,
            'tills' => $tills,
            'shortfalls' => $shortfalls,
            'articles' => $articles,
            'categories' => self::categories($articles),
        ];
    }

    /**
     * Chiffre d'affaires par catégorie (cartons, restauration…), pour séparer les activités dans les
     * comptes. Catégorie actuelle de l'article (une formule compte dans sa propre catégorie), dans
     * l'ordre du menu ; la somme des catégories est le chiffre d'affaires hors consignes.
     *
     * @param list<array{name: string, category: string, archived: bool, formula: bool, sold: int, revenue: int, initial: int|null, remaining: int|null}> $articles
     *
     * @return list<array{name: string, sold: int, revenue: int}>
     */
    private static function categories(array $articles): array
    {
        $categories = [];
        foreach ($articles as $article) {
            $categories[$article['category']] ??= ['name' => $article['category'], 'sold' => 0, 'revenue' => 0];
            $categories[$article['category']]['sold'] += $article['sold'];
            $categories[$article['category']]['revenue'] += $article['revenue'];
        }

        return array_values($categories);
    }

    /**
     * Tous les articles de l'événement, retirés compris, dans l'ordre du menu ; puis les
     * ventes d'articles qui n'existent plus en base (supprimés avant l'archivage).
     *
     * @param array<string, array{quantity: int, revenue: int, label: string, category: string}> $sold
     *
     * @return list<array{name: string, category: string, archived: bool, formula: bool, sold: int, revenue: int, initial: int|null, remaining: int|null}>
     */
    private function articles(Event $event, array $sold): array
    {
        $products = $this->products->findAllForEvent($event);
        $remaining = $this->stock->remaining($event, $products);

        $articles = [];
        foreach ($products as $product) {
            $key = 'p'.$product->getId();
            $articles[] = [
                'name' => $product->getName(),
                'category' => $product->getCategory(),
                'archived' => $product->isArchived(),
                'formula' => $product->isFormula(),
                'sold' => $sold[$key]['quantity'] ?? 0,
                'revenue' => $sold[$key]['revenue'] ?? 0,
                'initial' => $product->isFormula() ? null : $product->getInitialStock(),
                'remaining' => $remaining[$product->getId()] ?? null,
            ];
            unset($sold[$key]);
        }
        foreach ($sold as $orphan) {
            $articles[] = ['name' => $orphan['label'], 'category' => $orphan['category'], 'archived' => true, 'formula' => false, 'sold' => $orphan['quantity'], 'revenue' => $orphan['revenue'], 'initial' => null, 'remaining' => null];
        }

        return $articles;
    }

    private static function articleKey(OrderLine $line): string
    {
        $product = $line->getProduct();

        return null !== $product ? 'p'.$product->getId() : 'l'.$line->getLabel();
    }
}
