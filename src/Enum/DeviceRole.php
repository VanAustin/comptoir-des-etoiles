<?php

namespace App\Enum;

enum DeviceRole: string
{
    case Till = 'caisse';
    case Kitchen = 'cuisine';
    case Display = 'affichage';

    /** Rôle Symfony qui ouvre les routes de l'API réservées à ce type de tablette. */
    public function securityRole(): string
    {
        return match ($this) {
            self::Till => 'ROLE_TILL',
            self::Kitchen => 'ROLE_KITCHEN',
            self::Display => 'ROLE_DISPLAY',
        };
    }

    /** Cuisine et affichage n'ont rien à montrer lors d'un événement sans cuisine. */
    public function needsKitchen(): bool
    {
        return self::Till !== $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Till => 'Caisse',
            self::Kitchen => 'Cuisine',
            self::Display => 'Affichage',
        };
    }
}
