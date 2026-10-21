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
    /** [clé, icône, nom, catégorie, prix en centimes, stock de départ, cuisine, composants] */
    private const ITEMS = [
        ['croque', '🥪', 'Croque-monsieur', 'Salé', 300, 60, true, []],
        ['hotdog', '🌭', 'Hot-dog', 'Salé', 300, 80, true, []],
        ['pizza', '🍕', 'Part de pizza', 'Salé', 250, 48, true, []],
        ['crepe-sucre', '🥞', 'Crêpe sucre', 'Sucré', 150, 70, true, []],
        ['crepe-choco', '🍫', 'Crêpe chocolat', 'Sucré', 200, 70, true, []],
        ['gateau', '🍰', 'Part de gâteau', 'Sucré', 150, 40, false, []],
        ['soda', '🥤', 'Soda canette', 'Boissons', 150, 96, false, []],
        ['jus', '🧃', 'Jus de fruits', 'Boissons', 100, 60, false, []],
        ['eau', '💧', 'Eau 50 cl', 'Boissons', 100, 48, false, []],
        ['cafe', '☕', 'Café', 'Boissons', 100, null, false, []],
        ['chocolat', '♨️', 'Chocolat chaud', 'Boissons', 150, null, false, []],
        ['menu-loto', '⭐', 'Menu Loto', 'Formules', 550, null, true, ['hotdog', 'soda', 'crepe-sucre']],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
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
        $this->entityManager->flush();

        return \count($products);
    }
}
