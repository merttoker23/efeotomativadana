<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920070100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add customer-owned wishlist items';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE commerce_wishlist_item (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, product_id INT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_commerce_wishlist_owner_product (customer_id, product_id), INDEX IDX_F1084E0F9395C3F3 (customer_id), INDEX IDX_F1084E0F4584665A (product_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE commerce_wishlist_item ADD CONSTRAINT FK_COMMERCE_WISHLIST_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customer_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_wishlist_item ADD CONSTRAINT FK_COMMERCE_WISHLIST_PRODUCT FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commerce_wishlist_item DROP FOREIGN KEY FK_COMMERCE_WISHLIST_CUSTOMER');
        $this->addSql('ALTER TABLE commerce_wishlist_item DROP FOREIGN KEY FK_COMMERCE_WISHLIST_PRODUCT');
        $this->addSql('DROP TABLE commerce_wishlist_item');
    }
}
