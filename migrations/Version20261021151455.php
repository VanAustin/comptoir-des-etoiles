<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021151455 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Consigne : montant par événement, case « avec consigne » sur les articles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD COLUMN deposit_cents INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE product ADD COLUMN has_deposit BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Suppression de colonnes plutôt que recréation des tables : recréer « event »
        // effacerait en cascade les tablettes et les commandes (clés étrangères actives).
        $this->addSql('ALTER TABLE product DROP COLUMN has_deposit');
        $this->addSql('ALTER TABLE event DROP COLUMN deposit_cents');
    }
}
