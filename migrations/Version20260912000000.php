<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add typed store settings and dedicated administrator identity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE store_setting (id INT AUTO_INCREMENT NOT NULL, setting_key VARCHAR(100) NOT NULL, value JSON DEFAULT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_store_setting_key (setting_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE admin_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_admin_user_email (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql(<<<'SQL'
            INSERT INTO store_setting (setting_key, value, updated_at) VALUES
                ('commerce.b2b_enabled', 'false', CURRENT_TIMESTAMP),
                ('commerce.b2b_provider', 'null', CURRENT_TIMESTAMP),
                ('store.name', '"Efe Otomotiv Adana"', CURRENT_TIMESTAMP),
                ('store.currency', '"TRY"', CURRENT_TIMESTAMP),
                ('store.default_locale', '"tr"', CURRENT_TIMESTAMP),
                ('store.default_tax_rate', '20', CURRENT_TIMESTAMP),
                ('loyalty.enabled', 'false', CURRENT_TIMESTAMP),
                ('loyalty.earn_percentage', '1', CURRENT_TIMESTAMP),
                ('payment.provider', 'null', CURRENT_TIMESTAMP),
                ('shipping.provider', 'null', CURRENT_TIMESTAMP)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE admin_user');
        $this->addSql('DROP TABLE store_setting');
    }
}
