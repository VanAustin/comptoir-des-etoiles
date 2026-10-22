<?php

namespace App\Entity;

use App\Repository\EventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EventRepository::class)]
class Event
{
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
}
