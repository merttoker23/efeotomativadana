<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add persistent guest and customer carts with unique product lines';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE commerce_cart (id INT AUTO_INCREMENT NOT NULL, customer_id INT DEFAULT NULL, guest_token VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_commerce_cart_customer (customer_id), UNIQUE INDEX uniq_commerce_cart_guest_token (guest_token), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE commerce_cart_item (id INT AUTO_INCREMENT NOT NULL, cart_id INT NOT NULL, product_id INT NOT NULL, quantity INT NOT NULL, UNIQUE INDEX uniq_commerce_cart_item_product (cart_id, product_id), INDEX IDX_A37B06D01AD5CDBF (cart_id), INDEX IDX_A37B06D04584665A (product_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE commerce_cart ADD CONSTRAINT FK_COMMERCE_CART_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customer_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_cart_item ADD CONSTRAINT FK_COMMERCE_CART_ITEM_CART FOREIGN KEY (cart_id) REFERENCES commerce_cart (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_cart_item ADD CONSTRAINT FK_COMMERCE_CART_ITEM_PRODUCT FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commerce_cart_item DROP FOREIGN KEY FK_COMMERCE_CART_ITEM_CART');
        $this->addSql('ALTER TABLE commerce_cart_item DROP FOREIGN KEY FK_COMMERCE_CART_ITEM_PRODUCT');
        $this->addSql('ALTER TABLE commerce_cart DROP FOREIGN KEY FK_COMMERCE_CART_CUSTOMER');
        $this->addSql('DROP TABLE commerce_cart_item');
        $this->addSql('DROP TABLE commerce_cart');
    }
}
