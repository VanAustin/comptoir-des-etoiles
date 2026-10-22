<?php

namespace App\Tests;

use App\Entity\Event;
use App\Entity\Order;
use App\Entity\OrderLine;
use App\Entity\Product;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;

/**
 * Commandes créées directement en base, pour tester les écrans qui les lisent
 * (cuisine, Arrivées) sans repasser par la caisse.
 */
trait OrderFixturesTrait
{
    private static int $nextOrderNumber = 1;

    protected static function createProduct(Event $event, string $name, bool $kitchen = false): Product
    {
        $product = (new Product(self::entityManager()->find(Event::class, $event->getId()), $name))
            ->setPriceCents(200)
            ->setGoesToKitchen($kitchen);
        self::entityManager()->persist($product);
        self::entityManager()->flush();

        return $product;
    }

    /**
     * @param array<array{Product, int}> $items articles et quantités
     */
    protected static function createOrder(Event $event, array $items, OrderStatus $status = OrderStatus::New, string $letter = 'A', ?string $createdAt = null): Order
    {
        $em = self::entityManager();
        $order = new Order(self::uuid(), $em->find(Event::class, $event->getId()), $letter, self::$nextOrderNumber++, PaymentMethod::Cash);
        foreach ($items as [$product, $quantity]) {
            OrderLine::article($order, $em->find(Product::class, $product->getId()), $quantity);
        }
        if (null !== $createdAt) {
            $order->setCreatedAt(new \DateTimeImmutable($createdAt));
        }
        $steps = match ($status) {
            OrderStatus::New => [],
            OrderStatus::Prep => [OrderStatus::Prep],
            OrderStatus::Ready => [OrderStatus::Prep, OrderStatus::Ready],
            OrderStatus::Served => [OrderStatus::Prep, OrderStatus::Ready, OrderStatus::Served],
            OrderStatus::Cancelled => [OrderStatus::Cancelled],
        };
        foreach ($steps as $step) {
            $order->setStatus($step);
        }
        $em->persist($order);
        $em->flush();

        return $order;
    }

    protected static function uuid(): string
    {
        return \sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFF), random_int(0, 0xFFF), random_int(0, 0xFFFFFFFFFFFF));
    }
}
