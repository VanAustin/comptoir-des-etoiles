<?php

namespace App\Tests\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * bin/bruno nomme les requêtes une à une (WITH_KITCHEN, WITHOUT_KITCHEN) (ordre imposé) : une requête ajoutée à la collection
 * mais oubliée dans le script ne serait jamais lancée. Ce test l'empêche.
 */
class BrunoScriptTest extends TestCase
{
    public function testEveryRequestIsRunByTheScript(): void
    {
        $root = \dirname(__DIR__, 2);
        $script = (string) file_get_contents($root.'/bin/bruno');
        preg_match_all('/^(?:WITH_KITCHEN|WITHOUT_KITCHEN)="([^"]*)"/m', $script, $matches);
        $listed = array_flip(preg_split('/\s+/', implode(' ', $matches[1]), -1, \PREG_SPLIT_NO_EMPTY));
        self::assertArrayHasKey('appairage/1-echanger-le-code.bru', $listed, 'listes lues dans bin/bruno');

        $missing = [];
        foreach (glob($root.'/bruno/*/*.bru') ?: [] as $file) {
            $folder = basename(\dirname($file));
            $request = $folder.'/'.basename($file);
            if ('folder.bru' === basename($file) || 'environments' === $folder) {
                continue;
            }
            if (!isset($listed[$request]) && !isset($listed[$folder])) {
                $missing[] = $request;
            }
        }

        self::assertSame([], $missing, 'requêtes à ajouter à la liste de bin/bruno');
    }
}
