<?php

namespace App\Twig;

use App\Service\Money;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class MoneyExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            // 250|euros → « 2,50 € » (espace insécable avant le symbole)
            new TwigFilter('euros', static fn (int $cents): string => Money::formatEuros($cents)."\u{00A0}€"),
            // 250|euros_input → « 2,50 » (valeur d'un champ de saisie)
            new TwigFilter('euros_input', static fn (int $cents): string => Money::formatEuros($cents)),
        ];
    }
}
