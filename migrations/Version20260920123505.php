<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920123505 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add immutable local orders, item snapshots and address snapshots';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE commerce_customer_order (
              id INT AUTO_INCREMENT NOT NULL,
              order_number VARCHAR(32) NOT NULL,
              state VARCHAR(20) NOT NULL,
              customer_email VARCHAR(180) NOT NULL,
              customer_name VARCHAR(255) NOT NULL,
              customer_phone VARCHAR(30) DEFAULT NULL,
              subtotal_minor_amount BIGINT NOT NULL,
              tax_minor_amount BIGINT NOT NULL,
              shipping_minor_amount BIGINT NOT NULL,
              grand_total_minor_amount BIGINT NOT NULL,
              currency VARCHAR(3) NOT NULL,
              shipping_option_key VARCHAR(50) NOT NULL,
              shipping_option_label VARCHAR(120) NOT NULL,
              payment_option_key VARCHAR(50) NOT NULL,
              payment_option_label VARCHAR(160) NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              customer_id INT NOT NULL,
              INDEX IDX_5E7BDC29395C3F3 (customer_id),
              INDEX idx_commerce_order_customer_created (customer_id, created_at),
              UNIQUE INDEX uniq_commerce_order_number (order_number),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE commerce_order_address (
              id INT AUTO_INCREMENT NOT NULL,
              role VARCHAR(20) NOT NULL,
              recipient_name VARCHAR(120) NOT NULL,
              phone VARCHAR(30) NOT NULL,
              address_line1 VARCHAR(255) NOT NULL,
              address_line2 VARCHAR(255) DEFAULT NULL,
              district VARCHAR(100) NOT NULL,
              city VARCHAR(100) NOT NULL,
              postal_code VARCHAR(20) DEFAULT NULL,
              country_code VARCHAR(2) NOT NULL,
              order_id INT NOT NULL,
              INDEX IDX_6E187C2D8D9F6D38 (order_id),
              UNIQUE INDEX uniq_commerce_order_address_role (order_id, role),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE commerce_order_item (
              id INT AUTO_INCREMENT NOT NULL,
              sku VARCHAR(64) NOT NULL,
              product_name VARCHAR(255) NOT NULL,
              quantity INT NOT NULL,
              unit_gross_minor_amount BIGINT NOT NULL,
              tax_rate_basis_points INT NOT NULL,
              line_net_minor_amount BIGINT NOT NULL,
              line_tax_minor_amount BIGINT NOT NULL,
              line_gross_minor_amount BIGINT NOT NULL,
              currency VARCHAR(3) NOT NULL,
              order_id INT NOT NULL,
              product_id INT DEFAULT NULL,
              INDEX IDX_7160FD958D9F6D38 (order_id),
              INDEX IDX_7160FD954584665A (product_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commerce_customer_order
            ADD
              CONSTRAINT FK_5E7BDC29395C3F3 FOREIGN KEY (customer_id) REFERENCES customer_user (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commerce_order_address
            ADD
              CONSTRAINT FK_6E187C2D8D9F6D38 FOREIGN KEY (order_id) REFERENCES commerce_customer_order (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commerce_order_item
            ADD
              CONSTRAINT FK_7160FD958D9F6D38 FOREIGN KEY (order_id) REFERENCES commerce_customer_order (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commerce_order_item
            ADD
              CONSTRAINT FK_7160FD954584665A FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE
            SET
              NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commerce_customer_order DROP FOREIGN KEY FK_5E7BDC29395C3F3');
        $this->addSql('ALTER TABLE commerce_order_address DROP FOREIGN KEY FK_6E187C2D8D9F6D38');
        $this->addSql('ALTER TABLE commerce_order_item DROP FOREIGN KEY FK_7160FD958D9F6D38');
        $this->addSql('ALTER TABLE commerce_order_item DROP FOREIGN KEY FK_7160FD954584665A');
        $this->addSql('DROP TABLE commerce_customer_order');
        $this->addSql('DROP TABLE commerce_order_address');
        $this->addSql('DROP TABLE commerce_order_item');
    }
}
