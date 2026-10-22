<?php

namespace App\Twig;

use App\Menu\IconCatalog;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class IconExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            // icon_class('pizza') → « icon icon-pizza » ; icône inconnue ou absente → icône par défaut
            new TwigFunction('icon_class', static fn (?string $key): string => 'icon icon-'.(IconCatalog::has($key) ? $key : 'default')),
        ];
    }
}
