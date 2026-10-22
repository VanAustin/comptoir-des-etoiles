<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022082343 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lignes de commande : type (article, consigne, retour de consigne) et composition figée des formules';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_line ADD COLUMN kind VARCHAR(20) DEFAULT \'article\' NOT NULL');
        $this->addSql('ALTER TABLE order_line ADD COLUMN component_ids CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Suppression de colonnes plutôt que recréation de la table (voir CLAUDE.md)
        $this->addSql('ALTER TABLE order_line DROP COLUMN component_ids');
        $this->addSql('ALTER TABLE order_line DROP COLUMN kind');
    }
}
