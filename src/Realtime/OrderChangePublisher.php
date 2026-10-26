<?php

namespace App\Realtime;

use App\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Après chaque enregistrement qui crée ou modifie une commande (caisse, cuisine,
 * remise au client, annulation), prévient les tablettes de l'événement.
 *
 * Un hub injoignable ne fait jamais échouer l'enregistrement : les tablettes
 * se recalent à leur prochaine interrogation de contrôle.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class OrderChangePublisher
{
    /** @var array<int, true> événements dont une commande a changé pendant l'enregistrement en cours */
    private array $changedEvents = [];

    /**
     * @param \Closure(): HubInterface $hub créé seulement pour publier : sans hub configuré,
     *                                     le bundle refuserait de le construire
     */
    public function __construct(
        private readonly Realtime $realtime,
        #[AutowireServiceClosure(HubInterface::class)]
        private readonly \Closure $hub,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->realtime->isEnabled()) {
            return;
        }

        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates()] as $entity) {
            if ($entity instanceof Order && null !== $entity->getEvent()->getId()) {
                $this->changedEvents[$entity->getEvent()->getId()] = true;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $events = array_keys($this->changedEvents);
        $this->changedEvents = [];

        foreach ($events as $eventId) {
            try {
                // Aucune donnée : « quelque chose a changé », la tablette relit l'API
                ($this->hub)()->publish(new Update(Realtime::ordersTopic($eventId), '{}'));
            } catch (\Throwable $e) {
                $this->logger->warning('Hub Mercure injoignable : les tablettes se recaleront à leur prochaine interrogation.', ['exception' => $e]);
            }
        }
    }
}
