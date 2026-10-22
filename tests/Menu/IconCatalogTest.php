<?php

namespace App\Tests\Menu;

use App\Menu\IconCatalog;
use PHPUnit\Framework\TestCase;

/** Chaque icône du catalogue a son fichier SVG et sa règle CSS, et rien de plus. */
class IconCatalogTest extends TestCase
{
    public function testEveryIconHasItsFileAndCssRule(): void
    {
        $root = \dirname(__DIR__, 2).'/public';
        $css = (string) file_get_contents($root.'/css/icons.css');

        foreach (IconCatalog::keys() as $key) {
            self::assertFileExists($root.'/img/icons/'.$key.'.svg');
            self::assertStringContainsString('.icon-'.$key.' { -webkit-mask-image: url(../img/icons/'.$key.'.svg); mask-image: url(../img/icons/'.$key.'.svg); }', $css);
        }
        self::assertContains(IconCatalog::DEFAULT, IconCatalog::keys());
    }

    public function testNoUnusedIconFile(): void
    {
        $files = array_map(static fn (string $f) => basename($f, '.svg'), glob(\dirname(__DIR__, 2).'/public/img/icons/*.svg'));

        self::assertEqualsCanonicalizing(IconCatalog::keys(), $files);
        self::assertFileExists(\dirname(__DIR__, 2).'/public/img/icons/LICENSE.txt');
    }

    public function testKeysAreUnique(): void
    {
        self::assertSame(IconCatalog::keys(), array_values(array_unique(IconCatalog::keys())));
    }

    public function testUnknownIconsAreRejected(): void
    {
        self::assertTrue(IconCatalog::has('pizza'));
        self::assertFalse(IconCatalog::has('🍕'));
        self::assertFalse(IconCatalog::has('../../etc/passwd'));
        self::assertFalse(IconCatalog::has(null));
    }
}
