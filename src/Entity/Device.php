<?php

namespace App\Entity;

use App\Enum\DeviceRole;
use App\Repository\DeviceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tablette appairée. Le serveur ne garde que les empreintes SHA-256 du jeton
 * et du code d'appairage, jamais leur valeur.
 */
#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ORM\UniqueConstraint(columns: ['event_id', 'till_letter'])]
class Device
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\Column(length: 60)]
    private string $name;

    #[ORM\Column(length: 20, enumType: DeviceRole::class)]
    private DeviceRole $role;

    /**
     * Lettre de caisse (A, B…) attribuée par le serveur à l'appairage,
     * unique par événement. Null pour les tablettes cuisine et affichage.
     */
    #[ORM\Column(length: 1, nullable: true)]
    private ?string $tillLetter = null;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $tokenHash = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $pairingCodeHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pairingCodeExpiresAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(Event $event, string $name, DeviceRole $role)
    {
        $this->event = $event;
        $this->name = $name;
        $this->role = $role;
        $this->createdAt = new \DateTimeImmutable();
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

    public function getRole(): DeviceRole
    {
        return $this->role;
    }

    public function getTillLetter(): ?string
    {
        return $this->tillLetter;
    }

    public function setTillLetter(?string $tillLetter): static
    {
        $this->tillLetter = $tillLetter;

        return $this;
    }

    public function getTokenHash(): ?string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(?string $tokenHash): static
    {
        $this->tokenHash = $tokenHash;

        return $this;
    }

    public function getPairingCodeHash(): ?string
    {
        return $this->pairingCodeHash;
    }

    public function setPairingCodeHash(?string $pairingCodeHash): static
    {
        $this->pairingCodeHash = $pairingCodeHash;

        return $this;
    }

    public function getPairingCodeExpiresAt(): ?\DateTimeImmutable
    {
        return $this->pairingCodeExpiresAt;
    }

    public function setPairingCodeExpiresAt(?\DateTimeImmutable $pairingCodeExpiresAt): static
    {
        $this->pairingCodeExpiresAt = $pairingCodeExpiresAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): static
    {
        $this->lastSeenAt = $lastSeenAt;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeImmutable $revokedAt): static
    {
        $this->revokedAt = $revokedAt;

        return $this;
    }
}
