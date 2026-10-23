<?php

namespace App\Repository;

use App\Entity\Event;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /** @return list<Product> le menu (sans les articles retirés), dans l'ordre d'affichage */
    public function findForEvent(Event $event): array
    {
        return $this->findBy(['event' => $event, 'archivedAt' => null], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Menu et articles retirés : pour accepter une commande encaissée hors ligne
     * avant un retrait, et nommer les composants des commandes passées.
     *
     * @return list<Product>
     */
    public function findAllForEvent(Event $event): array
    {
        return $this->findBy(['event' => $event], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function nextPosition(Event $event): int
    {
        $max = $this->createQueryBuilder('p')
            ->select('MAX(p.position)')
            ->where('p.event = :event')
            ->setParameter('event', $event)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $max + 1;
    }

    /** L'article fait-il partie d'au moins une formule ? */
    public function isUsedAsComponent(Product $product): bool
    {
        return (bool) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->innerJoin('f.components', 'c')
            ->where('c.component = :product')
            ->setParameter('product', $product)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
