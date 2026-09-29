<?php

declare(strict_types=1);

namespace App\Module\Seo\Sitemap;

use App\Entity\Seo\SeoResourceType;

/**
 * One address a sitemap advertises, still in local terms.
 *
 * Carries the route name rather than a finished URL so the builder is the only place that turns
 * a route into an address. That is what keeps every published URL on the same code path as the
 * canonical link, instead of a second, separately-derived spelling that can disagree with it.
 */
final readonly class SitemapEntry
{
    public function __construct(
        public string $route,
        public string $slug,
        public ?string $lastModified = null,
    ) {
    }

    public static function forResource(SeoResourceType $type, string $slug, ?string $lastModified = null): self
    {
        return new self($type->routeName(), $slug, $lastModified);
    }
}
