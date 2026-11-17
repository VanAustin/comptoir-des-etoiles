<?php

namespace App\Menu;

use App\Entity\Product;

/**
 * Valeurs d'un article notées dans le journal d'audit (avant et après une modification).
 */
final class ProductSnapshot
{
    /** @return array<string, mixed> */
    public static function of(Product $product): array
    {
        return [
            'name' => $product->getName(),
            'icon' => $product->getIcon(),
            'category' => $product->getCategory(),
            'price_cents' => $product->getPriceCents(),
            'initial_stock' => $product->getInitialStock(),
            'kitchen' => $product->goesToKitchen(),
            'deposit' => $product->hasDeposit(),
            'sold_alone' => $product->isSoldAlone(),
            'composition' => $product->describeComposition(),
        ];
    }
}
