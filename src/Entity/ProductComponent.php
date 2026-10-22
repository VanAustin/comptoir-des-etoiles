<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Article inclus dans une formule, avec sa quantité (« 2 crêpes »).
 * Vendre la formule décompte cette quantité du stock de l'article.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_component')]
class ProductComponent
{
    public const MAX_QUANTITY = 20;

    #[ORM\Id]
    #[ORM\ManyToOne(inversedBy: 'components')]
    #[ORM\JoinColumn(name: 'formula_id', nullable: false, onDelete: 'CASCADE')]
    private Product $formula;

    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'component_id', nullable: false, onDelete: 'CASCADE')]
    private Product $component;

    #[ORM\Column(options: ['default' => 1])]
    private int $quantity;

    public function __construct(Product $formula, Product $component, int $quantity = 1)
    {
        $this->formula = $formula;
        $this->component = $component;
        $this->quantity = $quantity;
    }

    public function getFormula(): Product
    {
        return $this->formula;
    }

    public function getComponent(): Product
    {
        return $this->component;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }
}
