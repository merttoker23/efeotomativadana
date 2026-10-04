<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add encrypted admin-managed PayTR configuration; credentials are entered in the payment settings page.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE payment_configuration (id INT NOT NULL, merchant_id VARCHAR(100) NOT NULL, merchant_key_encrypted LONGTEXT DEFAULT NULL, merchant_salt_encrypted LONGTEXT DEFAULT NULL, test_mode TINYINT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("INSERT INTO payment_configuration (id, merchant_id, test_mode, updated_at) VALUES (1, '', 1, CURRENT_TIMESTAMP)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE payment_configuration');
    }
}
