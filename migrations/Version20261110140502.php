<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Numéros de révision des commandes et du menu de chaque événement : augmentés de 1 à chaque
 * changement (App\Service\Revisions), ils permettent de répondre 304 aux tablettes sans relire
 * les commandes. Ajout instantané sous MySQL 8.4, sans bloquer la table.
 */
final class Version20261110140502 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Révisions des commandes et du menu par événement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD orders_revision INT UNSIGNED DEFAULT 0 NOT NULL, ADD menu_revision INT UNSIGNED DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP orders_revision, DROP menu_revision');
    }
}
