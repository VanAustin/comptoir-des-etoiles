<?php

namespace App\Entity;

use App\Enum\LineKind;
use App\Repository\OrderLineRepository;
use Doctrine\DBAL\Types\Types;
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

    #[ORM\Column(length: 20, enumType: LineKind::class, options: ['default' => 'article'])]
    private LineKind $kind = LineKind::Article;

    #[ORM\Column(length: 80)]
    private string $label;

    #[ORM\Column]
    private int $unitPriceCents;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column]
    private bool $goesToKitchen;

    /**
     * Composants d'une formule au moment de la vente (un identifiant d'article
     * par unité) : le stock reste juste même si la formule est modifiée ensuite.
     *
     * @var list<int>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $componentIds = null;

    private function __construct(Order $order, LineKind $kind, ?Product $product, string $label, int $unitPriceCents, int $quantity, bool $goesToKitchen)
    {
        $this->order = $order;
        $this->kind = $kind;
        $this->product = $product;
        $this->label = $label;
        $this->unitPriceCents = $unitPriceCents;
        $this->quantity = $quantity;
        $this->goesToKitchen = $goesToKitchen;
        $order->addLine($this);
    }

    public static function article(Order $order, Product $product, int $quantity): self
    {
        $line = new self($order, LineKind::Article, $product, $product->getName(), $product->getPriceCents(), $quantity, $product->goesToKitchen());
        if ($product->isFormula()) {
            // Un identifiant par unité : « 2 crêpes » figé comme [crêpe, crêpe]
            $ids = [];
            foreach ($product->getComponents() as $component) {
                array_push($ids, ...array_fill(0, $component->getQuantity(), (int) $component->getComponent()->getId()));
            }
            $line->componentIds = $ids;
        }

        return $line;
    }

    /** Une consigne par article « avec consigne » vendu. */
    public static function deposit(Order $order, int $depositCents, int $quantity): self
    {
        return new self($order, LineKind::Deposit, null, 'Consigne', $depositCents, $quantity, false);
    }

    /** Gobelets rendus : ligne négative. */
    public static function depositReturn(Order $order, int $depositCents, int $quantity): self
    {
        return new self($order, LineKind::DepositReturn, null, 'Retour de consigne', -$depositCents, $quantity, false);
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

    public function getKind(): LineKind
    {
        return $this->kind;
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

    /** @return list<int>|null */
    public function getComponentIds(): ?array
    {
        return $this->componentIds;
    }

    public function getTotalCents(): int
    {
        return $this->unitPriceCents * $this->quantity;
    }
}
