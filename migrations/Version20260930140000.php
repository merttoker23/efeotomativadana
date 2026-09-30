<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Composite indexes for three list queries that were sorting in memory.
 *
 * Each one is here because a query this application actually issues could not use an index it
 * already had, and each is additive only. Nothing is dropped, because the single-column index
 * being complemented is still the one other queries reach for.
 *
 * `catalog_product` — the catalogue's default sort is newest-first, and the only usable index
 * was `(publication_status, name)`. EXPLAIN on the seeded catalogue (4,000 products) reported
 * `Using filesort` over 1,944 published rows, while the alphabetical sort on the very same
 * query shape reported `Using index`. Adding `created_at` to the publication prefix removes the
 * sort without disturbing the name index.
 *
 * `commerce_customer_order` — the admin order list orders the whole table by recency and the
 * only composite index is `(customer_id, created_at)`, which cannot serve a query that filters
 * on no customer at all.
 *
 * `commerce_wishlist_item` — the wishlist orders one customer's saved products by recency and
 * had only a generated single-column index on `customer_id`.
 */
final class Version20260930140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite indexes for the catalogue newest-first sort, the admin order list and the wishlist.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_catalog_product_publication_created ON catalog_product (publication_status, created_at, id)');
        $this->addSql('CREATE INDEX idx_commerce_order_created ON commerce_customer_order (created_at, id)');
        $this->addSql('CREATE INDEX idx_commerce_wishlist_owner_created ON commerce_wishlist_item (customer_id, created_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_commerce_wishlist_owner_created ON commerce_wishlist_item');
        $this->addSql('DROP INDEX idx_commerce_order_created ON commerce_customer_order');
        $this->addSql('DROP INDEX idx_catalog_product_publication_created ON catalog_product');
    }
}