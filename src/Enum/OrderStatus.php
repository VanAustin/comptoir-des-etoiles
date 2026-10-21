<?php

namespace App\Enum;

enum OrderStatus: string
{
    case New = 'new';
    case Prep = 'prep';
    case Ready = 'ready';
    case Served = 'served';
    case Cancelled = 'cancelled';

    /**
     * Transitions autorisées aux tablettes : new → prep → ready → served.
     * L'annulation n'en fait pas partie, elle est réservée à l'administration.
     */
    public function canAdvanceTo(self $next): bool
    {
        return match ($this) {
            self::New => self::Prep === $next,
            self::Prep => self::Ready === $next,
            self::Ready => self::Served === $next,
            self::Served, self::Cancelled => false,
        };
    }

    public function canBeCancelled(): bool
    {
        return self::Cancelled !== $this;
    }
}
