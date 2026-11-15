<?php

namespace App\Repository;

use App\Entity\Device;
use App\Entity\Event;
use App\Entity\Order;
use App\Enum\OrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function findOneByUuid(string $uuid): ?Order
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /** Dernier numéro utilisé par une lettre de caisse ; 0 si aucun. */
    public function lastNumber(Event $event, string $tillLetter): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('MAX(o.number)')
            ->where('o.event = :event')
            ->andWhere('o.tillLetter = :letter')
            ->setParameter('event', $event)
            ->setParameter('letter', $tillLetter)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Commandes en cours (nouvelle, en préparation, prête), de la plus ancienne
     * à la plus récente, avec leurs lignes.
     *
     * @return list<Order>
     */
    public function findOpen(Event $event): array
    {
        return $this->createQueryBuilder('o')
            ->addSelect('l')
            ->leftJoin('o.lines', 'l')
            ->where('o.event = :event')
            ->andWhere('o.status IN (:open)')
            ->setParameter('event', $event)
            ->setParameter('open', [OrderStatus::New->value, OrderStatus::Prep->value, OrderStatus::Ready->value])
            ->orderBy('o.createdAt', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les commandes de l'événement avec leurs lignes, de la plus récente à la plus ancienne
     * (Carnet de bord). Quelques centaines au plus pour un événement de l'association.
     *
     * @return list<Order>
     */
    public function findForEvent(Event $event): array
    {
        return $this->createQueryBuilder('o')
            ->addSelect('l')
            ->leftJoin('o.lines', 'l')
            ->where('o.event = :event')
            ->setParameter('event', $event)
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneInEvent(Event $event, string $uuid): ?Order
    {
        return $this->findOneBy(['event' => $event, 'uuid' => $uuid]);
    }

    /** Vrai dès la première commande, même annulée. */
    public function hasOrders(Event $event): bool
    {
        return null !== $this->findOneBy(['event' => $event]);
    }

    /** La tablette a-t-elle encaissé au moins une commande (annulées comprises) ? */
    public function hasOrdersFromDevice(Device $device): bool
    {
        return null !== $this->findOneBy(['device' => $device]);
    }

    /**
     * Tablettes, parmi celles données, qui ont encaissé au moins une commande, en une requête.
     *
     * @param list<Device> $devices
     *
     * @return array<int, true> identifiants
     */
    public function devicesWithOrders(array $devices): array
    {
        if ([] === $devices) {
            return [];
        }
        $ids = $this->createQueryBuilder('o')
            ->select('DISTINCT IDENTITY(o.device) AS id')
            ->where('o.device IN (:devices)')
            ->setParameter('devices', $devices)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /** Commandes de l'événement, annulées comprises. */
    public function countForEvent(Event $event): int
    {
        return $this->count(['event' => $event]);
    }

    public function numberExists(Event $event, string $tillLetter, int $number): bool
    {
        return null !== $this->findOneBy(['event' => $event, 'tillLetter' => $tillLetter, 'number' => $number]);
    }
}
