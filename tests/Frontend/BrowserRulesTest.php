<?php

namespace App\Tests\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Règles « Navigateur » de CLAUDE.md, vérifiées sur tous les fichiers :
 * pas de script ni de style en ligne (CSP stricte), pas de |raw dans Twig,
 * pas de innerHTML ni de stockage du jeton hors d'IndexedDB en JavaScript.
 */
class BrowserRulesTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        yield from self::files(\dirname(__DIR__, 2).'/templates', 'twig');
    }

    /** @return iterable<string, array{string}> */
    public static function scripts(): iterable
    {
        yield from self::files(\dirname(__DIR__, 2).'/public/js', 'js');
    }

    #[DataProvider('templates')]
    public function testTemplateHasNoInlineScriptOrStyle(string $path): void
    {
        $content = (string) file_get_contents($path);

        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $content, 'Script en ligne interdit');
        self::assertDoesNotMatchRegularExpression('/<style\b/i', $content, 'Balise <style> interdite');
        self::assertDoesNotMatchRegularExpression('/\sstyle=/i', $content, 'Attribut style interdit');
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+=/i', $content, 'Gestionnaire d\'événement en ligne interdit');
        self::assertStringNotContainsString('|raw', $content, '|raw interdit');
    }

    #[DataProvider('scripts')]
    public function testScriptUsesSafeDomAndStorage(string $path): void
    {
        $content = (string) file_get_contents($path);

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'localStorage', 'sessionStorage'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $content, $forbidden.' interdit');
        }
        foreach (['alert(', 'confirm(', 'prompt('] as $dialog) {
            self::assertDoesNotMatchRegularExpression('/(?<![\w.])'.preg_quote($dialog, '/').'/', $content, 'Fenêtre système interdite : '.$dialog);
        }
    }

    /** @return iterable<string, array{string}> */
    private static function files(string $directory, string $extension): iterable
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === $extension) {
                yield substr($file->getPathname(), \strlen(\dirname(__DIR__, 2)) + 1) => [$file->getPathname()];
            }
        }
    }
}
