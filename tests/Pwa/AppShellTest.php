<?php

namespace App\Tests\Pwa;

use App\Pwa\AppShell;
use PHPUnit\Framework\TestCase;

class AppShellTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/app-shell-'.bin2hex(random_bytes(4));
        foreach (['public/css', 'public/js', 'public/img/icons', 'templates', 'src/Pwa'] as $directory) {
            mkdir($this->root.'/'.$directory, 0o777, true);
        }
        file_put_contents($this->root.'/public/css/base.css', 'body {}');
        file_put_contents($this->root.'/public/js/till.js', '// caisse');
        file_put_contents($this->root.'/public/img/icons/coffee.svg', '<svg/>');
        file_put_contents($this->root.'/public/index.php', '<?php');
        file_put_contents($this->root.'/public/js/.DS_Store', '');
        file_put_contents($this->root.'/templates/till.html.twig', 'caisse');
        file_put_contents($this->root.'/src/Pwa/service-worker.js', '// sw');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testListsStaticFilesOnly(): void
    {
        self::assertSame(['/css/base.css', '/img/icons/coffee.svg', '/js/till.js'], (new AppShell($this->root))->staticFiles());
    }

    public function testVersionIsStableWhileNothingChanges(): void
    {
        self::assertSame((new AppShell($this->root))->version(), (new AppShell($this->root))->version());
    }

    /** @return iterable<string, array{string}> */
    public static function changedFiles(): iterable
    {
        yield 'feuille de style' => ['public/css/base.css'];
        yield 'script' => ['public/js/till.js'];
        yield 'gabarit' => ['templates/till.html.twig'];
        yield 'service worker' => ['src/Pwa/service-worker.js'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedFiles')]
    public function testVersionChangesWithAnyFileOfTheApplication(string $file): void
    {
        $before = (new AppShell($this->root))->version();
        // Même taille, contenu différent : seule l'empreinte du contenu le détecte
        $path = $this->root.'/'.$file;
        file_put_contents($path, strrev((string) file_get_contents($path)));

        self::assertNotSame($before, (new AppShell($this->root))->version());
    }
}
