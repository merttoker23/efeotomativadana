<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the published product name lookup index for storefront catalog search';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_catalog_product_publication_name ON catalog_product (publication_status, name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_catalog_product_publication_name ON catalog_product');
    }
}
