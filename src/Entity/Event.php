<?php

namespace App\Entity;

use App\Repository\EventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EventRepository::class)]
class Event
{
    public const DEFAULT_ROWS_PER_ZONE = 3;
    public const MIN_ROWS_PER_ZONE = 1;
    public const MAX_ROWS_PER_ZONE = 9;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(name: 'event_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column]
    private bool $active = false;

    /** Montant de la consigne (écocup…) en centimes ; 0 = pas de consigne. */
    #[ORM\Column(options: ['default' => 0])]
    private int $depositCents = 0;

    /**
     * Sans cuisine (stand de Noël…) : toute commande est servie au comptoir,
     * seules les tablettes caisse fonctionnent.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $withKitchen = true;

    /**
     * Service à table (Loto) : la caisse propose un numéro de table. Seulement avec cuisine.
     * Ne fait qu'afficher ou masquer le bouton « Table » : un numéro déjà saisi est toujours gardé.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $tableService = false;

    /**
     * Service à table : zones des livreurs. La rangée est le chiffre des centaines du numéro
     * de table (304 : rangée 3) ; avec 3 rangées par zone, rangées 1 à 3 = zone 1, 4 à 6 = zone 2…
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => self::DEFAULT_ROWS_PER_ZONE, 'unsigned' => true])]
    private int $rowsPerZone = self::DEFAULT_ROWS_PER_ZONE;

    /**
     * Numéros de révision, augmentés de 1 à chaque changement des commandes ou du menu, dans
     * la même transaction (App\Service\Revisions, jamais en PHP). Les tablettes interrogent
     * le serveur toutes les quelques secondes : tant que la révision n'a pas changé, il répond
     * 304 sans relire les commandes (App\Controller\Api\PollResponse).
     */
    #[ORM\Column(options: ['default' => 0, 'unsigned' => true])]
    private int $ordersRevision = 0;

    #[ORM\Column(options: ['default' => 0, 'unsigned' => true])]
    private int $menuRevision = 0;

    public function __construct(string $name, \DateTimeImmutable $date)
    {
        $this->name = $name;
        $this->date = $date;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getDepositCents(): int
    {
        return $this->depositCents;
    }

    public function setDepositCents(int $depositCents): static
    {
        $this->depositCents = $depositCents;

        return $this;
    }

    public function hasKitchen(): bool
    {
        return $this->withKitchen;
    }

    public function setWithKitchen(bool $withKitchen): static
    {
        $this->withKitchen = $withKitchen;

        return $this;
    }

    /** Vrai seulement avec cuisine : sans cuisine, tout est servi au comptoir. */
    public function hasTableService(): bool
    {
        return $this->withKitchen && $this->tableService;
    }

    public function setTableService(bool $tableService): static
    {
        $this->tableService = $tableService;

        return $this;
    }

    public function getRowsPerZone(): int
    {
        return $this->rowsPerZone;
    }

    public function setRowsPerZone(int $rowsPerZone): static
    {
        if ($rowsPerZone < self::MIN_ROWS_PER_ZONE || $rowsPerZone > self::MAX_ROWS_PER_ZONE) {
            throw new \InvalidArgumentException(\sprintf('Rangées par zone : de %d à %d.', self::MIN_ROWS_PER_ZONE, self::MAX_ROWS_PER_ZONE));
        }
        $this->rowsPerZone = $rowsPerZone;

        return $this;
    }

    /**
     * Zone du livreur pour une table (304 : rangée 3, zone 1 avec 3 rangées par zone),
     * ou null pour un numéro sans rangée (1 à 99) : pas de zone, le numéro de table suffit.
     */
    public function deliveryZone(int $tableNumber): ?int
    {
        $row = intdiv($tableNumber, 100);

        return $row < 1 ? null : intdiv($row - 1, $this->rowsPerZone) + 1;
    }

    /** Lecture seule : l'augmentation se fait en SQL, voir App\Service\Revisions. */
    public function getOrdersRevision(): int
    {
        return $this->ordersRevision;
    }

    public function getMenuRevision(): int
    {
        return $this->menuRevision;
    }
}
