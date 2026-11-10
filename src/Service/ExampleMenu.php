<?php

namespace App\Service;

use App\Entity\Event;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Menu d'exemple du Loto (repris du prototype validé par l'APE),
 * pour tester l'application ou préparer la répétition générale.
 */
class ExampleMenu
{
    /** [clé, icône (voir IconCatalog), nom, catégorie, prix en centimes, stock de départ, cuisine, composants] */
    private const ITEMS = [
        ['croque', 'sandwich', 'Croque-monsieur', 'Salé', 300, 60, true, []],
        ['hotdog', 'sausage', 'Hot-dog', 'Salé', 300, 80, true, []],
        ['pizza', 'pizza', 'Part de pizza', 'Salé', 250, 48, true, []],
        ['crepe-sucre', 'crepe', 'Crêpe sucre', 'Sucré', 150, 70, true, []],
        ['crepe-choco', 'crepe', 'Crêpe chocolat', 'Sucré', 200, 70, true, []],
        ['gateau', 'cake-slice', 'Part de gâteau', 'Sucré', 150, 40, false, []],
        ['soda', 'cup-soda', 'Soda canette', 'Boissons', 150, 96, false, []],
        ['jus', 'citrus', 'Jus de fruits', 'Boissons', 100, 60, false, []],
        ['eau', 'glass-water', 'Eau 50 cl', 'Boissons', 100, 48, false, []],
        ['cafe', 'coffee', 'Café', 'Boissons', 100, null, false, []],
        ['chocolat', 'coffee', 'Chocolat chaud', 'Boissons', 150, null, false, []],
        ['menu-loto', 'star', 'Menu Loto', 'Formules', 550, null, true, ['hotdog', 'soda', 'crepe-sucre']],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Revisions $revisions,
    ) {
    }

    public function load(Event $event): int
    {
        $products = [];
        foreach (self::ITEMS as $position => [$key, $icon, $name, $category, $price, $stock, $kitchen, $components]) {
            $product = (new Product($event, $name))
                ->setIcon($icon)
                ->setCategory($category)
                ->setPriceCents($price)
                ->setInitialStock($stock)
                ->setGoesToKitchen($kitchen)
                ->setPosition($position + 1);
            foreach ($components as $component) {
                $product->addComponent($products[$component]);
            }
            $this->entityManager->persist($product);
            $products[$key] = $product;
        }

        $this->auditLogger->log('menu.example', 'event:'.$event->getId().' '.$event->getName(), after: ['products' => \count($products)]);
        $this->revisions->flushMenu($event);

        return \count($products);
    }
}
