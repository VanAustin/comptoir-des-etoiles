<?php

namespace App\Tests;

use App\Entity\Device;
use App\Entity\Event;
use App\Enum\DeviceRole;
use App\Service\DevicePairing;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Création rapide d'événements et de tablettes pour les tests.
 */
trait FixturesTrait
{
    protected static function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected static function createEvent(string $name = 'Loto de Noël', string $date = '+30 days', bool $active = true): Event
    {
        $event = new Event($name, (new \DateTimeImmutable($date))->setTime(0, 0));
        $event->setActive($active);
        self::entityManager()->persist($event);
        self::entityManager()->flush();

        return $event;
    }

    /** @return array{Device, string} la tablette et son code d'appairage en clair */
    protected static function createDeviceWithCode(Event $event, string $name = 'Caisse buvette', DeviceRole $role = DeviceRole::Till): array
    {
        // Le gestionnaire d'entités est vidé après chaque requête du client de test : on recharge l'événement
        $event = self::entityManager()->find(Event::class, $event->getId());
        $device = new Device($event, $name, $role);
        $code = static::getContainer()->get(DevicePairing::class)->generateCode($device);
        self::entityManager()->persist($device);
        self::entityManager()->flush();

        return [$device, $code];
    }

    /** @return array{Device, string} la tablette appairée et son jeton en clair */
    protected static function createPairedDevice(Event $event, string $name = 'Caisse buvette', DeviceRole $role = DeviceRole::Till): array
    {
        [, $code] = self::createDeviceWithCode($event, $name, $role);

        return static::getContainer()->get(DevicePairing::class)->pair($code);
    }
}
