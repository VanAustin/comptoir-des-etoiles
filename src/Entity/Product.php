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
     * Composants d'une formule et leurs quantités : vendre la formule décompte
     * le stock de chacun d'eux. Vide pour un article simple.
     *
     * @var Collection<int, ProductComponent>
     */
    #[ORM\OneToMany(targetEntity: ProductComponent::class, mappedBy: 'formula', cascade: ['persist'], orphanRemoval: true)]
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

    /** @return Collection<int, ProductComponent> */
    public function getComponents(): Collection
    {
        return $this->components;
    }

    /**
     * Fixe la quantité d'un article dans la formule ; 0 le retire.
     * Une formule ne se contient pas elle-même.
     */
    public function setComponentQuantity(self $component, int $quantity): static
    {
        if ($component === $this) {
            return $this;
        }
        foreach ($this->components as $existing) {
            if ($existing->getComponent() === $component) {
                if ($quantity > 0) {
                    $existing->setQuantity($quantity);
                } else {
                    $this->components->removeElement($existing);
                }

                return $this;
            }
        }
        if ($quantity > 0) {
            $this->components->add(new ProductComponent($this, $component, $quantity));
        }

        return $this;
    }

    public function addComponent(self $component, int $quantity = 1): static
    {
        return $this->setComponentQuantity($component, $quantity);
    }

    public function removeComponent(self $component): static
    {
        return $this->setComponentQuantity($component, 0);
    }

    /** Quantité d'un article dans la formule ; 0 s'il n'en fait pas partie. */
    public function componentQuantity(self $component): int
    {
        foreach ($this->components as $existing) {
            if ($existing->getComponent() === $component) {
                return $existing->getQuantity();
            }
        }

        return 0;
    }

    /** « 2 × Crêpe sucre, 1 × Soda » */
    public function describeComposition(): string
    {
        return implode(', ', array_map(
            static fn (ProductComponent $c): string => $c->getQuantity().' × '.$c->getComponent()->getName(),
            $this->components->toArray(),
        ));
    }
}
