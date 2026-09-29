<?php

declare(strict_types=1);

namespace App\Module\Seo\Sitemap;

/**
 * The kinds of file a sitemap index can point at.
 *
 * The slug-shaped value is the URL segment, so a new kind gets a reachable address by being
 * added here rather than by a second routing table. `Static` is the one kind that is always
 * present: the landing pages exist whether or not the catalogue does.
 */
enum SitemapKind: string
{
    case Static = 'static';
    case Products = 'products';
    case Categories = 'categories';
    case Brands = 'brands';
    case Content = 'content';

    /**
     * The kinds whose URLs come from published database rows, and which are therefore
     * advertised only when there is at least one row to advertise.
     */
    public function isGeneratedFromContent(): bool
    {
        return self::Static !== $this;
    }
}
