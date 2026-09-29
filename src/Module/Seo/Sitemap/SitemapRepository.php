<?php

declare(strict_types=1);

namespace App\Module\Seo\Sitemap;

use App\Entity\Seo\SeoResourceType;
use Doctrine\DBAL\Connection;

/**
 * Reads the published URLs a sitemap advertises.
 *
 * Hand-written statements over an explicit whitelist rather than a generated query. A
 * generated one would need the table name and the published test to come from somewhere, and
 * "somewhere" is exactly the kind of value that ends up concatenated into SQL. Here every table
 * and predicate is a literal written in this file, and a content type that needs adding is a
 * visible `match` arm rather than an interpolation.
 *
 * Everything is read from the local tables. The B2B provider's own URLs are never consulted:
 * this store's public address for a product is the one its own route produces, and a sitemap
 * is a statement about this domain, not about a supplier's.
 */
final readonly class SitemapRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /** How many files a kind is split into, or 0 when there is nothing to advertise. */
    public function chunkCount(SitemapKind $kind, int $pageSize): int
    {
        if (!$kind->isGeneratedFromContent()) {
            return 1;
        }

        $pageSize = max(1, $pageSize);
        $total = match ($kind) {
            SitemapKind::Categories => $this->count('catalog_category', "publication_status = 'published'"),
            SitemapKind::Brands => $this->count('catalog_brand', "publication_status = 'published'"),
            SitemapKind::Products => $this->count('catalog_product', "publication_status = 'published'"),
            SitemapKind::Content => $this->count('cms_blog_post', 'published = 1') + $this->count('cms_information_page', 'published = 1'),
            SitemapKind::Static => 0,
        };

        return $total > 0 ? (int) ceil($total / $pageSize) : 0;
    }

    /** @return list<SitemapEntry> */
    public function entries(SitemapKind $kind, int $page, int $pageSize): array
    {
        $pageSize = max(1, $pageSize);
        $offset = (max(1, $page) - 1) * $pageSize;

        return match ($kind) {
            SitemapKind::Static => [],
            SitemapKind::Categories => $this->publishedSlugs(SeoResourceType::Category, 'catalog_category', "publication_status = 'published'", $pageSize, $offset),
            SitemapKind::Brands => $this->publishedSlugs(SeoResourceType::Brand, 'catalog_brand', "publication_status = 'published'", $pageSize, $offset),
            SitemapKind::Products => $this->publishedSlugs(SeoResourceType::Product, 'catalog_product', "publication_status = 'published'", $pageSize, $offset, dated: true),
            SitemapKind::Content => $this->publishedContent($pageSize, $offset),
        };
    }

    /**
     * Blog posts and information pages share one section, so the page window has to be applied
     * to the two of them *together*.
     *
     * Reading each table with its own LIMIT/OFFSET and concatenating looks equivalent and is
     * not: it would let a single file hold twice the declared page size, and it would advertise
     * a final chunk that contains nothing. A UNION with one ORDER BY partitions the combined
     * result properly, and the kind column keeps the order total when a blog post and an
     * information page happen to share a slug.
     *
     * @return list<SitemapEntry>
     */
    private function publishedContent(int $limit, int $offset): array
    {
        $rows = $this->connection->fetchAllAssociative(sprintf(
            <<<'SQL'
                SELECT 'blog' AS kind, slug FROM cms_blog_post WHERE published = 1
                UNION ALL
                SELECT 'page' AS kind, slug FROM cms_information_page WHERE published = 1
                ORDER BY kind ASC, slug ASC
                LIMIT %d OFFSET %d
                SQL,
            $limit,
            $offset,
        ));

        $types = ['blog' => SeoResourceType::BlogPost, 'page' => SeoResourceType::InformationPage];

        return array_map(
            static fn (array $row): SitemapEntry => SitemapEntry::forResource(
                $types[(string) $row['kind']],
                (string) $row['slug'],
            ),
            $rows,
        );
    }

    private function count(string $table, string $published): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s', $table, $published));
    }

    /**
     * @return list<SitemapEntry>
     */
    private function publishedSlugs(
        SeoResourceType $type,
        string $table,
        string $published,
        int $limit,
        int $offset,
        bool $dated = false,
    ): array {
        $lastModified = $dated ? ', updated_at' : '';
        $rows = $this->connection->fetchAllAssociative(sprintf(
            'SELECT slug%s FROM %s WHERE %s ORDER BY slug ASC LIMIT %d OFFSET %d',
            $lastModified,
            $table,
            $published,
            $limit,
            $offset,
        ));

        return array_map(
            static fn (array $row): SitemapEntry => SitemapEntry::forResource(
                $type,
                (string) $row['slug'],
                isset($row['updated_at']) ? substr((string) $row['updated_at'], 0, 10) : null,
            ),
            $rows,
        );
    }
}
