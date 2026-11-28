<?php

namespace App\Tests\Menu;

use App\Menu\PurchaseUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PurchaseUnitTest extends TestCase
{
    /** @return iterable<array{string, string}> */
    public static function plurals(): iterable
    {
        yield ['pot de Nutella', 'pots de Nutella'];
        yield ['kg de jambon', 'kg de jambon'];
        yield ['BIB de vin', 'BIB de vin'];
        yield ['sac de frites', 'sacs de frites'];
        yield ['paquet', 'paquets'];
        yield ['L de lait', 'L de lait'];
        yield ['cl', 'cl'];
        yield ['colis de saucisses', 'colis de saucisses'];
        yield ['boîte de 6', 'boîtes de 6'];
        yield ['seau de glace', 'seaux de glace'];
        yield ['prix', 'prix'];
        yield ['gaz', 'gaz'];
    }

    #[DataProvider('plurals')]
    public function testPluralOfTheFirstWordOnly(string $unit, string $plural): void
    {
        self::assertSame($plural, PurchaseUnit::plural($unit));
    }

    public function testDescribeInUnits(): void
    {
        self::assertSame('= 5 pots de Nutella', PurchaseUnit::describe(125, 'pot de Nutella', 25));
        self::assertSame('≈ 3,9 pots de Nutella', PurchaseUnit::describe(97, 'pot de Nutella', 25));
        self::assertSame('= 1 pot de Nutella', PurchaseUnit::describe(25, 'pot de Nutella', 25));
        self::assertSame('≈ 1,5 pot de Nutella', PurchaseUnit::describe(37, 'pot de Nutella', 25), 'singulier sous 2');
        self::assertSame('= 0 pot de Nutella', PurchaseUnit::describe(0, 'pot de Nutella', 25));
        self::assertSame('≈ -0,4 pot de Nutella', PurchaseUnit::describe(-10, 'pot de Nutella', 25), 'stock négatif possible');
        self::assertSame('= 2 kg de jambon', PurchaseUnit::describe(40, 'kg de jambon', 20));
        self::assertSame('≈ 4 sacs de frites', PurchaseUnit::describe(199, 'sac de frites', 50), '3,98 arrondi à 4');
    }

    public function testHalfRoundsUp(): void
    {
        self::assertSame(2.4, PurchaseUnit::units(47, 20), '2,35');
        self::assertSame(0.3, PurchaseUnit::units(1, 4), '0,25');
    }

    public function testNothingWithoutUnitOrStock(): void
    {
        self::assertNull(PurchaseUnit::describe(null, 'pot', 25));
        self::assertNull(PurchaseUnit::describe(125, null, null));
    }
}
