<?php

namespace App\Realtime;

use App\Entity\Device;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Indique aux tablettes où s'abonner, dans l'en-tête Link (découverte Mercure) :
 * <hub>; rel="mercure" et <sujet>; rel="self". Absent sans hub configuré.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class RealtimeLinkListener
{
    public function __construct(
        private readonly Realtime $realtime,
        private readonly Security $security,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $device = $this->security->getUser();
        if (!$event->isMainRequest() || !$device instanceof Device || !$this->realtime->isEnabled()
            || !$event->getRequest()->isMethod('GET') || !str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Link', \sprintf('<%s>; rel="mercure"', $this->realtime->publicHubUrl()), false);
        $headers->set('Link', \sprintf('<%s>; rel="self"', Realtime::ordersTopic($device->getEvent())), false);
    }
}
