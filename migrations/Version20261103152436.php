<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Postes de caisse : catégories vendues (vide = tout le menu) et vente directe.
 * MySQL refuse une valeur par défaut sur une colonne JSON : remplie avant de devenir obligatoire.
 */
final class Version20261103152436 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catégories vendues et vente directe des caisses';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device ADD categories JSON DEFAULT NULL, ADD direct_sale TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE device SET categories = JSON_ARRAY()');
        $this->addSql('ALTER TABLE device MODIFY categories JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device DROP categories, DROP direct_sale');
    }
}
