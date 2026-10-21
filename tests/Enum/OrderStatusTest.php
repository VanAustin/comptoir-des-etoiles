<?php

namespace App\Tests\Enum;

use App\Enum\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    /** @return iterable<string, array{OrderStatus, OrderStatus}> */
    public static function allowedTransitions(): iterable
    {
        yield 'new → prep' => [OrderStatus::New, OrderStatus::Prep];
        yield 'prep → ready' => [OrderStatus::Prep, OrderStatus::Ready];
        yield 'ready → served' => [OrderStatus::Ready, OrderStatus::Served];
    }

    #[DataProvider('allowedTransitions')]
    public function testAllowedTransition(OrderStatus $from, OrderStatus $to): void
    {
        self::assertTrue($from->canAdvanceTo($to));
    }

    public function testEveryOtherTransitionIsRefused(): void
    {
        $allowed = [];
        foreach (self::allowedTransitions() as [$from, $to]) {
            $allowed[] = $from->value.'>'.$to->value;
        }

        foreach (OrderStatus::cases() as $from) {
            foreach (OrderStatus::cases() as $to) {
                if (!\in_array($from->value.'>'.$to->value, $allowed, true)) {
                    self::assertFalse($from->canAdvanceTo($to), \sprintf('%s → %s doit être refusé', $from->value, $to->value));
                }
            }
        }
    }

    public function testTabletsCannotCancel(): void
    {
        foreach (OrderStatus::cases() as $from) {
            self::assertFalse($from->canAdvanceTo(OrderStatus::Cancelled));
        }
    }

    public function testOnlyCancelledOrderCannotBeCancelledAgain(): void
    {
        foreach (OrderStatus::cases() as $status) {
            self::assertSame(OrderStatus::Cancelled !== $status, $status->canBeCancelled());
        }
    }
}
