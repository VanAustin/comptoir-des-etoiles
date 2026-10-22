<?php

namespace App\Repository;

use App\Entity\Event;
use App\Entity\Order;
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

    public function numberExists(Event $event, string $tillLetter, int $number): bool
    {
        return null !== $this->findOneBy(['event' => $event, 'tillLetter' => $tillLetter, 'number' => $number]);
    }
}
