<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Réglage « Avec cuisine » : les événements existants restent avec cuisine.
 */
final class Version20261103134657 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Événement avec ou sans cuisine';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD with_kitchen TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP with_kitchen');
    }
}
