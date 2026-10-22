<?php

namespace App\Tests\Service;

use App\Service\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function validAmounts(): iterable
    {
        yield 'entier' => ['2', 200];
        yield 'virgule' => ['2,50', 250];
        yield 'point' => ['2.5', 250];
        yield 'un chiffre après la virgule' => ['1,5', 150];
        yield 'avec symbole et espaces' => [' 3,00 € ', 300];
        yield 'espace insécable' => ["5\u{00A0}€", 500];
        yield 'zéro' => ['0', 0];
        yield 'centimes' => ['0,05', 5];
        yield 'maximum' => ['9999,99', 999999];
    }

    #[DataProvider('validAmounts')]
    public function testParseValidAmount(string $input, int $expected): void
    {
        self::assertSame($expected, Money::parseEuros($input));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAmounts(): iterable
    {
        yield 'vide' => [''];
        yield 'négatif' => ['-1'];
        yield 'trois décimales' => ['2,505'];
        yield 'texte' => ['deux euros'];
        yield 'trop grand' => ['10000'];
        yield 'deux séparateurs' => ['1,2,3'];
        yield 'notation scientifique' => ['1e3'];
    }

    #[DataProvider('invalidAmounts')]
    public function testRejectInvalidAmount(string $input): void
    {
        self::assertNull(Money::parseEuros($input));
    }

    public function testFormat(): void
    {
        self::assertSame('2,50', Money::formatEuros(250));
        self::assertSame('0,05', Money::formatEuros(5));
        self::assertSame('12,00', Money::formatEuros(1200));
        self::assertSame('-1,00', Money::formatEuros(-100));
    }
}
