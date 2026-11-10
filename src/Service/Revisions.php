<?php

namespace App\Service;

use App\Entity\Event;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enregistre un changement des commandes ou du menu d'un événement et augmente son numéro
 * de révision, dans une seule transaction.
 *
 * L'augmentation se fait en SQL (« + 1 » lu et écrit par la base) : deux caisses qui encaissent
 * en même temps donnent bien deux révisions. Un « + 1 » calculé en PHP les ferait lire la même
 * valeur, et une des deux commandes pourrait ne jamais apparaître sur les tablettes.
 *
 * Toute écriture qui change ce que reçoivent les tablettes passe par ici, à la place de flush().
 */
final class Revisions
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Commandes créées, avancées, remises ou annulées (cuisine, Arrivées, suivi de la caisse,
     * et menu de la caisse : stocks restants et dernier numéro).
     *
     * @template T
     *
     * @param (\Closure(): T)|null $work écritures à faire dans la même transaction (flush compris)
     *
     * @return T|null
     */
    public function flushOrders(Event $event, ?\Closure $work = null): mixed
    {
        return $this->flush($event, 'orders_revision', $work);
    }

    /**
     * Menu changé : articles, prix, stocks de départ, ordre, retrait, formules, réglages de
     * l'événement (nom, date, consigne, cuisine) ou d'une caisse (catégories, poste de vente).
     *
     * @template T
     *
     * @param (\Closure(): T)|null $work
     *
     * @return T|null
     */
    public function flushMenu(Event $event, ?\Closure $work = null): mixed
    {
        return $this->flush($event, 'menu_revision', $work);
    }

    /** @param 'orders_revision'|'menu_revision' $column */
    private function flush(Event $event, string $column, ?\Closure $work): mixed
    {
        // En cas d'erreur, tout est annulé : ni l'écriture ni la révision
        return $this->entityManager->wrapInTransaction(function () use ($event, $column, $work): mixed {
            $result = null !== $work ? $work() : null;
            $this->entityManager->flush();
            // Après l'écriture, dans la même transaction : les tablettes voient les deux, ou aucun
            $this->entityManager->getConnection()->executeStatement(
                \sprintf('UPDATE event SET %1$s = %1$s + 1 WHERE id = ?', $column),
                [$event->getId()],
            );

            return $result;
        });
    }
}
