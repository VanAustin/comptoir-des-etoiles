<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023081218 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remarque pour la cuisine sur une commande';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders ADD COLUMN note VARCHAR(140) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Pas de recréation de la table : elle effacerait en cascade les lignes de commande
        $this->addSql('ALTER TABLE orders DROP COLUMN note');
    }
}
