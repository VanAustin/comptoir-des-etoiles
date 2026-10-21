<?php

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\Service\AuditLogger;
use App\Service\Money;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
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
        $values = ['name' => '', 'date' => '', 'deposit' => '0,00'];
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->denyUnlessCsrfValid($request, 'event-form');
            [$values, $errors, $name, $date, $depositCents] = $this->validate($request);

            if (!$errors) {
                $event = (new Event($name, $date))->setDepositCents($depositCents);
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
        ], new Response(status: $errors ? 422 : 200));
    }

    #[Route('/{id<\d+>}/modifier', name: 'admin_event_edit', methods: ['GET', 'POST'])]
    public function edit(Event $event, Request $request): Response
    {
        $values = ['name' => $event->getName(), 'date' => $event->getDate()->format('Y-m-d'), 'deposit' => Money::formatEuros($event->getDepositCents())];
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->denyUnlessCsrfValid($request, 'event-form');
            [$values, $errors, $name, $date, $depositCents] = $this->validate($request);

            if (!$errors) {
                $before = self::snapshot($event);
                $event->setName($name)->setDate($date)->setDepositCents($depositCents);
                $this->auditLogger->log('event.update', 'event:'.$event->getId().' '.$name, $before, self::snapshot($event));
                $this->entityManager->flush();
                $this->addFlash('info', \sprintf('L\'événement « %s » est enregistré.', $name));

                return $this->redirectToRoute('admin_event_index');
            }
        }

        return $this->render('admin/event/form.html.twig', [
            'event' => $event,
            'values' => $values,
            'errors' => $errors,
        ], new Response(status: $errors ? 422 : 200));
    }

    #[Route('/{id<\d+>}/activer', name: 'admin_event_activate', methods: ['POST'])]
    public function activate(Event $event, Request $request): Response
    {
        $this->denyUnlessCsrfValid($request, 'event-activate-'.$event->getId());

        $previous = $this->events->findActive();
        $this->entityManager->wrapInTransaction(function () use ($event, $previous): void {
            $this->events->activate($event);
            $this->auditLogger->log(
                'event.activate',
                'event:'.$event->getId().' '.$event->getName(),
                ['active_event' => $previous?->getName()],
                ['active_event' => $event->getName()],
            );
        });

        $this->addFlash('info', \sprintf('« %s » est maintenant l\'événement actif. Les tablettes l\'utiliseront.', $event->getName()));

        return $this->redirectToRoute('admin_event_index');
    }

    private function denyUnlessCsrfValid(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    /**
     * @return array{array{name: string, date: string, deposit: string}, array<string, string>, string, ?\DateTimeImmutable, int}
     */
    private function validate(Request $request): array
    {
        $payload = $request->getPayload();
        $values = [
            'name' => trim($payload->getString('name')),
            'date' => trim($payload->getString('date')),
            'deposit' => trim($payload->getString('deposit')),
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

    /** @return array<string, mixed> */
    private static function snapshot(Event $event): array
    {
        return ['name' => $event->getName(), 'date' => $event->getDate()->format('Y-m-d'), 'deposit_cents' => $event->getDepositCents()];
    }
}
