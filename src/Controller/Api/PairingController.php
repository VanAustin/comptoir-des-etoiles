<?php

namespace App\Controller\Api;

use App\Service\DevicePairing;
use App\Service\PairingException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

class PairingController extends AbstractController
{
    /**
     * Échange un code d'appairage contre le jeton de la tablette.
     * Corps attendu : {"code": "K7QM-3XRP"}.
     */
    #[Route('/api/pair', name: 'api_pair', methods: ['POST'])]
    public function pair(Request $request, DevicePairing $pairing, RateLimiterFactoryInterface $pairingLimiter): JsonResponse
    {
        if (!$pairingLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Trop de tentatives d\'appairage. Patientez 15 minutes, puis réessayez.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            $payload = [];
        }
        $code = $payload['code'] ?? null;
        if (!\is_string($code)) {
            return new JsonResponse(['error' => 'Saisissez le code affiché dans l\'administration.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            [$device, $token] = $pairing->pair($code);
        } catch (PairingException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'token' => $token,
            'expiresAt' => $device->getExpiresAt()?->format(\DATE_ATOM),
            'device' => MeController::describe($device),
        ], Response::HTTP_CREATED);
    }
}
