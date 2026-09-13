<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product pricing and inventory persistence with database invariants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE commerce_product_price (
                id INT AUTO_INCREMENT NOT NULL,
                product_id INT NOT NULL,
                base_minor_amount BIGINT NOT NULL,
                sale_minor_amount BIGINT DEFAULT NULL,
                currency VARCHAR(3) NOT NULL,
                tax_category VARCHAR(50) NOT NULL,
                tax_rate_basis_points INT NOT NULL,
                sale_starts_at DATETIME DEFAULT NULL,
                sale_ends_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_commerce_price_product (product_id),
                INDEX idx_commerce_price_sale_bounds (sale_starts_at, sale_ends_at),
                PRIMARY KEY (id),
                CONSTRAINT chk_commerce_price_base_non_negative CHECK (base_minor_amount >= 0),
                CONSTRAINT chk_commerce_price_sale_non_negative CHECK (sale_minor_amount IS NULL OR sale_minor_amount >= 0),
                CONSTRAINT chk_commerce_price_sale_below_base CHECK (sale_minor_amount IS NULL OR sale_minor_amount < base_minor_amount),
                CONSTRAINT chk_commerce_price_tax_rate CHECK (tax_rate_basis_points BETWEEN 0 AND 10000),
                CONSTRAINT chk_commerce_price_sale_window CHECK (sale_starts_at IS NULL OR sale_ends_at IS NULL OR sale_starts_at < sale_ends_at),
                CONSTRAINT fk_commerce_price_product FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE commerce_product_inventory (
                id INT AUTO_INCREMENT NOT NULL,
                product_id INT NOT NULL,
                quantity INT NOT NULL,
                available_for_sale TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_commerce_inventory_product (product_id),
                INDEX idx_commerce_inventory_availability_quantity (available_for_sale, quantity),
                PRIMARY KEY (id),
                CONSTRAINT chk_commerce_inventory_quantity_non_negative CHECK (quantity >= 0),
                CONSTRAINT fk_commerce_inventory_product FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commerce_product_inventory');
        $this->addSql('DROP TABLE commerce_product_price');
    }
}
