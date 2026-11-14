<?php

namespace App\Controller\Api;

use App\Entity\Device;
use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use App\Service\Revisions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Suivi des commandes à la caisse : où en est la commande d'un client, et
 * remise au comptoir d'une commande prête. Numéros et états seulement, ni
 * montant ni article : le détail des commandes reste à la cuisine.
 */
#[IsGranted('ROLE_TILL')]
class TillTrackingController extends AbstractController
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    #[Route('/api/till/orders', name: 'api_till_orders', methods: ['GET'])]
    public function orders(#[CurrentUser] Device $device, Request $request, PollResponse $poll, OrderRepository $orders): Response
    {
        $event = $device->getEvent();

        return $poll->create($request, $event, false, static fn (): array => [
            'orders' => array_map(static fn (Order $o): array => self::describe($o, $device), $orders->findOpen($event)),
        ]);
    }

    /** Le client récupère au comptoir une commande prête. Déjà remise : rien ne change. */
    #[Route('/api/till/orders/{uuid}/served', name: 'api_till_orders_served', requirements: ['uuid' => self::UUID], methods: ['POST'])]
    public function served(#[CurrentUser] Device $device, string $uuid, OrderRepository $orders, Revisions $revisions): JsonResponse
    {
        $order = $orders->findOneInEvent($device->getEvent(), $uuid);
        if (null === $order) {
            return new JsonResponse(['error' => 'Commande introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $status = $order->getStatus();
        if (OrderStatus::Ready === $status) {
            $order->setStatus(OrderStatus::Served);
            $revisions->flushOrders($device->getEvent());
        } elseif (OrderStatus::Served !== $status) {
            $message = OrderStatus::Cancelled === $status
                ? \sprintf('La commande %s a été annulée.', $order->getDisplayNumber())
                : \sprintf('La commande %s n\'est pas encore prête.', $order->getDisplayNumber());

            return new JsonResponse(['error' => $message, 'order' => self::describe($order, $device)], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(self::describe($order, $device));
    }

    /** @return array<string, mixed> */
    private static function describe(Order $order, Device $device): array
    {
        return [
            'uuid' => $order->getUuid(),
            'displayNumber' => $order->getDisplayNumber(),
            'status' => $order->getStatus()->value,
            'createdAt' => $order->getCreatedAt()->format(\DATE_ATOM),
            // Dernier changement d'état : pour une commande prête, l'heure où elle l'est devenue
            'updatedAt' => $order->getUpdatedAt()->format(\DATE_ATOM),
            'mine' => $order->getTillLetter() === $device->getTillLetter(),
            // Service à table : un bénévole apporte le plat, pas de remise au comptoir
            'tableNumber' => $order->getTableNumber(),
        ];
    }
}
