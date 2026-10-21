<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    /** Formats acceptés pour le logo déposé dans var/uploads/ (pas de SVG : il peut contenir du script). */
    private const LOGO_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home.html.twig');
    }

    /** Page ouverte sur la tablette pour saisir le code d'appairage. */
    #[Route('/appairer', name: 'app_pair', methods: ['GET'])]
    public function pair(): Response
    {
        return $this->render('pair.html.twig');
    }

    /**
     * Logo de l'association : déposé par chaque installation dans var/uploads/,
     * jamais commité. À défaut, le logo générique du dépôt.
     */
    #[Route('/logo', name: 'app_logo', methods: ['GET'])]
    public function logo(#[Autowire('%kernel.project_dir%')] string $projectDir): Response
    {
        $path = $projectDir.'/public/img/logo-generique.svg';
        $type = 'image/svg+xml';
        foreach (self::LOGO_TYPES as $extension => $mimeType) {
            if (is_file($candidate = $projectDir.'/var/uploads/logo.'.$extension)) {
                $path = $candidate;
                $type = $mimeType;
                break;
            }
        }

        // Type déclaré explicitement : pas besoin du composant symfony/mime
        $response = new BinaryFileResponse($path, headers: ['Content-Type' => $type]);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
