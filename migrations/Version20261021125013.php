<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021125013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table des administrateurs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, username VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AD8A54A9F85E0677 ON admin_user (username)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_user');
    }
}
