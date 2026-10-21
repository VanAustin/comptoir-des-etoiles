<?php

namespace App\Service;

/**
 * Conversion entre les montants saisis en euros (« 2,50 », « 2.5 », « 2 € »)
 * et les centimes utilisés partout côté serveur. Aucun calcul en virgule flottante.
 */
final class Money
{
    public const MAX_CENTS = 999999;

    /** @return int|null centimes, ou null si la saisie n'est pas un montant valide */
    public static function parseEuros(string $input): ?int
    {
        $value = str_replace([' ', "\u{00A0}", "\u{202F}", '€'], '', trim($input));
        $value = str_replace(',', '.', $value);

        if (!preg_match('/^(\d{1,4})(?:\.(\d{1,2}))?$/', $value, $matches)) {
            return null;
        }

        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '0', 2, '0');
    }

    /** 250 → « 2,50 » (pour un champ de saisie). */
    public static function formatEuros(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return \sprintf('%s%d,%02d', $sign, intdiv($cents, 100), $cents % 100);
    }
}
