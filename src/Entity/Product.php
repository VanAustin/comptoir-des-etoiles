<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Index(columns: ['event_id', 'position'])]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\Column(length: 80)]
    private string $name;

    /** Émoji, ou plus tard chemin d'une image. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(length: 40)]
    private string $category = 'Divers';

    /** Prix en centimes. */
    #[ORM\Column]
    private int $priceCents = 0;

    /** Stock de départ ; null = article non compté (café, thé…). */
    #[ORM\Column(nullable: true)]
    private ?int $initialStock = null;

    #[ORM\Column]
    private bool $goesToKitchen = false;

    /**
     * Vendu avec consigne : la caisse ajoute automatiquement une consigne
     * (montant fixé sur l'événement) par article vendu.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $hasDeposit = false;

    #[ORM\Column]
    private int $position = 0;

    /**
     * Composants d'une formule : vendre la formule décompte le stock de chacun d'eux.
     *
     * @var Collection<int, Product>
     */
    #[ORM\ManyToMany(targetEntity: self::class)]
    #[ORM\JoinTable(name: 'product_component')]
    #[ORM\JoinColumn(name: 'formula_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'component_id', onDelete: 'CASCADE')]
    private Collection $components;

    public function __construct(Event $event, string $name)
    {
        $this->event = $event;
        $this->name = $name;
        $this->components = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): Event
    {
        return $this->event;
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

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getPriceCents(): int
    {
        return $this->priceCents;
    }

    public function setPriceCents(int $priceCents): static
    {
        $this->priceCents = $priceCents;

        return $this;
    }

    public function getInitialStock(): ?int
    {
        return $this->initialStock;
    }

    public function setInitialStock(?int $initialStock): static
    {
        $this->initialStock = $initialStock;

        return $this;
    }

    public function goesToKitchen(): bool
    {
        return $this->goesToKitchen;
    }

    public function setGoesToKitchen(bool $goesToKitchen): static
    {
        $this->goesToKitchen = $goesToKitchen;

        return $this;
    }

    public function hasDeposit(): bool
    {
        return $this->hasDeposit;
    }

    public function setHasDeposit(bool $hasDeposit): static
    {
        $this->hasDeposit = $hasDeposit;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function isFormula(): bool
    {
        return !$this->components->isEmpty();
    }

    /** @return Collection<int, Product> */
    public function getComponents(): Collection
    {
        return $this->components;
    }

    public function addComponent(self $component): static
    {
        if ($component !== $this && !$this->components->contains($component)) {
            $this->components->add($component);
        }

        return $this;
    }

    public function removeComponent(self $component): static
    {
        $this->components->removeElement($component);

        return $this;
    }
}
