<?php

namespace App\Tests\Service;

use App\Entity\Order;
use App\Service\TableTents;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TableTentsTest extends TestCase
{
    public function testRowThenTable(): void
    {
        $numbers = TableTents::numbers(8, 8);

        self::assertCount(64, $numbers);
        self::assertSame([101, 102, 103, 104, 105, 106, 107, 108, 201], \array_slice($numbers, 0, 9));
        self::assertSame(808, end($numbers));
    }

    public function testSingleTable(): void
    {
        self::assertSame([101], TableTents::numbers(1, 1));
    }

    public function testLargestHallStaysWithinTableNumbersOfTheTill(): void
    {
        $numbers = TableTents::numbers(TableTents::MAX_ROWS, TableTents::MAX_TABLES_PER_ROW);

        self::assertSame(Order::TABLE_MAX, max($numbers));
        self::assertGreaterThanOrEqual(Order::TABLE_MIN, min($numbers));
    }

    /** @return iterable<string, array{int, int}> */
    public static function outOfRange(): iterable
    {
        yield 'aucune rangée' => [0, 8];
        yield 'dix rangées' => [10, 8];
        yield 'aucune table' => [8, 0];
        yield 'cent tables' => [8, 100];
    }

    #[DataProvider('outOfRange')]
    public function testOutOfRangeIsRefused(int $rows, int $tables): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TableTents::numbers($rows, $tables);
    }

    public function testTwoTentsPerSheetLastOneMayBeAlone(): void
    {
        self::assertSame([[101, 102], [103]], TableTents::sheets([101, 102, 103]));
        self::assertSame([], TableTents::sheets([]));
    }
}
