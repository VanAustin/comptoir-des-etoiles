<?php

namespace App\Tests;

/**
 * Numéros de révision d'un événement, lus en base (et non sur l'entité, que l'augmentation
 * en SQL ne met pas à jour) : chaque écriture qui change ce que voient les tablettes doit
 * augmenter la bonne révision, sinon les tablettes reçoivent 304 et ne voient rien.
 */
trait RevisionsTrait
{
    /** @return array{orders: int, menu: int} */
    protected static function revisionsOf(?int $eventId = null): array
    {
        $connection = self::getContainer()->get('doctrine')->getConnection();
        $row = null === $eventId
            ? $connection->fetchAssociative('SELECT orders_revision, menu_revision FROM event WHERE active = 1')
            : $connection->fetchAssociative('SELECT orders_revision, menu_revision FROM event WHERE id = ?', [$eventId]);
        self::assertIsArray($row, 'événement introuvable');

        return ['orders' => (int) $row['orders_revision'], 'menu' => (int) $row['menu_revision']];
    }

    /** @param array{orders: int, menu: int} $before */
    protected static function assertRevisionsMoved(array $before, int $orders, int $menu, ?int $eventId = null, string $message = ''): void
    {
        self::assertSame(['orders' => $before['orders'] + $orders, 'menu' => $before['menu'] + $menu], self::revisionsOf($eventId), $message);
    }
}
