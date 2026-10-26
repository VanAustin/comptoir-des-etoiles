<?php

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace des actions sensibles. L'auteur et l'objet sont enregistrés en texte
 * pour que la trace survive à la suppression d'une tablette ou d'un article.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Index(columns: ['created_at'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Par exemple « admin:camille » ou « tablette:3 Caisse buvette ». */
    #[ORM\Column(length: 120)]
    private string $actor;

    /** Par exemple « order.cancel », « product.price », « device.pair ». */
    #[ORM\Column(length: 40)]
    private string $action;

    /** Par exemple « order:A12 » ou « product:7 Crêpe sucre ». */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $subject;

    /** @var array<string, mixed>|null « before » est un mot réservé de MySQL */
    #[ORM\Column(name: 'value_before', type: Types::JSON, nullable: true)]
    private ?array $before;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'value_after', type: Types::JSON, nullable: true)]
    private ?array $after;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function __construct(string $actor, string $action, ?string $subject = null, ?array $before = null, ?array $after = null)
    {
        $this->actor = $actor;
        $this->action = $action;
        $this->subject = $subject;
        $this->before = $before;
        $this->after = $after;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActor(): string
    {
        return $this->actor;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    /** @return array<string, mixed>|null */
    public function getBefore(): ?array
    {
        return $this->before;
    }

    /** @return array<string, mixed>|null */
    public function getAfter(): ?array
    {
        return $this->after;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
