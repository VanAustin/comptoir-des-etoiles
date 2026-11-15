<?php

namespace App\Controller\Admin;

use App\Entity\Device;
use App\Entity\Event;
use App\Enum\DeviceRole;
use App\Repository\DeviceRepository;
use App\Repository\EventRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Service\AuditLogger;
use App\Service\DevicePairing;
use App\Service\Revisions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
        private readonly ProductRepository $products,
        private readonly DevicePairing $pairing,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Revisions $revisions,
        private readonly OrderRepository $orders,
    ) {
    }

    #[Route('', name: 'admin_device_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $event = $this->events->findActive();
        $devices = $event ? $this->devices->findForEvent($event) : [];
        $now = new \DateTimeImmutable();
        $states = array_combine(
            array_map(static fn (Device $d) => $d->getId(), $devices),
            array_map(static fn (Device $d) => $d->getState($now), $devices),
        );
        // Tablettes révoquées : masquées par défaut, affichées sur demande (?retirees=1)
        $revoked = array_values(array_filter($devices, static fn (Device $d): bool => Device::STATE_REVOKED === $states[$d->getId()]));
        $showRevoked = $request->query->getBoolean('retirees');
        $listed = $showRevoked ? $devices : array_values(array_filter($devices, static fn (Device $d): bool => Device::STATE_REVOKED !== $states[$d->getId()]));
        $withOrders = $this->orders->devicesWithOrders($listed);

        return $this->render('admin/device/index.html.twig', [
            'event' => $event,
            'devices' => $listed,
            'revoked_count' => \count($revoked),
            'show_revoked' => $showRevoked,
            'unsold_categories' => $event ? $this->unsoldCategories($event, $devices, $now) : [],
            'states' => $states,
            'deletable' => array_fill_keys(array_map(
                static fn (Device $d) => $d->getId(),
                array_filter($listed, static fn (Device $d): bool => self::canDelete($d, isset($withOrders[$d->getId()]))),
            ), true),
        ]);
    }

    /**
     * Suppression, confirmée dans la page. Seulement une tablette sans commande, jamais appairée,
     * ou cuisine ou Arrivées : une caisse appairée peut garder des commandes hors ligne pas encore
     * envoyées, et sa lettre ne doit jamais être réattribuée pendant l'événement (on la révoque).
     */
    #[Route('/{id<\d+>}/supprimer', name: 'admin_device_delete', methods: ['GET', 'POST'])]
    public function delete(Device $device, Request $request): Response
    {
        if (!self::canDelete($device, $this->orders->hasOrdersFromDevice($device))) {
            $this->addFlash('error', \sprintf('La tablette « %s » ne peut pas être supprimée : %s', $device->getName(), self::whyNotDeletable($device)));

            return $this->redirectToRoute('admin_device_index');
        }

        if ($request->isMethod('POST')) {
            $this->denyUnlessCsrfValid($request, 'device-delete-'.$device->getId());
            $name = $device->getName();
            $this->auditLogger->log('device.delete', 'device:'.$device->getId().' '.$name, before: [
                'role' => $device->getRole()->value,
                'paired' => $device->isPaired(),
                'revoked' => $device->isRevoked(),
            ]);
            $this->entityManager->remove($device);
            $this->entityManager->flush();
            $this->addFlash('info', \sprintf('La tablette « %s » est supprimée.', $name));

            return $this->redirectToRoute('admin_device_index');
        }

        return $this->render('admin/device/delete.html.twig', [
            'device' => $device,
            'state' => $device->getState(new \DateTimeImmutable()),
        ]);
    }

    private static function canDelete(Device $device, bool $hasOrders): bool
    {
        return !$hasOrders && (!$device->isPaired() || DeviceRole::Till !== $device->getRole());
    }

    private static function whyNotDeletable(Device $device): string
    {
        return DeviceRole::Till === $device->getRole() && $device->isPaired()
            ? 'une caisse appairée se révoque, elle ne se supprime pas (commandes peut-être encore dans le téléphone, lettre à ne jamais réattribuer). Révoquée, elle disparaît de la liste.'
            : 'elle a encaissé des commandes, que le Carnet de bord rattache à elle.';
    }

    #[Route('/nouvelle', name: 'admin_device_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $event = $this->events->findActive();
        if (null === $event) {
            $this->addFlash('error', 'Activez d\'abord un événement : chaque tablette est rattachée à l\'événement actif.');

            return $this->redirectToRoute('admin_event_index');
        }

        // Sans cuisine, seules les caisses ont un écran : le rôle est choisi d'office
        $roles = array_values(array_filter(DeviceRole::cases(), static fn (DeviceRole $r): bool => !$r->needsKitchen() || $event->hasKitchen()));
        $categories = $this->products->saleCategories($event);
        $values = ['name' => '', 'role' => 1 === \count($roles) ? $roles[0]->value : '', 'categories' => $categories, 'direct_sale' => false];
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->denyUnlessCsrfValid($request, 'device-form');
            $payload = $request->getPayload();
            $values = ['name' => trim($payload->getString('name')), 'role' => $payload->getString('role')];
            $role = DeviceRole::tryFrom($values['role']);
            // Réglages de caisse : ignorés pour les autres rôles
            [$values['categories'], $values['direct_sale'], $tillSettings, $categoryError] = $this->readTillSettings($request, $categories, DeviceRole::Till === $role);
            if (null !== $categoryError) {
                $errors['categories'] = $categoryError;
            }

            if ('' === $values['name']) {
                $errors['name'] = 'Donnez un nom à la tablette, par exemple « Caisse buvette ».';
            } elseif (mb_strlen($values['name']) > self::NAME_MAX_LENGTH) {
                $errors['name'] = \sprintf('Le nom ne doit pas dépasser %d caractères.', self::NAME_MAX_LENGTH);
            }
            if (null === $role) {
                $errors['role'] = 'Choisissez le rôle de la tablette.';
            } elseif (!\in_array($role, $roles, true)) {
                $errors['role'] = 'Cet événement est sans cuisine : seules les tablettes caisse sont utiles.';
            }

            if (!$errors) {
                $device = new Device($event, $values['name'], $role);
                if (DeviceRole::Till === $role) {
                    $device->setCategories($tillSettings['categories'])->setDirectSale($tillSettings['direct_sale']);
                }
                $code = $this->pairing->generateCode($device);
                $this->entityManager->persist($device);
                $this->entityManager->flush();
                $this->auditLogger->log('device.create', 'device:'.$device->getId().' '.$device->getName(), after: ['role' => $role->value] + (DeviceRole::Till === $role ? self::tillSnapshot($device) : []));
                $this->entityManager->flush();

                return $this->showCodeOnce($request, $device, $code);
            }
        }

        return $this->render('admin/device/form.html.twig', [
            'event' => $event,
            'values' => $values,
            'errors' => $errors,
            'roles' => $roles,
            'categories' => $categories,
        ], new Response(status: $errors ? 422 : 200));
    }

    /** Réglages d'une caisse, modifiables à tout moment : elle les relit avec le menu. */
    #[Route('/{id<\d+>}/reglages', name: 'admin_device_settings', methods: ['GET', 'POST'])]
    public function settings(Device $device, Request $request): Response
    {
        if (DeviceRole::Till !== $device->getRole() || $device->isRevoked() || $device->getEvent() !== $this->events->findActive()) {
            throw $this->createNotFoundException('Seule une caisse de l\'événement actif, non révoquée, a des réglages.');
        }

        // Catégories du menu, et celles que la caisse vend encore alors qu'elles n'y sont plus (renommées)
        $categories = array_values(array_unique([...$this->products->saleCategories($device->getEvent()), ...$device->getCategories()]));
        $values = [
            'categories' => [] === $device->getCategories() ? $categories : $device->getCategories(),
            'direct_sale' => $device->isDirectSale(),
        ];
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->denyUnlessCsrfValid($request, 'device-settings-'.$device->getId());
            [$values['categories'], $values['direct_sale'], $settings, $categoryError] = $this->readTillSettings($request, $categories, true);
            if (null !== $categoryError) {
                $errors['categories'] = $categoryError;
            } else {
                $before = self::tillSnapshot($device);
                $device->setCategories($settings['categories'])->setDirectSale($settings['direct_sale']);
                if ($before !== self::tillSnapshot($device)) {
                    $this->auditLogger->log('device.update', 'device:'.$device->getId().' '.$device->getName(), $before, self::tillSnapshot($device));
                }
                // Catégories vendues et poste de vente font partie du menu de cette caisse
                $this->revisions->flushMenu($device->getEvent());
                $this->addFlash('info', \sprintf('Les réglages de « %s » sont enregistrés. La caisse les applique d\'ici 30 secondes.', $device->getName()));

                return $this->redirectToRoute('admin_device_index');
            }
        }

        return $this->render('admin/device/settings.html.twig', [
            'device' => $device,
            'values' => $values,
            'errors' => $errors,
            'categories' => $categories,
        ], new Response(status: $errors ? 422 : 200));
    }

    #[Route('/{id<\d+>}/code', name: 'admin_device_code', methods: ['GET'])]
    public function code(Device $device, Request $request): Response
    {
        $session = $request->getSession();
        $codes = $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->get(self::CODE_FLASH) : [];

        $now = new \DateTimeImmutable();
        $code = $codes ? DevicePairing::formatCode($codes[0]) : null;
        $expiresAt = $device->getPairingCodeExpiresAt();

        return $this->render('admin/device/code.html.twig', [
            'device' => $device,
            'code' => $code,
            // Lien scanné avec l'appareil photo de la tablette : le code est après le #, jamais envoyé au serveur
            'pair_url' => null !== $code ? $this->generateUrl('app_pair', [], UrlGeneratorInterface::ABSOLUTE_URL).'#code='.$code : null,
            // Calculé par le serveur : l'horloge de l'ordinateur de l'administration peut être fausse
            'remaining_seconds' => null !== $expiresAt ? max(0, $expiresAt->getTimestamp() - $now->getTimestamp()) : 0,
            'can_regenerate' => !$device->isPaired() && !$device->isRevoked() && $device->getEvent()->isActive(),
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

    /**
     * Catégories du menu qu'aucune caisse en service (appairée ou en attente) ne vend.
     * Aucune caisse : rien à signaler, l'installation n'est pas commencée.
     *
     * @param list<Device> $devices
     *
     * @return list<string>
     */
    private function unsoldCategories(Event $event, array $devices, \DateTimeImmutable $now): array
    {
        $tills = array_filter($devices, static fn (Device $d): bool => DeviceRole::Till === $d->getRole() && \in_array($d->getState($now), ['waiting', 'paired'], true));
        if ([] === $tills) {
            return [];
        }
        $sold = [];
        foreach ($tills as $till) {
            if ([] === $till->getCategories()) {
                return [];
            }
            $sold = [...$sold, ...$till->getCategories()];
        }

        return array_values(array_diff($this->products->saleCategories($event), $sold));
    }

    /**
     * Catégories cochées et vente directe. Toutes cochées : la caisse vend tout le menu,
     * y compris les catégories ajoutées plus tard (liste vide).
     *
     * @param list<string> $offered
     *
     * @return array{list<string>, bool, array{categories: list<string>, direct_sale: bool}, ?string}
     */
    private function readTillSettings(Request $request, array $offered, bool $isTill): array
    {
        $payload = $request->getPayload();
        $checked = array_values(array_filter($payload->all('categories'), static fn ($c): bool => \is_string($c) && \in_array($c, $offered, true)));
        $directSale = $payload->has('direct_sale');
        $error = $isTill && [] !== $offered && [] === $checked ? 'Cochez au moins une catégorie : une caisse sans catégorie n\'aurait rien à vendre.' : null;
        $all = [] === array_diff($offered, $checked);

        return [$checked, $directSale, ['categories' => $all ? [] : $checked, 'direct_sale' => $directSale], $error];
    }

    /** @return array{categories: string, direct_sale: bool} */
    private static function tillSnapshot(Device $device): array
    {
        return [
            'categories' => [] === $device->getCategories() ? 'tout le menu' : implode(', ', $device->getCategories()),
            'direct_sale' => $device->isDirectSale(),
        ];
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
