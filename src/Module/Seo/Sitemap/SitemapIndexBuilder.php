<?php

declare(strict_types=1);

namespace App\Module\Seo\Sitemap;

use App\Module\Seo\SeoUrlFactory;

/**
 * Builds the sitemap index, naming only the files that exist.
 *
 * A kind with no published rows is left out entirely rather than advertised as an empty file.
 * Pointing a crawler at a section that will answer "no entries" spends its budget on a URL
 * that teaches it nothing, and an index that lists sections which do not exist is the kind of
 * thing that gets a sitemap ignored wholesale.
 */
final readonly class SitemapIndexBuilder
{
    public function __construct(
        private SitemapRepository $repository,
        private SeoUrlFactory $urls,
    ) {
    }

    public function build(?int $pageSize = null): string
    {
        $pageSize = $this->pageSize($pageSize);
        $entries = [['loc' => $this->urls->absolute('storefront_sitemap_static'), 'lastmod' => null]];

        foreach (SitemapKind::cases() as $kind) {
            if (!$kind->isGeneratedFromContent()) {
                continue;
            }
            $chunks = $this->repository->chunkCount($kind, $pageSize);
            for ($chunk = 1; $chunk <= $chunks; ++$chunk) {
                $entries[] = [
                    'loc' => $this->urls->absolute('storefront_sitemap_section', ['kind' => $kind->value, 'page' => $chunk]),
                    'lastmod' => null,
                ];
            }
        }

        return SitemapXml::index($entries);
    }

    private function pageSize(?int $override): int
    {
        return max(1, $override ?? SitemapSectionBuilder::PAGE_SIZE);
    }
}
