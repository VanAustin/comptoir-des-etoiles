<?php

namespace App\Controller;

use App\Pwa\AppShell;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Application installable sur les tablettes : service worker, manifeste et page de démarrage. */
class PwaController extends AbstractController
{
    /** À la racine du site : le service worker contrôle alors toutes les pages. */
    #[Route('/sw.js', name: 'app_service_worker', methods: ['GET'])]
    public function serviceWorker(AppShell $shell, #[Autowire('%kernel.project_dir%/src/Pwa/service-worker.js')] string $source): Response
    {
        $prelude = \sprintf(
            "const VERSION = %s;\nconst PRECACHE = %s;\n\n",
            json_encode($shell->version(), \JSON_THROW_ON_ERROR),
            json_encode([...AppShell::PAGES, ...$shell->staticFiles()], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        );

        return new Response($prelude.file_get_contents($source), headers: [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            // Toujours vérifié auprès du serveur : c'est lui qui annonce les nouvelles versions
            'Cache-Control' => 'no-cache',
        ]);
    }

    #[Route('/manifest.webmanifest', name: 'app_manifest', methods: ['GET'])]
    public function manifest(Request $request): JsonResponse
    {
        $base = $request->getBasePath();

        return new JsonResponse([
            'name' => 'Le Comptoir des étoiles',
            'short_name' => 'Comptoir',
            'description' => 'Caisse, cuisine et appel des commandes.',
            'lang' => 'fr',
            'start_url' => $base.'/demarrer',
            'scope' => $base.'/',
            'display' => 'standalone',
            // Couleurs de la charte (Nuage et Renard) : le manifeste n'accepte pas de variables CSS
            'background_color' => '#FBF6EC',
            'theme_color' => '#A56726',
            'icons' => [
                ['src' => $base.'/img/app/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => $base.'/img/app/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => $base.'/img/app/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], headers: ['Content-Type' => 'application/manifest+json']);
    }

    /** Point d'entrée de l'application installée : ouvre l'écran du rôle de la tablette. */
    #[Route('/demarrer', name: 'app_start', methods: ['GET'])]
    public function start(): Response
    {
        return $this->render('start.html.twig');
    }
}
