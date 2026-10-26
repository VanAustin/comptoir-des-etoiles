<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schéma initial MySQL : remplace les migrations SQLite d'origine.
 */
final class Version20261026131450 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schéma initial MySQL';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_user (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_AD8A54A9F85E0677 (username), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE audit_log (id INT AUTO_INCREMENT NOT NULL, actor VARCHAR(120) NOT NULL, action VARCHAR(40) NOT NULL, subject VARCHAR(120) DEFAULT NULL, value_before JSON DEFAULT NULL, value_after JSON DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_F6E1C0F58B8E8428 (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE device (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(60) NOT NULL, role VARCHAR(20) NOT NULL, till_letter VARCHAR(1) DEFAULT NULL, token_hash VARCHAR(64) DEFAULT NULL, pairing_code_hash VARCHAR(64) DEFAULT NULL, pairing_code_expires_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, last_seen_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, event_id INT NOT NULL, UNIQUE INDEX UNIQ_92FB68EB3BC57DA (token_hash), UNIQUE INDEX UNIQ_92FB68E71F7E88BDFC0AE94 (event_id, till_letter), INDEX IDX_92FB68E71F7E88B (event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE event (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, event_date DATE NOT NULL, active TINYINT NOT NULL, deposit_cents INT DEFAULT 0 NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE order_line (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) DEFAULT \'article\' NOT NULL, label VARCHAR(80) NOT NULL, unit_price_cents INT NOT NULL, quantity INT NOT NULL, goes_to_kitchen TINYINT NOT NULL, component_ids JSON DEFAULT NULL, order_id INT NOT NULL, product_id INT DEFAULT NULL, INDEX IDX_9CE58EE18D9F6D38 (order_id), INDEX IDX_9CE58EE14584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE orders (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, till_letter VARCHAR(1) NOT NULL, number INT NOT NULL, payment_method VARCHAR(10) NOT NULL, amount_received_cents INT DEFAULT NULL, total_cents INT NOT NULL, status VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, note VARCHAR(140) DEFAULT NULL, event_id INT NOT NULL, device_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_E52FFDEED17F50A6 (uuid), INDEX IDX_E52FFDEE71F7E88B43625D9F (event_id, updated_at), UNIQUE INDEX UNIQ_E52FFDEE71F7E88BDFC0AE9496901F54 (event_id, till_letter, number), INDEX IDX_E52FFDEE71F7E88B (event_id), INDEX IDX_E52FFDEE94A4C7D4 (device_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE product (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, icon VARCHAR(100) DEFAULT NULL, category VARCHAR(40) NOT NULL, price_cents INT NOT NULL, initial_stock INT DEFAULT NULL, goes_to_kitchen TINYINT NOT NULL, has_deposit TINYINT DEFAULT 0 NOT NULL, position INT NOT NULL, archived_at DATETIME DEFAULT NULL, event_id INT NOT NULL, INDEX IDX_D34A04AD71F7E88B462CE4F5 (event_id, position), INDEX IDX_D34A04AD71F7E88B (event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE product_component (quantity INT DEFAULT 1 NOT NULL, formula_id INT NOT NULL, component_id INT NOT NULL, INDEX IDX_275E17DAA50A6386 (formula_id), INDEX IDX_275E17DAE2ABAFFF (component_id), PRIMARY KEY (formula_id, component_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE device ADD CONSTRAINT FK_92FB68E71F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE order_line ADD CONSTRAINT FK_9CE58EE18D9F6D38 FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE order_line ADD CONSTRAINT FK_9CE58EE14584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_E52FFDEE71F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_E52FFDEE94A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD71F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_component ADD CONSTRAINT FK_275E17DAA50A6386 FOREIGN KEY (formula_id) REFERENCES product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_component ADD CONSTRAINT FK_275E17DAE2ABAFFF FOREIGN KEY (component_id) REFERENCES product (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device DROP FOREIGN KEY FK_92FB68E71F7E88B');
        $this->addSql('ALTER TABLE order_line DROP FOREIGN KEY FK_9CE58EE18D9F6D38');
        $this->addSql('ALTER TABLE order_line DROP FOREIGN KEY FK_9CE58EE14584665A');
        $this->addSql('ALTER TABLE orders DROP FOREIGN KEY FK_E52FFDEE71F7E88B');
        $this->addSql('ALTER TABLE orders DROP FOREIGN KEY FK_E52FFDEE94A4C7D4');
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04AD71F7E88B');
        $this->addSql('ALTER TABLE product_component DROP FOREIGN KEY FK_275E17DAA50A6386');
        $this->addSql('ALTER TABLE product_component DROP FOREIGN KEY FK_275E17DAE2ABAFFF');
        $this->addSql('DROP TABLE admin_user');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE device');
        $this->addSql('DROP TABLE event');
        $this->addSql('DROP TABLE order_line');
        $this->addSql('DROP TABLE orders');
        $this->addSql('DROP TABLE product');
        $this->addSql('DROP TABLE product_component');
    }
}
