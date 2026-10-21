<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/**
 * Tablette authentifiée, mais son rôle n'autorise pas cette action
 * (par exemple une tablette d'affichage qui tente de créer une commande).
 */
class ApiAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return new JsonResponse(['error' => 'Cette tablette n\'a pas le droit de faire cette action.'], Response::HTTP_FORBIDDEN);
    }
}
