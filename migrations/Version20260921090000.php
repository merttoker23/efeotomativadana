<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optimistic commerce versions and auditable order status history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commerce_product_inventory ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE commerce_customer_order ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql(<<<'SQL'
            CREATE TABLE commerce_order_status_change (
              id INT AUTO_INCREMENT NOT NULL,
              order_id INT NOT NULL,
              from_state VARCHAR(20) NOT NULL,
              to_state VARCHAR(20) NOT NULL,
              reason VARCHAR(500) NOT NULL,
              actor_email VARCHAR(180) NOT NULL,
              changed_at DATETIME NOT NULL,
              INDEX IDX_10744FAD8D9F6D38 (order_id),
              INDEX idx_order_status_change_order_time (order_id, changed_at),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql('ALTER TABLE commerce_order_status_change ADD CONSTRAINT FK_10744FAD8D9F6D38 FOREIGN KEY (order_id) REFERENCES commerce_customer_order (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commerce_order_status_change DROP FOREIGN KEY FK_10744FAD8D9F6D38');
        $this->addSql('DROP TABLE commerce_order_status_change');
        $this->addSql('ALTER TABLE commerce_product_inventory DROP version');
        $this->addSql('ALTER TABLE commerce_customer_order DROP version');
    }
}
