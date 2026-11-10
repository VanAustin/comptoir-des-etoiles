<?php

namespace App\Repository;

use App\Entity\Device;
use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Device>
 */
class DeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Device::class);
    }

    /** @return list<Device> */
    public function findForEvent(Event $event): array
    {
        return $this->findBy(['event' => $event], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Avec son événement, dans la même requête : l'authentification vérifie qu'il est actif, et
     * ses numéros de révision suffisent à répondre 304 aux interrogations (une seule lecture).
     */
    public function findOneByTokenHash(string $tokenHash): ?Device
    {
        return $this->createQueryBuilder('d')
            ->addSelect('e')
            ->join('d.event', 'e')
            ->where('d.tokenHash = :hash')
            ->setParameter('hash', $tokenHash)
            ->getQuery()
            // Révisions telles qu'en base, même si l'événement était déjà en mémoire
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    public function findOneByPairingCodeHash(string $codeHash): ?Device
    {
        return $this->findOneBy(['pairingCodeHash' => $codeHash]);
    }

    /**
     * Lettres de caisse déjà attribuées pour l'événement, y compris aux tablettes révoquées.
     *
     * @return list<string>
     */
    public function findUsedTillLetters(Event $event): array
    {
        return $this->createQueryBuilder('d')
            ->select('d.tillLetter')
            ->where('d.event = :event')
            ->andWhere('d.tillLetter IS NOT NULL')
            ->setParameter('event', $event)
            ->getQuery()
            ->getSingleColumnResult();
    }

    /** Mise à jour directe, sans flush : appelée à chaque requête de l'API. */
    public function touchLastSeen(Device $device, \DateTimeImmutable $now): void
    {
        $this->createQueryBuilder('d')
            ->update()
            ->set('d.lastSeenAt', ':now')
            ->where('d.id = :id')
            ->setParameter('now', $now, 'datetime_immutable')
            ->setParameter('id', $device->getId())
            ->getQuery()
            ->execute();
    }
}
