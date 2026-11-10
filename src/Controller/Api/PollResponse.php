<?php

namespace App\Controller\Api;

use App\Entity\Event;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réponse des routes que les tablettes interrogent toutes les quelques secondes.
 *
 * L'empreinte (ETag) est faite des numéros de révision de l'événement, déjà lus avec la tablette
 * lors de l'authentification (une seule requête SQL). Tant qu'ils n'ont pas changé, le serveur
 * répond 304 sans contenu, sans relire les commandes ni le menu : c'est le cas de presque tous
 * les appels, et ce qui ménage le serveur et le forfait 4G.
 *
 * Les révisions sont lues avant le contenu : si une commande arrive entre les deux, la tablette
 * reçoit déjà la commande avec l'ancienne révision, puis tout à nouveau à l'appel suivant.
 * Jamais l'inverse (révision nouvelle, contenu ancien), qui lui ferait manquer la commande.
 *
 * L'heure du serveur vient de l'en-tête Date, présent aussi sur une 304.
 */
final class PollResponse
{
    /** Change à chaque cache:clear, donc à chaque mise à jour du serveur : un nouveau format de réponse n'est jamais masqué par une 304. */
    private readonly int $buildTime;

    public function __construct(ContainerBagInterface $parameters)
    {
        // Paramètre écrit dans le conteneur compilé : lu à l'exécution, pas injectable à la compilation
        $this->buildTime = (int) $parameters->get('container.build_time');
    }

    /**
     * @param bool                                 $withMenu le contenu dépend aussi du menu (noms d'articles, stocks…)
     * @param \Closure(): array<string, mixed> $content  appelé seulement si la tablette n'a pas déjà cette version
     */
    public function create(Request $request, Event $event, bool $withMenu, \Closure $content): Response
    {
        $etag = \sprintf('e%d-o%d-m%s-v%d', $event->getId(), $event->getOrdersRevision(), $withMenu ? $event->getMenuRevision() : '', $this->buildTime);
        // Toujours revalider auprès du serveur, jamais servir une copie gardée par un intermédiaire
        $headers = ['Cache-Control' => 'no-cache, private'];

        // Apache (mod_deflate) suffixe l'empreinte d'une réponse compressée par « -gzip »
        $sent = $request->headers->get('If-None-Match');
        if (null !== $sent) {
            $request->headers->set('If-None-Match', preg_replace('/-(gzip|br|deflate)"/', '"', $sent));
        }

        $notModified = new Response(null, Response::HTTP_OK, $headers);
        $notModified->setEtag($etag);
        if ($notModified->isNotModified($request)) {
            return $notModified;
        }

        $response = new JsonResponse($content(), Response::HTTP_OK, $headers);
        $response->setEtag($etag);

        return $response;
    }
}
