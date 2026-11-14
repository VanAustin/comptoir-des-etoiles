<?php

namespace App\Controller\Api;

use App\Entity\Device;
use App\Entity\Order;
use App\Entity\OrderLine;
use App\Enum\LineKind;
use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Service\Revisions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** API des tablettes « cuisine » : lire les commandes en cours, faire avancer leur état. */
#[IsGranted('ROLE_KITCHEN')]
class KitchenController extends AbstractController
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /**
     * Liste complète des commandes en cours, à chaque fois : une tablette qui a
     * manqué une réponse se recale toute seule à la suivante.
     */
    #[Route('/api/orders', name: 'api_orders_open', methods: ['GET'])]
    public function open(#[CurrentUser] Device $device, Request $request, PollResponse $poll, OrderRepository $orders, ProductRepository $products): Response
    {
        $event = $device->getEvent();

        // Noms des articles dans les formules : dépend aussi du menu
        return $poll->create($request, $event, true, static function () use ($event, $orders, $products): array {
            $names = self::productNames($products->findAllForEvent($event));

            return ['orders' => array_map(static fn (Order $o): array => self::describe($o, $names), $orders->findOpen($event))];
        });
    }

    /**
     * Fait avancer une commande : new → prep → ready → served. Corps : {"status": "prep"}.
     * Demander l'état où elle est déjà ne change rien (deux tablettes cuisine).
     */
    #[Route('/api/orders/{uuid}/status', name: 'api_orders_status', requirements: ['uuid' => self::UUID], methods: ['POST'])]
    public function status(#[CurrentUser] Device $device, string $uuid, Request $request, OrderRepository $orders, ProductRepository $products, Revisions $revisions): JsonResponse
    {
        try {
            $next = OrderStatus::tryFrom($request->toArray()['status'] ?? '');
        } catch (\Throwable) {
            $next = null;
        }
        if (null === $next || OrderStatus::New === $next || OrderStatus::Cancelled === $next) {
            return new JsonResponse(['error' => 'État demandé inconnu. Valeurs possibles : prep, ready, served.'], Response::HTTP_BAD_REQUEST);
        }

        $event = $device->getEvent();
        $order = $orders->findOneInEvent($event, $uuid);
        if (null === $order) {
            return new JsonResponse(['error' => 'Commande introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $current = $order->getStatus();
        if ($current !== $next) {
            if (!$current->canAdvanceTo($next)) {
                $names = self::productNames($products->findAllForEvent($event));

                return new JsonResponse([
                    'error' => self::refusal($order),
                    'order' => self::describe($order, $names),
                ], Response::HTTP_CONFLICT);
            }
            $order->setStatus($next);
            $revisions->flushOrders($event);
        }

        return new JsonResponse(self::describe($order, self::productNames($products->findAllForEvent($event))));
    }

    /**
     * Ce qu'il faut à la cuisine : articles à préparer (avec la composition des
     * formules) et, à part, ce qui se sert au comptoir. Ni prix ni paiement.
     *
     * @param array<int, string> $names nom des articles par identifiant
     *
     * @return array<string, mixed>
     */
    public static function describe(Order $order, array $names): array
    {
        $kitchen = [];
        $counter = [];
        foreach ($order->getLines() as $line) {
            if (LineKind::Article !== $line->getKind()) {
                continue;
            }
            if ($line->goesToKitchen()) {
                $kitchen[] = [
                    'label' => $line->getLabel(),
                    'quantity' => $line->getQuantity(),
                    'components' => self::components($line, $names),
                ];
            } else {
                $counter[] = ['label' => $line->getLabel(), 'quantity' => $line->getQuantity()];
            }
        }

        return [
            'uuid' => $order->getUuid(),
            'displayNumber' => $order->getDisplayNumber(),
            'status' => $order->getStatus()->value,
            'createdAt' => $order->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $order->getUpdatedAt()->format(\DATE_ATOM),
            // Texte brut : l'écran l'insère avec textContent, jamais comme du HTML
            'note' => $order->getNote(),
            'tableNumber' => $order->getTableNumber(),
            'kitchenLines' => $kitchen,
            'counterLines' => $counter,
        ];
    }

    /**
     * Composition d'une formule, quantités totales de la ligne :
     * 2 × « Formule Duo » (2 crêpes chacune) donne 4 × Crêpe.
     *
     * @param array<int, string> $names
     *
     * @return list<array{label: string, quantity: int}>
     */
    private static function components(OrderLine $line, array $names): array
    {
        // Composants figés pour une formule : à multiplier par le nombre de formules
        $counts = array_count_values($line->getComponentIds() ?? []);

        $components = [];
        foreach ($counts as $id => $perFormula) {
            $components[] = ['label' => $names[$id] ?? 'Article retiré du menu', 'quantity' => $perFormula * $line->getQuantity()];
        }

        return $components;
    }

    /**
     * @param list<\App\Entity\Product> $products
     *
     * @return array<int, string>
     */
    private static function productNames(array $products): array
    {
        $names = [];
        foreach ($products as $product) {
            $names[(int) $product->getId()] = $product->getName();
        }

        return $names;
    }

    private static function refusal(Order $order): string
    {
        return match ($order->getStatus()) {
            OrderStatus::Cancelled => \sprintf('La commande %s a été annulée.', $order->getDisplayNumber()),
            OrderStatus::Served => \sprintf('La commande %s a déjà été remise au client.', $order->getDisplayNumber()),
            default => \sprintf('La commande %s n\'est plus à cette étape : l\'écran est mis à jour.', $order->getDisplayNumber()),
        };
    }
}
