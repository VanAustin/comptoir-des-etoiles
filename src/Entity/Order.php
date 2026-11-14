<?php

namespace App\Entity;

use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le numéro affiché au client est la lettre de la caisse suivie de son
 * numéro (A12, B07) : chaque caisse peut ainsi numéroter même hors ligne.
 */
#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
#[ORM\UniqueConstraint(columns: ['event_id', 'till_letter', 'number'])]
#[ORM\Index(columns: ['event_id', 'updated_at'])]
class Order
{
    public const NOTE_MAX_LENGTH = 140;
    /** Numéros de table : 1 à 999 (rangée et table : 101 à 808). */
    public const TABLE_MIN = 1;
    public const TABLE_MAX = 999;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Identifiant créé par la tablette, garant de l'idempotence. */
    #[ORM\Column(length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\Column(length: 1)]
    private string $tillLetter;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(length: 10, enumType: PaymentMethod::class)]
    private PaymentMethod $paymentMethod;

    /** Montant remis par le client en espèces, en centimes. */
    #[ORM\Column(nullable: true)]
    private ?int $amountReceivedCents = null;

    /** Total recalculé par le serveur, en centimes. */
    #[ORM\Column]
    private int $totalCents = 0;

    #[ORM\Column(length: 10, enumType: OrderStatus::class)]
    private OrderStatus $status = OrderStatus::New;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Device $device = null;

    /** Remarque pour la cuisine (« sans oignons »), texte brut. Jamais de nom ni de téléphone. */
    #[ORM\Column(length: self::NOTE_MAX_LENGTH, nullable: true)]
    private ?string $note = null;

    /** Service à table : numéro de la table (1 à 999), seulement si la commande passe en cuisine. */
    #[ORM\Column(type: 'smallint', nullable: true, options: ['unsigned' => true])]
    private ?int $tableNumber = null;

    /** @var Collection<int, OrderLine> */
    #[ORM\OneToMany(targetEntity: OrderLine::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    private Collection $lines;

    public function __construct(string $uuid, Event $event, string $tillLetter, int $number, PaymentMethod $paymentMethod)
    {
        $this->uuid = $uuid;
        $this->event = $event;
        $this->tillLetter = $tillLetter;
        $this->number = $number;
        $this->paymentMethod = $paymentMethod;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function getTillLetter(): string
    {
        return $this->tillLetter;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getDisplayNumber(): string
    {
        return $this->tillLetter.$this->number;
    }

    public function getPaymentMethod(): PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function getAmountReceivedCents(): ?int
    {
        return $this->amountReceivedCents;
    }

    public function setAmountReceivedCents(?int $amountReceivedCents): static
    {
        $this->amountReceivedCents = $amountReceivedCents;

        return $this;
    }

    public function getTotalCents(): int
    {
        return $this->totalCents;
    }

    public function setTotalCents(int $totalCents): static
    {
        $this->totalCents = $totalCents;

        return $this;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function setStatus(OrderStatus $status): static
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Heure de l'encaissement sur la tablette, qui peut précéder l'envoi (coupure réseau). */
    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDevice(): ?Device
    {
        return $this->device;
    }

    public function setDevice(?Device $device): static
    {
        $this->device = $device;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    /** Table où le plat est apporté ; null : commande au comptoir. Fixé à l'encaissement, jamais modifié. */
    public function getTableNumber(): ?int
    {
        return $this->tableNumber;
    }

    public function setTableNumber(?int $tableNumber): static
    {
        $this->tableNumber = $tableNumber;

        return $this;
    }

    public function getChangeCents(): ?int
    {
        return null === $this->amountReceivedCents ? null : $this->amountReceivedCents - $this->totalCents;
    }

    /** @return Collection<int, OrderLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(OrderLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
        }

        return $this;
    }
}
