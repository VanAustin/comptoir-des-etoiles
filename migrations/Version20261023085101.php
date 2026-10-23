<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023085101 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Articles retirés du menu archivés au lieu d\'être supprimés';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD COLUMN archived_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Pas de recréation de la table : elle effacerait en cascade les composants des formules
        $this->addSql('ALTER TABLE product DROP COLUMN archived_at');
    }
}
