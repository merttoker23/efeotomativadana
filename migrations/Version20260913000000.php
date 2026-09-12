<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add provider-neutral catalog domain persistence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE catalog_brand (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, source VARCHAR(20) NOT NULL, publication_status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_catalog_brand_publication (publication_status), UNIQUE INDEX uniq_catalog_brand_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE catalog_category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, source VARCHAR(20) NOT NULL, publication_status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, parent_id INT DEFAULT NULL, INDEX IDX_349BC7DF727ACA70 (parent_id), INDEX idx_catalog_category_publication (publication_status), UNIQUE INDEX uniq_catalog_category_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE catalog_product (id INT AUTO_INCREMENT NOT NULL, sku VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, source VARCHAR(20) NOT NULL, publication_status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, brand_id INT DEFAULT NULL, INDEX IDX_DCF8F98144F5D008 (brand_id), INDEX idx_catalog_product_publication (publication_status), UNIQUE INDEX uniq_catalog_product_sku (sku), UNIQUE INDEX uniq_catalog_product_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE catalog_product_category (product_id INT NOT NULL, category_id INT NOT NULL, INDEX IDX_8BEBEF264584665A (product_id), INDEX IDX_8BEBEF2612469DE2 (category_id), PRIMARY KEY (product_id, category_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE catalog_product_attribute (id INT AUTO_INCREMENT NOT NULL, attribute_key VARCHAR(100) NOT NULL, attribute_value VARCHAR(500) NOT NULL, product_id INT NOT NULL, INDEX IDX_C4993FD64584665A (product_id), INDEX idx_catalog_attribute_lookup (attribute_key, attribute_value), UNIQUE INDEX uniq_catalog_product_attribute (product_id, attribute_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE catalog_product_identifier (id INT AUTO_INCREMENT NOT NULL, identifier_type VARCHAR(20) NOT NULL, code VARCHAR(120) NOT NULL, product_id INT NOT NULL, INDEX IDX_32CF3CCF4584665A (product_id), INDEX idx_catalog_identifier_lookup (identifier_type, code), UNIQUE INDEX uniq_catalog_product_identifier (product_id, identifier_type, code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE catalog_product_image (id INT AUTO_INCREMENT NOT NULL, path VARCHAR(500) NOT NULL, alt_text VARCHAR(255) DEFAULT NULL, sort_order INT NOT NULL, product_id INT NOT NULL, INDEX IDX_188CEF8E4584665A (product_id), INDEX idx_catalog_product_image_sort (product_id, sort_order), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE catalog_category ADD CONSTRAINT FK_349BC7DF727ACA70 FOREIGN KEY (parent_id) REFERENCES catalog_category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE catalog_product ADD CONSTRAINT FK_DCF8F98144F5D008 FOREIGN KEY (brand_id) REFERENCES catalog_brand (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE catalog_product_category ADD CONSTRAINT FK_8BEBEF264584665A FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE catalog_product_category ADD CONSTRAINT FK_8BEBEF2612469DE2 FOREIGN KEY (category_id) REFERENCES catalog_category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE catalog_product_attribute ADD CONSTRAINT FK_C4993FD64584665A FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE catalog_product_identifier ADD CONSTRAINT FK_32CF3CCF4584665A FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE catalog_product_image ADD CONSTRAINT FK_188CEF8E4584665A FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog_product_image');
        $this->addSql('DROP TABLE catalog_product_identifier');
        $this->addSql('DROP TABLE catalog_product_attribute');
        $this->addSql('DROP TABLE catalog_product_category');
        $this->addSql('DROP TABLE catalog_product');
        $this->addSql('DROP TABLE catalog_category');
        $this->addSql('DROP TABLE catalog_brand');
    }
}
