<?php

namespace App\Realtime;

use App\Entity\Event;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Temps réel facultatif : un hub Mercure prévient les tablettes qu'une commande a changé.
 *
 * Les messages ne contiennent aucune donnée : la tablette relit ensuite l'API, avec son jeton
 * (impossible à joindre à une connexion EventSource sans le mettre dans l'URL).
 * Sans hub configuré (MERCURE_URL vide), les tablettes interrogent le serveur comme avant.
 */
final class Realtime
{
    public function __construct(
        #[Autowire('%env(default::MERCURE_URL)%')]
        private readonly ?string $hubUrl,
        #[Autowire('%env(default::MERCURE_PUBLIC_URL)%')]
        private readonly ?string $publicHubUrl,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== (string) $this->hubUrl && '' !== (string) $this->publicHubUrl;
    }

    public function publicHubUrl(): string
    {
        return (string) $this->publicHubUrl;
    }

    /** Sujet des commandes d'un événement : cuisine, Arrivées et suivi des caisses s'y abonnent. */
    public static function ordersTopic(Event|int $event): string
    {
        return \sprintf('urn:comptoir:evenement:%d:commandes', $event instanceof Event ? $event->getId() : $event);
    }
}
