<?php

namespace App\Entity;

use App\Repository\OrderLineRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Libellé, prix et passage en cuisine sont figés au moment de la vente :
 * modifier le menu ensuite ne change pas les commandes passées.
 */
#[ORM\Entity(repositoryClass: OrderLineRepository::class)]
class OrderLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    /** Null si l'article a été supprimé du menu depuis la vente. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Product $product;

    #[ORM\Column(length: 80)]
    private string $label;

    #[ORM\Column]
    private int $unitPriceCents;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column]
    private bool $goesToKitchen;

    public function __construct(Order $order, Product $product, int $quantity)
    {
        $this->order = $order;
        $this->product = $product;
        $this->label = $product->getName();
        $this->unitPriceCents = $product->getPriceCents();
        $this->goesToKitchen = $product->goesToKitchen();
        $this->quantity = $quantity;
        $order->addLine($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getUnitPriceCents(): int
    {
        return $this->unitPriceCents;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function goesToKitchen(): bool
    {
        return $this->goesToKitchen;
    }

    public function getTotalCents(): int
    {
        return $this->unitPriceCents * $this->quantity;
    }
}
