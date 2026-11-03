<?php

namespace App\Security;

use App\Entity\Device;
use Symfony\Bundle\SecurityBundle\Security;
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
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        $device = $this->security->getUser();
        if ($device instanceof Device && !$device->isRoleAvailable()) {
            return new JsonResponse(['error' => 'Cet événement est sans cuisine : cette tablette n\'a rien à afficher. Seules les caisses fonctionnent.'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(['error' => 'Cette tablette n\'a pas le droit de faire cette action.'], Response::HTTP_FORBIDDEN);
    }
}
