<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Case « Vendu seul » des articles : les articles existants restent vendus seuls.
 */
final class Version20261103151311 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Article vendu seul ou seulement dans les formules';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD sold_alone TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP sold_alone');
    }
}
