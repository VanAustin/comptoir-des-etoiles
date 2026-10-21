<?php

namespace App\Service;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Trace une action sensible au nom de l'utilisateur connecté.
 * L'écriture en base se fait au flush suivant, avec la modification tracée.
 */
class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function log(string $action, ?string $subject = null, ?array $before = null, ?array $after = null): void
    {
        $this->entityManager->persist(new AuditLog($this->actor(), $action, $subject, $before, $after));
    }

    private function actor(): string
    {
        $user = $this->security->getUser();

        return match (true) {
            $user instanceof AdminUser => 'admin:'.$user->getUserIdentifier(),
            null === $user => 'anonyme',
            default => $user->getUserIdentifier(),
        };
    }
}
