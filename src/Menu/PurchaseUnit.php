<?php

namespace App\Menu;

/**
 * Stock en unités d'achat, pour les courses : 125 portions à 25 par pot → « = 5 pots de Nutella ».
 * Le stock reste toujours compté en portions ; ceci n'est qu'un affichage.
 */
final class PurchaseUnit
{
    public const NAME_MAX_LENGTH = 40;
    public const PORTIONS_MAX = 999;

    /** Unités de mesure invariables : « 2 kg de jambon ». */
    private const INVARIABLE = ['g', 'kg', 'l', 'cl', 'ml'];

    /** Nombre d'unités, arrondi au dixième (moitié vers le haut) : 97 portions à 25 → 3,9. */
    public static function units(int $portions, int $portionsPerUnit): float
    {
        return round($portions / $portionsPerUnit, 1, \PHP_ROUND_HALF_UP);
    }

    /**
     * « = 5 pots de Nutella » si le compte tombe juste, « ≈ 3,9 pots de Nutella » sinon.
     * Null sans unité d'achat ou sans stock.
     */
    public static function describe(?int $portions, ?string $unit, ?int $portionsPerUnit): ?string
    {
        if (null === $portions || null === $unit || null === $portionsPerUnit || $portionsPerUnit < 1) {
            return null;
        }
        $exact = 0 === $portions % $portionsPerUnit;
        $units = self::units($portions, $portionsPerUnit);
        // Pluriel à partir de 2, comme en français : « 1,5 pot »
        $label = abs($units) >= 2 ? self::plural($unit) : $unit;

        return ($exact ? '= ' : '≈ ').self::number($units).' '.$label;
    }

    /** 3.9 → « 3,9 », 5.0 → « 5 » : nombre pour un tableur, virgule décimale. */
    public static function number(float $units): string
    {
        $text = number_format($units, 1, ',', '');

        return str_ends_with($text, ',0') ? substr($text, 0, -2) : $text;
    }

    /**
     * Pluriel du premier mot seulement : « pot de Nutella » → « pots de Nutella ».
     * Inchangé s'il finit par s, x ou z, si c'est une unité de mesure (kg, l…) ou un sigle (BIB).
     */
    public static function plural(string $unit): string
    {
        $parts = explode(' ', trim($unit), 2);
        $first = $parts[0];
        $rest = isset($parts[1]) ? ' '.$parts[1] : '';

        if ('' === $first
            || preg_match('/[sxz]$/iu', $first)
            || \in_array(mb_strtolower($first), self::INVARIABLE, true)
            || preg_match('/^\p{Lu}{2,}$/u', $first)) {
            return $unit;
        }
        // « seau » → « seaux »
        $first .= preg_match('/eau$/iu', $first) ? 'x' : 's';

        return $first.$rest;
    }
}
