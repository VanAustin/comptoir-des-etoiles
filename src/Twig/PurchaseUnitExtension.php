<?php

namespace App\Twig;

use App\Menu\PurchaseUnit;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PurchaseUnitExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            // in_units(125, 'pot de Nutella', 25) → « = 5 pots de Nutella » ; null sans unité ou sans stock
            new TwigFunction('in_units', PurchaseUnit::describe(...)),
        ];
    }
}
