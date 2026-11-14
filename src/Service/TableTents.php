<?php

namespace App\Service;

/**
 * Numéros des chevalets de table (service à table) : rangée × 100 + table.
 * 8 rangées de 8 tables donnent 101 à 108, 201 à 208… jusqu'à 808, dans la
 * limite 1 à 999 des numéros de table de la caisse (App\Entity\Order).
 */
final class TableTents
{
    public const MAX_ROWS = 9;
    public const MAX_TABLES_PER_ROW = 99;

    /**
     * @return list<int>
     *
     * @throws \InvalidArgumentException
     */
    public static function numbers(int $rows, int $tablesPerRow): array
    {
        if ($rows < 1 || $rows > self::MAX_ROWS || $tablesPerRow < 1 || $tablesPerRow > self::MAX_TABLES_PER_ROW) {
            throw new \InvalidArgumentException('Nombre de rangées ou de tables hors limites.');
        }

        $numbers = [];
        for ($row = 1; $row <= $rows; ++$row) {
            for ($table = 1; $table <= $tablesPerRow; ++$table) {
                $numbers[] = $row * 100 + $table;
            }
        }

        return $numbers;
    }

    /**
     * Deux chevalets par feuille A4 ; la dernière feuille peut n'en avoir qu'un.
     *
     * @param list<int> $numbers
     *
     * @return list<list<int>>
     */
    public static function sheets(array $numbers): array
    {
        return array_chunk($numbers, 2);
    }
}
