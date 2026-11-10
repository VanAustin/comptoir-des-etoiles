<?php

namespace App\Controller\Api;

use App\Entity\Device;
use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** API des tablettes « affichage » (écran Arrivées) : les numéros et leur état, rien d'autre. */
#[IsGranted('ROLE_DISPLAY')]
class BoardController extends AbstractController
{
    /** Nombre de commandes prêtes affichées : les plus récentes chassent les plus anciennes. */
    public const READY_SHOWN = 12;

    #[Route('/api/board', name: 'api_board', methods: ['GET'])]
    public function board(#[CurrentUser] Device $device, Request $request, PollResponse $poll, OrderRepository $orders): Response
    {
        $event = $device->getEvent();

        return $poll->create($request, $event, false, static fn (): array => self::numbers($orders->findOpen($event)));
    }

    /**
     * @param list<Order> $open
     *
     * @return array{ready: list<string>, preparing: list<string>}
     */
    private static function numbers(array $open): array
    {
        $ready = [];
        $preparing = [];
        foreach ($open as $order) {
            if (OrderStatus::Ready === $order->getStatus()) {
                $ready[] = $order;
            } else {
                $preparing[] = $order->getDisplayNumber();
            }
        }

        // Prêtes : la plus récente en premier
        usort($ready, static fn (Order $a, Order $b): int => [$b->getUpdatedAt(), $b->getId()] <=> [$a->getUpdatedAt(), $a->getId()]);
        $ready = array_map(static fn (Order $o): string => $o->getDisplayNumber(), \array_slice($ready, 0, self::READY_SHOWN));

        return ['ready' => $ready, 'preparing' => $preparing];
    }
}
