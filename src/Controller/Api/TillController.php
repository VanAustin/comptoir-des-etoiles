<?php

namespace App\Controller\Api;

use App\Entity\Device;
use App\Entity\Order;
use App\Entity\OrderLine;
use App\Entity\Product;
use App\Entity\ProductComponent;
use App\Menu\IconCatalog;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Service\OrderPlacement;
use App\Service\Money;
use App\Service\OrderRejectedException;
use App\Service\StockCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** API des tablettes « caisse » : lire le menu et les stocks, créer des commandes. */
#[IsGranted('ROLE_TILL')]
class TillController extends AbstractController
{
    #[Route('/api/menu', name: 'api_menu', methods: ['GET'])]
    public function menu(#[CurrentUser] Device $device, ProductRepository $products, StockCalculator $stock, OrderRepository $orders): JsonResponse
    {
        $event = $device->getEvent();
        $list = $products->findForEvent($event);
        $remaining = $stock->remaining($event, $list);

        return new JsonResponse([
            'event' => [
                'name' => $event->getName(),
                'date' => $event->getDate()->format('Y-m-d'),
                'depositCents' => $event->getDepositCents(),
                // Sans cuisine : pas de remarque ni de suivi des commandes à la caisse
                'withKitchen' => $event->hasKitchen(),
            ],
            'tillLetter' => $device->getTillLetter(),
            // La caisse reprend sa numérotation après ce numéro (tablette réinstallée…)
            'lastNumber' => $orders->lastNumber($event, (string) $device->getTillLetter()),
            'products' => array_map(static fn (Product $p): array => [
                'id' => $p->getId(),
                'name' => $p->getName(),
                // Clé d'icône du catalogue, ou null : la caisse affiche alors l'icône par défaut
                'icon' => IconCatalog::has($p->getIcon()) ? $p->getIcon() : null,
                'category' => $p->getCategory(),
                'priceCents' => $p->getPriceCents(),
                'goesToKitchen' => $event->hasKitchen() && $p->goesToKitchen(),
                'hasDeposit' => $p->hasDeposit(),
                'components' => array_values(array_map(static fn (ProductComponent $c): array => [
                    'productId' => $c->getComponent()->getId(),
                    'quantity' => $c->getQuantity(),
                ], $p->getComponents()->toArray())),
                // Information seulement : un stock épuisé ne bloque pas la vente
                'remainingStock' => $remaining[$p->getId()] ?? null,
            ], $list),
        ]);
    }

    /**
     * Enregistre une commande. Corps attendu :
     * {"uuid", "number", "paymentMethod", "amountReceivedCents", "lines": [{"productId", "quantity"}], "depositReturns", "createdAt"}.
     * Prix et totaux éventuellement envoyés sont ignorés.
     */
    #[Route('/api/orders', name: 'api_orders_create', methods: ['POST'])]
    public function create(#[CurrentUser] Device $device, Request $request, OrderPlacement $placement): JsonResponse
    {
        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'La commande envoyée est illisible.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            [$order, $created] = $placement->place($device, $payload);
        } catch (OrderRejectedException $e) {
            return new JsonResponse(['error' => $e->getMessage()] + $e->details, $e->statusCode);
        }

        return new JsonResponse(self::describe($order), $created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /** @return array<string, mixed> */
    public static function describe(Order $order): array
    {
        return [
            'uuid' => $order->getUuid(),
            'displayNumber' => $order->getDisplayNumber(),
            'tillLetter' => $order->getTillLetter(),
            'number' => $order->getNumber(),
            'status' => $order->getStatus()->value,
            'paymentMethod' => $order->getPaymentMethod()->value,
            'totalCents' => $order->getTotalCents(),
            'amountReceivedCents' => $order->getAmountReceivedCents(),
            'changeCents' => $order->getChangeCents(),
            // Billets et pièces à rendre, calculés par le serveur (référence pour la caisse)
            'changeBreakdown' => $order->getChangeCents() > 0 ? Money::changeBreakdown((int) $order->getChangeCents()) : null,
            'createdAt' => $order->getCreatedAt()->format(\DATE_ATOM),
            'note' => $order->getNote(),
            'lines' => array_map(static fn (OrderLine $l): array => [
                'kind' => $l->getKind()->value,
                'productId' => $l->getProduct()?->getId(),
                'label' => $l->getLabel(),
                'unitPriceCents' => $l->getUnitPriceCents(),
                'quantity' => $l->getQuantity(),
                'totalCents' => $l->getTotalCents(),
                'goesToKitchen' => $l->goesToKitchen(),
            ], array_values($order->getLines()->toArray())),
        ];
    }
}
