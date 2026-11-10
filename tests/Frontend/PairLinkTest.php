<?php

namespace App\Tests\Frontend;

use App\Service\DevicePairing;
use PHPUnit\Framework\TestCase;

/**
 * La tablette vérifie le code d'un lien d'appairage avant de l'envoyer (public/js/pair-link.js) :
 * même alphabet et même longueur que les codes créés par le serveur. Les décisions elles-mêmes
 * sont testées par node --test (tests/js/pair-link.test.js).
 */
class PairLinkTest extends TestCase
{
    private const SCRIPT = __DIR__.'/../../public/js/pair-link.js';

    public function testAlphabetAndLengthMatchTheServer(): void
    {
        $script = (string) file_get_contents(self::SCRIPT);

        self::assertMatchesRegularExpression('/export const CODE_ALPHABET = "([A-Z0-9]+)";/', $script);
        preg_match('/export const CODE_ALPHABET = "([A-Z0-9]+)";/', $script, $alphabet);
        preg_match('/export const CODE_LENGTH = (\d+);/', $script, $length);

        self::assertSame(DevicePairing::CODE_ALPHABET, $alphabet[1]);
        self::assertSame(DevicePairing::CODE_LENGTH, (int) $length[1]);
    }

    public function testOnlyTheCodeTravelsInTheAddressNeverTheToken(): void
    {
        foreach (['pair.js', 'pair-link.js'] as $file) {
            $script = (string) file_get_contents(\dirname(self::SCRIPT).'/'.$file);
            self::assertDoesNotMatchRegularExpression('/[#?&]token=|searchParams\.(get|set|append)\(["\']token/', $script, $file);
        }
        // Le code est retiré de l'adresse dès qu'il est lu
        self::assertStringContainsString('history.replaceState(', (string) file_get_contents(\dirname(self::SCRIPT).'/pair.js'));
    }
}
