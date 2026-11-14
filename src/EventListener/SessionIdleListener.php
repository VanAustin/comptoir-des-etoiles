<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ferme la session après 2 heures sans activité. La requête continue sans
 * utilisateur connecté : le pare-feu renvoie alors vers la page de connexion,
 * sauf si « Rester connectée sur cet appareil » a été cochée : le cookie
 * REMEMBERME rouvre aussitôt une session, sans message d'expiration.
 *
 * Priorité 20 : après l'ouverture de la session (128), avant le pare-feu (8).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
final class SessionIdleListener
{
    public const MAX_IDLE_SECONDS = 7200;

    /** Nom du cookie de config/packages/security.yaml (remember_me.name). */
    public const REMEMBER_ME_COOKIE = 'REMEMBERME';

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession()) {
            return;
        }

        $session = $request->getSession();
        $session->start();
        $lastUsed = $session->getMetadataBag()->getLastUsed();

        if ($lastUsed > 0 && time() - $lastUsed > self::MAX_IDLE_SECONDS) {
            $session->invalidate();
            if ($session instanceof FlashBagAwareSessionInterface && !$request->cookies->has(self::REMEMBER_ME_COOKIE)) {
                $session->getFlashBag()->add('info', 'Votre session a expiré après 2 heures d\'inactivité. Connectez-vous à nouveau.');
            }
        }
    }
}
