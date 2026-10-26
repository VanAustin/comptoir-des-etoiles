<?php

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Réponse des routes interrogées régulièrement (toutes les 5 à 30 secondes) : tant que rien n'a
 * changé, le serveur répond 304 sans contenu, ce qui ménage le forfait 4G.
 * La tablette renvoie l'empreinte reçue dans l'en-tête If-None-Match.
 */
final class PollResponse
{
    /**
     * @param array<string, mixed> $data     contenu qui détermine l'empreinte
     * @param array<string, mixed> $volatile ajouté à la réponse sans compter dans l'empreinte (heure du serveur)
     */
    public static function create(Request $request, array $data, array $volatile = []): JsonResponse
    {
        $response = new JsonResponse($data + $volatile);
        $response->setEtag(hash('sha256', json_encode($data, \JSON_THROW_ON_ERROR)));
        // Toujours revalider auprès du serveur, jamais servir une copie en cache
        $response->headers->set('Cache-Control', 'no-cache, private');

        // Apache (mod_deflate) suffixe l'empreinte d'une réponse compressée par « -gzip »
        $sent = $request->headers->get('If-None-Match');
        if (null !== $sent) {
            $request->headers->set('If-None-Match', preg_replace('/-(gzip|br|deflate)"/', '"', $sent));
        }
        $response->isNotModified($request);

        return $response;
    }
}
