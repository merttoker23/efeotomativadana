<?php

declare(strict_types=1);

namespace App\Module\Seo\Sitemap;

use App\Module\Seo\SeoUrlFactory;

/**
 * Builds one section of the sitemap.
 *
 * The page size is a parameter rather than a constant baked into the query so the chunking is
 * provable in a test with five fixtures instead of a thousand, and so a store that outgrows
 * the default can be re-tuned without touching this class. 1,000 per file is the ceiling the
 * sitemaps.org protocol sets; a single file with 40,000 URLs is not a document, it is a
 * denial of service on the crawler.
 */
final readonly class SitemapSectionBuilder
{
    public const PAGE_SIZE = 1_000;

    /** The landing pages, which exist whether or not anything has been published yet. */
    private const STATIC_ROUTES = [
        'app_home',
        'storefront_catalog_index',
        'storefront_catalog_brands',
        'storefront_blog_index',
        'storefront_information_index',
    ];

    public function __construct(
        private SitemapRepository $repository,
        private SeoUrlFactory $urls,
    ) {
    }

    public function build(SitemapKind $kind, int $page, ?int $pageSize = null): string
    {
        return SitemapXml::urlSet($this->entries($kind, $page, $pageSize));
    }

    /**
     * Null when the requested file does not exist, so a controller can answer 404 for a chunk
     * past the end instead of publishing an empty document.
     */
    public function buildOrNull(SitemapKind $kind, int $page, ?int $pageSize = null): ?string
    {
        $pageSize = max(1, $pageSize ?? self::PAGE_SIZE);
        if ($page < 1 || $page > $this->repository->chunkCount($kind, $pageSize)) {
            return null;
        }

        return $this->build($kind, $page, $pageSize);
    }

    /** @return list<array{loc: string, lastmod: ?string}> */
    private function entries(SitemapKind $kind, int $page, ?int $pageSize): array
    {
        $pageSize = max(1, $pageSize ?? self::PAGE_SIZE);

        if (SitemapKind::Static === $kind) {
            $entries = [];
            foreach (self::STATIC_ROUTES as $route) {
                $entries[] = ['loc' => $this->urls->absolute($route), 'lastmod' => null];
            }

            return $entries;
        }

        return array_map(
            fn (SitemapEntry $entry): array => [
                'loc' => $this->urls->absolute($entry->route, ['slug' => $entry->slug]),
                'lastmod' => $entry->lastModified,
            ],
            $this->repository->entries($kind, $page, $pageSize),
        );
    }
}
