<?php

namespace App\Pwa;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ce que le service worker garde sur la tablette pour fonctionner sans réseau :
 * les pages des écrans de service et tous les fichiers statiques de public/.
 * L'administration n'en fait jamais partie (session, formulaires protégés).
 *
 * La version change dès qu'un fichier statique, un gabarit ou le service worker
 * change : les tablettes rechargent alors tout, sans outil de build.
 */
final class AppShell
{
    /** Pages des tablettes, disponibles hors ligne. */
    public const PAGES = ['/', '/demarrer', '/appairer', '/caisse', '/cuisine', '/arrivees', '/logo'];

    private const STATIC_DIRECTORIES = ['css', 'js', 'fonts', 'img'];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** @return list<string> adresses relatives à la racine du site, triées */
    public function staticFiles(): array
    {
        $urls = [];
        foreach ($this->files(self::STATIC_DIRECTORIES, $this->projectDir.'/public') as $relative) {
            $urls[] = '/'.$relative;
        }

        return $urls;
    }

    public function version(): string
    {
        $fingerprint = [];
        $sources = [
            $this->projectDir.'/public' => self::STATIC_DIRECTORIES,
            $this->projectDir => ['templates', 'src/Pwa'],
        ];
        foreach ($sources as $root => $directories) {
            foreach ($this->files($directories, $root) as $relative) {
                $path = $root.'/'.$relative;
                $fingerprint[] = $relative.':'.filesize($path).':'.hash_file('xxh128', $path);
            }
        }

        return substr(hash('sha256', implode("\n", $fingerprint)), 0, 16);
    }

    /**
     * @param list<string> $directories relatives à $root
     *
     * @return list<string> chemins relatifs à $root, triés
     */
    private function files(array $directories, string $root): array
    {
        $files = [];
        foreach ($directories as $directory) {
            if (!is_dir($root.'/'.$directory)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                // Fichiers cachés exclus (.DS_Store…)
                if ($file->isFile() && !str_starts_with($file->getFilename(), '.')) {
                    $files[] = substr($file->getPathname(), \strlen($root) + 1);
                }
            }
        }
        sort($files);

        return $files;
    }
}
