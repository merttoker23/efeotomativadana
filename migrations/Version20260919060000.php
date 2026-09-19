<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add independent customer accounts, addresses, and hashed password reset tokens';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE customer_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, phone VARCHAR(30) DEFAULT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_customer_user_email (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE customer_address (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, label VARCHAR(80) NOT NULL, recipient_name VARCHAR(120) NOT NULL, phone VARCHAR(30) NOT NULL, address_line1 VARCHAR(255) NOT NULL, address_line2 VARCHAR(255) DEFAULT NULL, district VARCHAR(100) NOT NULL, city VARCHAR(100) NOT NULL, postal_code VARCHAR(20) DEFAULT NULL, country_code VARCHAR(2) DEFAULT \'TR\' NOT NULL, default_address TINYINT(1) DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_customer_address_owner (customer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE customer_password_reset_token (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_customer_reset_token_hash (token_hash), INDEX idx_customer_reset_token_owner (customer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE customer_address ADD CONSTRAINT FK_11996D8E9395C3F3 FOREIGN KEY (customer_id) REFERENCES customer_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_password_reset_token ADD CONSTRAINT FK_FCB6799D9395C3F3 FOREIGN KEY (customer_id) REFERENCES customer_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_address DROP FOREIGN KEY FK_11996D8E9395C3F3');
        $this->addSql('ALTER TABLE customer_password_reset_token DROP FOREIGN KEY FK_FCB6799D9395C3F3');
        $this->addSql('DROP TABLE customer_address');
        $this->addSql('DROP TABLE customer_password_reset_token');
        $this->addSql('DROP TABLE customer_user');
    }
}
