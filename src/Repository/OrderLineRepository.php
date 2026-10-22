<?php

namespace App\Repository;

use App\Entity\Event;
use App\Entity\OrderLine;
use App\Enum\LineKind;
use App\Enum\OrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderLine>
 */
class OrderLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderLine::class);
    }

    /**
     * Articles vendus dans les commandes non annulées de l'événement.
     *
     * @return list<array{productId: int|null, quantity: int, componentIds: list<int>|null}>
     */
    public function findSoldArticles(Event $event): array
    {
        return $this->createQueryBuilder('l')
            ->select('IDENTITY(l.product) AS productId', 'l.quantity', 'l.componentIds')
            ->innerJoin('l.order', 'o')
            ->where('o.event = :event')
            ->andWhere('o.status != :cancelled')
            ->andWhere('l.kind = :article')
            ->setParameter('event', $event)
            ->setParameter('cancelled', OrderStatus::Cancelled)
            ->setParameter('article', LineKind::Article)
            ->getQuery()
            ->getArrayResult();
    }
}
