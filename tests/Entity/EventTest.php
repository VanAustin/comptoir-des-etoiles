<?php

namespace App\Tests\Entity;

use App\Entity\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Zones des livreurs (service à table) : la rangée est le chiffre des centaines du numéro de table. */
class EventTest extends TestCase
{
    /** @return iterable<string, array{int, int, int|null}> rangées par zone, table, zone attendue */
    public static function zones(): iterable
    {
        yield '101 : rangée 1, zone 1' => [3, 101, 1];
        yield '304 : rangée 3, zone 1' => [3, 304, 1];
        yield '399 : rangée 3, zone 1' => [3, 399, 1];
        yield '401 : rangée 4, zone 2' => [3, 401, 2];
        yield '612 : rangée 6, zone 2' => [3, 612, 2];
        yield '701 : rangée 7, zone 3' => [3, 701, 3];
        yield '999 : rangée 9, zone 3' => [3, 999, 3];
        yield 'une rangée par zone' => [1, 808, 8];
        yield 'deux rangées par zone' => [2, 304, 2];
        yield 'neuf rangées : une seule zone' => [9, 999, 1];
        yield 'table 7, sans rangée : pas de zone' => [3, 7, null];
        yield 'table 99, sans rangée : pas de zone' => [3, 99, null];
    }

    #[DataProvider('zones')]
    public function testDeliveryZone(int $rowsPerZone, int $table, ?int $zone): void
    {
        $event = (new Event('Loto', new \DateTimeImmutable('2026-12-18')))->setRowsPerZone($rowsPerZone);

        self::assertSame($zone, $event->deliveryZone($table));
    }

    public function testThreeRowsPerZoneByDefault(): void
    {
        self::assertSame(3, (new Event('Loto', new \DateTimeImmutable('2026-12-18')))->getRowsPerZone());
    }

    public function testRowsPerZoneFromOneToNine(): void
    {
        $event = new Event('Loto', new \DateTimeImmutable('2026-12-18'));
        foreach ([0, 10, -1] as $wrong) {
            try {
                $event->setRowsPerZone($wrong);
                self::fail((string) $wrong);
            } catch (\InvalidArgumentException) {
                self::assertSame(3, $event->getRowsPerZone(), 'inchangé');
            }
        }
    }
}
