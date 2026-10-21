<?php

namespace App\Controller\Api;

use App\Entity\Device;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class MeController extends AbstractController
{
    /** Ce que la tablette sait d'elle-même : accessible à tous les rôles. */
    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] Device $device): JsonResponse
    {
        return new JsonResponse(self::describe($device));
    }

    /** @return array<string, mixed> */
    public static function describe(Device $device): array
    {
        return [
            'name' => $device->getName(),
            'role' => $device->getRole()->value,
            'tillLetter' => $device->getTillLetter(),
            'event' => [
                'name' => $device->getEvent()->getName(),
                'date' => $device->getEvent()->getDate()->format('Y-m-d'),
            ],
        ];
    }
}
