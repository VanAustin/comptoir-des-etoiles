<?php

namespace App\Repository;

use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /** @return list<Event> les plus récents en premier */
    public function findAllByDateDesc(): array
    {
        return $this->findBy([], ['date' => 'DESC', 'id' => 'DESC']);
    }

    public function findActive(): ?Event
    {
        return $this->findOneBy(['active' => true]);
    }

    /** Un seul événement actif à la fois : activer l'un désactive les autres. */
    public function activate(Event $event): void
    {
        $this->createQueryBuilder('e')
            ->update()
            ->set('e.active', ':inactive')
            ->where('e.id != :id')
            ->setParameter('inactive', false)
            ->setParameter('id', $event->getId())
            ->getQuery()
            ->execute();

        $event->setActive(true);
    }
}
