<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021123707 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création des tables : événements, articles, commandes, tablettes, journal';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, actor VARCHAR(120) NOT NULL, "action" VARCHAR(40) NOT NULL, subject VARCHAR(120) DEFAULT NULL, "before" CLOB DEFAULT NULL, "after" CLOB DEFAULT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX IDX_F6E1C0F58B8E8428 ON audit_log (created_at)');
        $this->addSql('CREATE TABLE device (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(60) NOT NULL, role VARCHAR(20) NOT NULL, till_letter VARCHAR(1) DEFAULT NULL, token_hash VARCHAR(64) DEFAULT NULL, pairing_code_hash VARCHAR(64) DEFAULT NULL, pairing_code_expires_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, last_seen_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, event_id INTEGER NOT NULL, CONSTRAINT FK_92FB68E71F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_92FB68EB3BC57DA ON device (token_hash)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_92FB68E71F7E88BDFC0AE94 ON device (event_id, till_letter)');
        $this->addSql('CREATE INDEX IDX_92FB68E71F7E88B ON device (event_id)');
        $this->addSql('CREATE TABLE event (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(100) NOT NULL, event_date DATE NOT NULL, active BOOLEAN NOT NULL)');
        $this->addSql('CREATE TABLE order_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, label VARCHAR(80) NOT NULL, unit_price_cents INTEGER NOT NULL, quantity INTEGER NOT NULL, goes_to_kitchen BOOLEAN NOT NULL, order_id INTEGER NOT NULL, product_id INTEGER DEFAULT NULL, CONSTRAINT FK_9CE58EE18D9F6D38 FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_9CE58EE14584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_9CE58EE18D9F6D38 ON order_line (order_id)');
        $this->addSql('CREATE INDEX IDX_9CE58EE14584665A ON order_line (product_id)');
        $this->addSql('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, till_letter VARCHAR(1) NOT NULL, number INTEGER NOT NULL, payment_method VARCHAR(10) NOT NULL, amount_received_cents INTEGER DEFAULT NULL, total_cents INTEGER NOT NULL, status VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, event_id INTEGER NOT NULL, device_id INTEGER DEFAULT NULL, CONSTRAINT FK_E52FFDEE71F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_E52FFDEE94A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E52FFDEED17F50A6 ON orders (uuid)');
        $this->addSql('CREATE INDEX IDX_E52FFDEE71F7E88B43625D9F ON orders (event_id, updated_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E52FFDEE71F7E88BDFC0AE9496901F54 ON orders (event_id, till_letter, number)');
        $this->addSql('CREATE INDEX IDX_E52FFDEE71F7E88B ON orders (event_id)');
        $this->addSql('CREATE INDEX IDX_E52FFDEE94A4C7D4 ON orders (device_id)');
        $this->addSql('CREATE TABLE product (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(80) NOT NULL, icon VARCHAR(100) DEFAULT NULL, category VARCHAR(40) NOT NULL, price_cents INTEGER NOT NULL, initial_stock INTEGER DEFAULT NULL, goes_to_kitchen BOOLEAN NOT NULL, position INTEGER NOT NULL, event_id INTEGER NOT NULL, CONSTRAINT FK_D34A04AD71F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_D34A04AD71F7E88B462CE4F5 ON product (event_id, position)');
        $this->addSql('CREATE INDEX IDX_D34A04AD71F7E88B ON product (event_id)');
        $this->addSql('CREATE TABLE product_component (formula_id INTEGER NOT NULL, component_id INTEGER NOT NULL, PRIMARY KEY (formula_id, component_id), CONSTRAINT FK_275E17DAA50A6386 FOREIGN KEY (formula_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_275E17DAE2ABAFFF FOREIGN KEY (component_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_275E17DAA50A6386 ON product_component (formula_id)');
        $this->addSql('CREATE INDEX IDX_275E17DAE2ABAFFF ON product_component (component_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE device');
        $this->addSql('DROP TABLE event');
        $this->addSql('DROP TABLE order_line');
        $this->addSql('DROP TABLE orders');
        $this->addSql('DROP TABLE product');
        $this->addSql('DROP TABLE product_component');
    }
}
