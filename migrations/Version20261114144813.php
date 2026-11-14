<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Service à table (Loto) : réglage de l'événement, décoché pour les événements existants,
 * et numéro de table facultatif de la commande (1 à 999), vide pour les commandes existantes
 * (servies au comptoir). Ajouts instantanés sous MySQL 8.4, sans bloquer les tables.
 */
final class Version20261114144813 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Service à table : réglage de l\'événement et numéro de table de la commande';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD table_service TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE orders ADD table_number SMALLINT UNSIGNED DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP table_service');
        $this->addSql('ALTER TABLE orders DROP table_number');
    }
}
