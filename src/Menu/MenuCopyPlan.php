<?php

namespace App\Menu;

use App\Entity\Event;
use App\Entity\Product;

/**
 * Ce que fera « Reprendre le menu d'un autre événement », montré avant de confirmer.
 *
 * - $copied : articles et formules de l'événement d'origine qui seront recopiés, dans l'ordre du menu ;
 * - $addedComponents : parmi eux, les articles d'une catégorie non cochée, repris parce
 *   qu'une formule copiée les contient (sinon la formule serait incomplète) ;
 * - $reused : articles qui existent déjà sous le même nom dans l'événement de destination,
 *   pas recopiés ; une formule copiée qui les contient est reliée à l'article existant.
 */
final readonly class MenuCopyPlan
{
    /**
     * @param list<string>                                    $categories
     * @param list<Product>                                   $copied
     * @param list<Product>                                   $addedComponents
     * @param list<array{source: Product, existing: Product}> $reused
     */
    public function __construct(
        public Event $source,
        public Event $target,
        public array $categories,
        public array $copied,
        public array $addedComponents,
        public array $reused,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->copied;
    }

    public function isAddedComponent(Product $product): bool
    {
        return \in_array($product, $this->addedComponents, true);
    }
}
