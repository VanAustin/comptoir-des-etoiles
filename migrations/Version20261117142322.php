<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Service à table : zones des livreurs, réglées par le nombre de rangées par zone
 * (3 pour les événements existants : rangées 1 à 3, 4 à 6, 7 à 9). Ajout instantané
 * sous MySQL 8.4, sans bloquer la table.
 */
final class Version20261117142322 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Service à table : rangées par zone de livreur';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD rows_per_zone SMALLINT UNSIGNED DEFAULT 3 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP rows_per_zone');
    }
}
