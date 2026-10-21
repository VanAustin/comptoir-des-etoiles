<?php

namespace App\Controller\Admin;

use App\Entity\Device;
use App\Enum\DeviceRole;
use App\Repository\DeviceRepository;
use App\Repository\EventRepository;
use App\Service\AuditLogger;
use App\Service\DevicePairing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/tablettes')]
class DeviceController extends AbstractController
{
    private const NAME_MAX_LENGTH = 60;
    /** Le code en clair ne transite que par ce message flash, lu une seule fois. */
    private const CODE_FLASH = 'pairing_code';

    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly EventRepository $events,
        private readonly DevicePairing $pairing,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'admin_device_index', methods: ['GET'])]
    public function index(): Response
    {
        $event = $this->events->findActive();
        $devices = $event ? $this->devices->findForEvent($event) : [];
        $now = new \DateTimeImmutable();

        return $this->render('admin/device/index.html.twig', [
            'event' => $event,
            'devices' => $devices,
            'states' => array_combine(
                array_map(static fn (Device $d) => $d->getId(), $devices),
                array_map(static fn (Device $d) => $d->getState($now), $devices),
            ),
        ]);
    }

    #[Route('/nouvelle', name: 'admin_device_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $event = $this->events->findActive();
        if (null === $event) {
            $this->addFlash('error', 'Activez d\'abord un événement : chaque tablette est rattachée à l\'événement actif.');

            return $this->redirectToRoute('admin_event_index');
        }

        $values = ['name' => '', 'role' => ''];
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->denyUnlessCsrfValid($request, 'device-form');
            $payload = $request->getPayload();
            $values = ['name' => trim($payload->getString('name')), 'role' => $payload->getString('role')];
            $role = DeviceRole::tryFrom($values['role']);

            if ('' === $values['name']) {
                $errors['name'] = 'Donnez un nom à la tablette, par exemple « Caisse buvette ».';
            } elseif (mb_strlen($values['name']) > self::NAME_MAX_LENGTH) {
                $errors['name'] = \sprintf('Le nom ne doit pas dépasser %d caractères.', self::NAME_MAX_LENGTH);
            }
            if (null === $role) {
                $errors['role'] = 'Choisissez le rôle de la tablette.';
            }

            if (!$errors) {
                $device = new Device($event, $values['name'], $role);
                $code = $this->pairing->generateCode($device);
                $this->entityManager->persist($device);
                $this->entityManager->flush();
                $this->auditLogger->log('device.create', 'device:'.$device->getId().' '.$device->getName(), after: ['role' => $role->value]);
                $this->entityManager->flush();

                return $this->showCodeOnce($request, $device, $code);
            }
        }

        return $this->render('admin/device/form.html.twig', [
            'event' => $event,
            'values' => $values,
            'errors' => $errors,
            'roles' => DeviceRole::cases(),
        ], new Response(status: $errors ? 422 : 200));
    }

    #[Route('/{id<\d+>}/code', name: 'admin_device_code', methods: ['GET'])]
    public function code(Device $device, Request $request): Response
    {
        $session = $request->getSession();
        $codes = $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->get(self::CODE_FLASH) : [];

        return $this->render('admin/device/code.html.twig', [
            'device' => $device,
            'code' => $codes ? DevicePairing::formatCode($codes[0]) : null,
        ]);
    }

    #[Route('/{id<\d+>}/nouveau-code', name: 'admin_device_regenerate', methods: ['POST'])]
    public function regenerate(Device $device, Request $request): Response
    {
        $this->denyUnlessCsrfValid($request, 'device-regenerate-'.$device->getId());

        if ($device->isPaired() || $device->isRevoked() || !$device->getEvent()->isActive()) {
            $this->addFlash('error', 'Un nouveau code ne peut être créé que pour une tablette en attente d\'appairage de l\'événement actif.');

            return $this->redirectToRoute('admin_device_index');
        }

        $code = $this->pairing->generateCode($device);
        $this->auditLogger->log('device.code', 'device:'.$device->getId().' '.$device->getName());
        $this->entityManager->flush();

        return $this->showCodeOnce($request, $device, $code);
    }

    #[Route('/{id<\d+>}/revoquer', name: 'admin_device_revoke', methods: ['POST'])]
    public function revoke(Device $device, Request $request): Response
    {
        $this->denyUnlessCsrfValid($request, 'device-revoke-'.$device->getId());

        if (!$device->isRevoked()) {
            $device->setRevokedAt(new \DateTimeImmutable());
            $device->setPairingCodeHash(null);
            $device->setPairingCodeExpiresAt(null);
            $this->auditLogger->log('device.revoke', 'device:'.$device->getId().' '.$device->getName());
            $this->entityManager->flush();
            $this->addFlash('info', \sprintf('La tablette « %s » est révoquée : elle ne peut plus utiliser l\'application.', $device->getName()));
        }

        return $this->redirectToRoute('admin_device_index');
    }

    /** Redirection, pour qu'un rechargement de la page ne recrée pas de tablette ni de code. */
    private function showCodeOnce(Request $request, Device $device, string $code): Response
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->set(self::CODE_FLASH, [$code]);
        }

        return $this->redirectToRoute('admin_device_code', ['id' => $device->getId()]);
    }

    private function denyUnlessCsrfValid(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }
}
