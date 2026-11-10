<?php

namespace App\Twig;

use App\Entity\Event;
use App\Repository\EventRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * active_event() : l'événement actif, affiché dans l'en-tête de chaque page de l'administration
 * (le menu, les tablettes et le carnet de bord dépendent de lui).
 */
final class ActiveEventExtension extends AbstractExtension
{
    public function __construct(private readonly EventRepository $events)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('active_event', $this->events->findActive(...))];
    }
}
