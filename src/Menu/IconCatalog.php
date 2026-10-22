<?php

namespace App\Menu;

/**
 * Icônes proposées pour les articles : pictogrammes au trait de la bibliothèque
 * Lucide (licence ISC), copiés dans public/img/icons/ avec leur licence, plus
 * quelques icônes dessinées pour le projet dans le même style, là où Lucide
 * n'a rien (frites, crêpe, saucisse, agneau, cochon).
 * L'article enregistre la clé (par exemple « pizza »), jamais un chemin.
 *
 * Pour ajouter une icône : copier son fichier SVG depuis lucide-static,
 * l'ajouter ici et dans public/css/icons.css (IconCatalogTest le vérifie).
 */
final class IconCatalog
{
    /** Icône affichée quand un article n'en a pas, ou une inconnue. */
    public const DEFAULT = 'utensils';

    /** @var array<string, array<string, string>> groupe → clé → libellé */
    private const GROUPS = [
        'Salé' => [
            'sandwich' => 'Sandwich',
            'hamburger' => 'Burger',
            'fries' => 'Frites',
            'sausage' => 'Saucisse, chipolata, merguez',
            'pizza' => 'Pizza',
            'ham' => 'Jambon',
            'drumstick' => 'Poulet',
            'beef' => 'Viande',
            'pig' => 'Cochon',
            'lamb' => 'Agneau',
            'fish' => 'Poisson',
            'egg-fried' => 'Œuf',
            'salad' => 'Salade',
            'carrot' => 'Légumes',
            'soup' => 'Soupe',
            'cooking-pot' => 'Plat chaud',
            'popcorn' => 'Pop-corn',
            'wheat' => 'Pain',
        ],
        'Sucré' => [
            'crepe' => 'Crêpe',
            'croissant' => 'Viennoiserie',
            'cake-slice' => 'Part de gâteau',
            'cake' => 'Gâteau',
            'cupcake' => 'Muffin',
            'cookie' => 'Biscuit',
            'donut' => 'Beignet',
            'dessert' => 'Dessert',
            'ice-cream-cone' => 'Glace',
            'popsicle' => 'Glace à l\'eau',
            'candy' => 'Bonbon',
            'lollipop' => 'Sucette',
            'apple' => 'Fruit',
            'cherry' => 'Cerises',
            'banana' => 'Banane',
        ],
        'Boissons' => [
            'coffee' => 'Café, boisson chaude',
            'cup-soda' => 'Soda',
            'glass-water' => 'Eau',
            'citrus' => 'Jus de fruits',
            'milk' => 'Lait',
            'beer' => 'Bière',
            'wine' => 'Vin',
            'bottle-wine' => 'Bouteille',
            'martini' => 'Cocktail',
        ],
        'Divers' => [
            'utensils' => 'Repas',
            'chef-hat' => 'Fait maison',
            'star' => 'Formule',
            'gift' => 'Lot',
            'ticket' => 'Ticket',
            'package' => 'Divers',
        ],
    ];

    /** @return array<string, array<string, string>> */
    public static function groups(): array
    {
        return self::GROUPS;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_merge(...array_values(array_map('array_keys', self::GROUPS)));
    }

    public static function has(?string $key): bool
    {
        return null !== $key && \in_array($key, self::keys(), true);
    }
}
