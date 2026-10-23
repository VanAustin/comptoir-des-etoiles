<?php

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Repository\EventRepository;
use App\Service\AuditLogger;
use App\Service\Logbook;
use App\Service\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Carnet de bord : chiffres de l'événement, ventes par article, historique et annulation. */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/carnet')]
class LogbookController extends AbstractController
{
    public const PER_PAGE = 100;
    public const STATUS_LABELS = [
        'new' => 'Nouvelle',
        'prep' => 'En préparation',
        'ready' => 'Prête',
        'served' => 'Remise',
        'cancelled' => 'Annulée',
    ];

    public function __construct(
        private readonly EventRepository $events,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'admin_logbook', methods: ['GET'])]
    public function index(Request $request, Logbook $logbook): Response
    {
        $allEvents = $this->events->findAllByDateDesc();
        $event = $this->selectedEvent($request, $allEvents);
        if (null === $event) {
            return $this->render('admin/logbook/no_event.html.twig');
        }

        $summary = $logbook->summarize($event);

        // Historique : filtres par état, caisse et numéro, puis pages de 100
        $filters = [
            'etat' => $request->query->getString('etat'),
            'caisse' => strtoupper($request->query->getString('caisse')),
            'numero' => strtoupper(preg_replace('/\s+/', '', $request->query->getString('numero')) ?? ''),
        ];
        $history = array_values(array_filter($summary['orders'], static fn (Order $o): bool => ('' === $filters['etat'] || $o->getStatus()->value === $filters['etat'])
            && ('' === $filters['caisse'] || $o->getTillLetter() === $filters['caisse'])
            && ('' === $filters['numero'] || str_contains($o->getDisplayNumber(), $filters['numero']))));
        $pages = max(1, (int) ceil(\count($history) / self::PER_PAGE));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));

        return $this->render('admin/logbook/index.html.twig', [
            'event' => $event,
            'events' => $allEvents,
            'totals' => $summary['totals'],
            'tills' => $summary['tills'],
            'shortfalls' => $summary['shortfalls'],
            'articles' => $summary['articles'],
            'history' => \array_slice($history, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'history_count' => \count($history),
            'page' => $page,
            'pages' => $pages,
            'filters' => $filters,
            'letters' => array_keys($summary['tills']),
            'status_labels' => self::STATUS_LABELS,
        ]);
    }

    /** Annulation, confirmée dans la page : la commande sort de la cuisine, des Arrivées et des chiffres. */
    #[Route('/commande/{id<\d+>}/annuler', name: 'admin_logbook_cancel', methods: ['GET', 'POST'])]
    public function cancel(Order $order, Request $request): Response
    {
        $back = $this->generateUrl('admin_logbook', ['evenement' => $order->getEvent()->getId()]);
        if (!$order->getStatus()->canBeCancelled()) {
            $this->addFlash('info', \sprintf('La commande %s est déjà annulée.', $order->getDisplayNumber()));

            return $this->redirect($back);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('order-cancel-'.$order->getId(), $request->getPayload()->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }
            $before = ['status' => $order->getStatus()->value, 'totalCents' => $order->getTotalCents(), 'paymentMethod' => $order->getPaymentMethod()->value];
            $order->setStatus(OrderStatus::Cancelled);
            $this->auditLogger->log('order.cancel', 'order:'.$order->getId().' '.$order->getDisplayNumber(), before: $before, after: ['status' => OrderStatus::Cancelled->value]);
            $this->entityManager->flush();
            $this->addFlash('info', \sprintf('La commande %s est annulée. %s', $order->getDisplayNumber(), self::refundHint($order)));

            return $this->redirect($back);
        }

        return $this->render('admin/logbook/cancel.html.twig', [
            'order' => $order,
            'refund_hint' => self::refundHint($order),
            'back' => $back,
        ]);
    }

    /** @param list<Event> $events */
    private function selectedEvent(Request $request, array $events): ?Event
    {
        $id = $request->query->getInt('evenement');
        foreach ($events as $event) {
            if ($event->getId() === $id) {
                return $event;
            }
        }

        return $this->events->findActive() ?? $events[0] ?? null;
    }

    private static function refundHint(Order $order): string
    {
        $total = $order->getTotalCents();
        if ($total <= 0) {
            return 'Elle ne comptait que des gobelets rendus : reprenez l\'argent rendu si besoin.';
        }

        return \sprintf('Rendez %s € au client %s si besoin.', Money::formatEuros($total), 'card' === $order->getPaymentMethod()->value ? 'par le terminal de carte' : 'en espèces');
    }
}
