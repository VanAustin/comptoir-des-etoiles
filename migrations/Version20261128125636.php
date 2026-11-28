<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Unités d'achat des articles (« pot de Nutella », 25 portions), pour les courses.
 * Colonnes facultatives : les articles existants n'en ont pas. Ajout instantané
 * sous MySQL 8.4, sans bloquer la table.
 */
final class Version20261128125636 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Articles : unité d\'achat et portions par unité';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD purchase_unit VARCHAR(40) DEFAULT NULL, ADD portions_per_unit SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP purchase_unit, DROP portions_per_unit');
    }
}
