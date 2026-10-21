<?php

namespace App\EventListener;

use App\Entity\Device;
use App\Repository\DeviceRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Garde-fous communs à toute l'API des tablettes :
 * taille du corps limitée, nombre de requêtes limité par tablette,
 * et date de dernière connexion tenue à jour sans écrire à chaque requête.
 */
final class ApiGuardListener
{
    public const MAX_BODY_BYTES = 65536;
    private const LAST_SEEN_PRECISION_SECONDS = 60;

    public function __construct(
        private readonly Security $security,
        private readonly DeviceRepository $devices,
        private readonly RateLimiterFactoryInterface $deviceApiLimiter,
    ) {
    }

    /** Avant le routage et le pare-feu : un corps trop gros n'est même pas lu par l'application. */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
    public function limitBodySize(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isApi($request)) {
            return;
        }

        $declared = $request->headers->get('Content-Length');
        $size = null !== $declared ? (int) $declared : \strlen($request->getContent());
        if ($size > self::MAX_BODY_BYTES) {
            $event->setResponse(new JsonResponse(['error' => 'La requête est trop volumineuse.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE));
        }
    }

    /** Après le pare-feu (priorité 8) : la tablette est authentifiée. */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
    public function limitRateAndTrackDevice(RequestEvent $event): void
    {
        $device = $this->security->getUser();
        if (!$event->isMainRequest() || !self::isApi($event->getRequest()) || !$device instanceof Device) {
            return;
        }

        $limit = $this->deviceApiLimiter->create('device-'.$device->getId())->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            $event->setResponse(new JsonResponse(
                ['error' => 'Cette tablette envoie trop de requêtes. Patientez quelques secondes.'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) $retryAfter],
            ));

            return;
        }

        $now = new \DateTimeImmutable();
        $lastSeen = $device->getLastSeenAt();
        if (null === $lastSeen || $now->getTimestamp() - $lastSeen->getTimestamp() >= self::LAST_SEEN_PRECISION_SECONDS) {
            $this->devices->touchLastSeen($device, $now);
        }
    }

    private static function isApi(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/');
    }
}
