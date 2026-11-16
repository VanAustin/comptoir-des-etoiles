<?php

namespace App\Controller\Admin;

use App\Entity\Device;
use App\Entity\Event;
use App\Repository\DeviceRepository;
use App\Repository\EventRepository;
use App\Repository\OrderRepository;
use App\Service\AuditLogger;
use App\Service\Money;
use App\Service\Revisions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/evenements')]
class EventController extends AbstractController
{
    private const NAME_MAX_LENGTH = 100;
    private const DEPOSIT_MAX_CENTS = 10000;

    public function __construct(
        private readonly EventRepository $events,
        private readonly OrderRepository $orders,
        private readonly DeviceRepository $devices,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Revisions $revisions,
    ) {
    }

    #[Route('', name: 'admin_event_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/event/index.html.twig', [
            'events' => $this->events->findAllByDateDesc(),
        ]);
    }

    #[Route('/nouveau', name: 'admin_event_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $values = ['name' => '', 'date' => '', 'deposit' => '0,00', 'kitchen' => true, 'table' => false];
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->denyUnlessCsrfValid($request, 'event-form');
            [$values, $errors, $name, $date, $depositCents] = $this->validate($request);
            $values['table'] = self::tableService($values);

            if (!$errors) {
                $event = (new Event($name, $date))->setDepositCents($depositCents)->setWithKitchen($values['kitchen'])->setTableService($values['table']);
                $this->entityManager->persist($event);
                $this->auditLogger->log('event.create', 'event:'.$name, after: self::snapshot($event));
                $this->entityManager->flush();
                $this->addFlash('info', \sprintf('L\'événement « %s » est créé.', $name));

                return $this->redirectToRoute('admin_event_index');
            }
        }

        return $this->render('admin/event/form.html.twig', [
            'event' => null,
            'values' => $values,
            'errors' => $errors,
            'kitchen_locked' => false,
            'kitchen_devices' => [],
        ], new Response(status: $errors ? 422 : 200));
    }

    #[Route('/{id<\d+>}/modifier', name: 'admin_event_edit', methods: ['GET', 'POST'])]
    public function edit(Event $event, Request $request): Response
    {
        $values = [
            'name' => $event->getName(),
            'date' => $event->getDate()->format('Y-m-d'),
            'deposit' => Money::formatEuros($event->getDepositCents()),
            'kitchen' => $event->hasKitchen(),
            'table' => $event->hasTableService(),
        ];
        $errors = [];
        // Après la première commande, changer le réglage fausserait les commandes déjà passées
        $orderCount = $this->orders->countForEvent($event);
        $kitchenLocked = $orderCount > 0;
        $kitchenDevices = $this->kitchenDevices($event);

        if ($request->isMethod('POST')) {
            $this->denyUnlessCsrfValid($request, 'event-form');
            [$values, $errors, $name, $date, $depositCents] = $this->validate($request);
            if ($kitchenLocked) {
                // La case est désactivée dans la page : la valeur envoyée est ignorée
                $values['kitchen'] = $event->hasKitchen();
            }
            $values['table'] = self::tableService($values);

            if (!$errors) {
                $before = self::snapshot($event);
                $event->setName($name)->setDate($date)->setDepositCents($depositCents)->setWithKitchen($values['kitchen'])->setTableService($values['table']);
                $this->auditLogger->log('event.update', 'event:'.$event->getId().' '.$name, $before, self::snapshot($event));
                // Nom, date, consigne, cuisine et service à table font partie du menu des caisses
                $this->revisions->flushMenu($event);
                $this->addFlash('info', \sprintf('L\'événement « %s » est enregistré.', $name));
                if (!$event->hasKitchen() && $kitchenDevices) {
                    $this->addFlash('info', \sprintf(
                        'Événement sans cuisine : %s ne %s plus. Seules les caisses fonctionnent.',
                        self::deviceList($kitchenDevices),
                        \count($kitchenDevices) > 1 ? 'fonctionnent' : 'fonctionne',
                    ));
                }

                return $this->redirectToRoute('admin_event_index');
            }
        }

        return $this->render('admin/event/form.html.twig', [
            'event' => $event,
            'values' => $values,
            'errors' => $errors,
            'kitchen_locked' => $kitchenLocked,
            'order_count' => $orderCount,
            'kitchen_devices' => $kitchenDevices,
            'kitchen_devices_text' => self::deviceList($kitchenDevices),
        ], new Response(status: $errors ? 422 : 200));
    }

    /**
     * Activation, confirmée dans la page : activer un autre événement arrête toutes les tablettes
     * de l'événement actif, et ses commandes encore gardées dans une caisse ne pourraient plus partir.
     */
    #[Route('/{id<\d+>}/activer', name: 'admin_event_activate', methods: ['GET', 'POST'])]
    public function activate(Event $event, Request $request): Response
    {
        if ($event->isActive()) {
            $this->addFlash('info', \sprintf('« %s » est déjà l\'événement actif.', $event->getName()));

            return $this->redirectToRoute('admin_event_index');
        }

        $previous = $this->events->findActive();

        if (!$request->isMethod('POST')) {
            return $this->render('admin/event/activate.html.twig', [
                'event' => $event,
                'previous' => $previous,
                'paired_devices' => null !== $previous ? $this->pairedDeviceCount($previous) : 0,
                'order_count' => null !== $previous ? $this->orders->countForEvent($previous) : 0,
            ]);
        }

        $this->denyUnlessCsrfValid($request, 'event-activate-'.$event->getId());
        $this->entityManager->wrapInTransaction(function () use ($event, $previous): void {
            $this->events->activate($event);
            $this->auditLogger->log(
                'event.activate',
                'event:'.$event->getId().' '.$event->getName(),
                ['active_event' => $previous?->getName()],
                ['active_event' => $event->getName()],
            );
        });

        $this->addFlash('info', null !== $previous
            ? \sprintf('« %s » est maintenant l\'événement actif : seules ses tablettes fonctionnent. Celles de « %s » sont arrêtées.', $event->getName(), $previous->getName())
            : \sprintf('« %s » est maintenant l\'événement actif : seules ses tablettes fonctionnent (rubrique Tablettes).', $event->getName()));

        return $this->redirectToRoute('admin_event_index');
    }

    /** Tablettes qui fonctionnent en ce moment : appairées, ni révoquées ni expirées. */
    private function pairedDeviceCount(Event $event): int
    {
        $now = new \DateTimeImmutable();

        return \count(array_filter(
            $this->devices->findForEvent($event),
            static fn (Device $device): bool => Device::STATE_PAIRED === $device->getState($now),
        ));
    }

    private function denyUnlessCsrfValid(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    /**
     * @return array{array{name: string, date: string, deposit: string, kitchen: bool, table: bool}, array<string, string>, string, ?\DateTimeImmutable, int}
     */
    private function validate(Request $request): array
    {
        $payload = $request->getPayload();
        $values = [
            'name' => trim($payload->getString('name')),
            'date' => trim($payload->getString('date')),
            'deposit' => trim($payload->getString('deposit')),
            // Case à cocher : absente du formulaire envoyé quand elle est décochée
            'kitchen' => $payload->has('with_kitchen'),
            'table' => $payload->has('table_service'),
        ];
        $errors = [];

        if ('' === $values['name']) {
            $errors['name'] = 'Indiquez le nom de l\'événement, par exemple « Loto de Noël ».';
        } elseif (mb_strlen($values['name']) > self::NAME_MAX_LENGTH) {
            $errors['name'] = \sprintf('Le nom ne doit pas dépasser %d caractères.', self::NAME_MAX_LENGTH);
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $values['date']) ?: null;
        if (null === $date || $date->format('Y-m-d') !== $values['date']) {
            $date = null;
            $errors['date'] = 'Choisissez la date de l\'événement.';
        }

        // Champ vide : pas de consigne
        $depositCents = '' === $values['deposit'] ? 0 : Money::parseEuros($values['deposit']);
        if (null === $depositCents || $depositCents > self::DEPOSIT_MAX_CENTS) {
            $depositCents = 0;
            $errors['deposit'] = 'Indiquez un montant en euros, par exemple 1 ou 1,50 (0 si pas de consigne).';
        }

        return [$values, $errors, $values['name'], $date, $depositCents];
    }

    /**
     * Sans cuisine, tout est remis au comptoir : pas de service à table (la case est désactivée dans la page).
     *
     * @param array{kitchen: bool, table: bool} $values
     */
    private static function tableService(array $values): bool
    {
        return $values['table'] && $values['kitchen'];
    }

    /** @return array<string, mixed> */
    private static function snapshot(Event $event): array
    {
        return [
            'name' => $event->getName(),
            'date' => $event->getDate()->format('Y-m-d'),
            'deposit_cents' => $event->getDepositCents(),
            'with_kitchen' => $event->hasKitchen(),
            'table_service' => $event->hasTableService(),
        ];
    }

    /**
     * Tablettes cuisine et affichage encore utilisables (appairées ou en attente de leur code),
     * qui ne fonctionnent pas lors d'un événement sans cuisine.
     *
     * @return list<Device>
     */
    private function kitchenDevices(Event $event): array
    {
        $now = new \DateTimeImmutable();

        return array_values(array_filter(
            $this->devices->findForEvent($event),
            static fn (Device $d): bool => $d->getRole()->needsKitchen()
                && \in_array($d->getState($now), [Device::STATE_PAIRED, Device::STATE_WAITING], true),
        ));
    }

    /**
     * « la tablette « Cuisine 1 » » ou « 2 tablettes (« Cuisine 1 », « Écran salle ») ».
     *
     * @param list<Device> $devices
     */
    private static function deviceList(array $devices): string
    {
        $names = array_map(static fn (Device $d): string => '« '.$d->getName().' »', $devices);

        return 1 === \count($names) ? 'la tablette '.$names[0] : \sprintf('%d tablettes (%s)', \count($names), implode(', ', $names));
    }
}
