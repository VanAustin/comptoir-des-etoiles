<?php

namespace App\Service;

use App\Entity\Event;
use App\Entity\Product;
use App\Repository\OrderLineRepository;

/**
 * Stock restant = stock de départ − quantités vendues des commandes non annulées.
 * Jamais stocké comme un compteur : il est recalculé à partir des ventes.
 *
 * Le stock est une information pour les bénévoles, pas une limite : il peut
 * devenir négatif (stock de départ approximatif, ventes hors ligne).
 */
class StockCalculator
{
    public function __construct(private readonly OrderLineRepository $lines)
    {
    }

    /**
     * @param list<Product> $products les articles de l'événement
     *
     * @return array<int, int|null> stock restant par identifiant d'article ; null = non compté
     */
    public function remaining(Event $event, array $products): array
    {
        $sold = [];
        foreach ($this->lines->findSoldArticles($event) as $line) {
            // Une formule décompte les composants figés au moment de la vente
            $ids = $line['componentIds'] ?? [$line['productId']];
            foreach ($ids as $id) {
                if (null !== $id) {
                    $sold[$id] = ($sold[$id] ?? 0) + $line['quantity'];
                }
            }
        }

        $remaining = [];
        foreach ($products as $product) {
            if (!$product->isFormula()) {
                $initial = $product->getInitialStock();
                $remaining[$product->getId()] = null === $initial ? null : $initial - ($sold[$product->getId()] ?? 0);
            }
        }

        // Une formule : le plus petit stock restant parmi ses composants comptés
        foreach ($products as $product) {
            if ($product->isFormula()) {
                $counted = array_filter(
                    array_map(static fn (Product $c): ?int => $remaining[$c->getId()] ?? null, $product->getComponents()->toArray()),
                    static fn (?int $stock): bool => null !== $stock,
                );
                $remaining[$product->getId()] = [] === $counted ? null : min($counted);
            }
        }

        return $remaining;
    }
}
