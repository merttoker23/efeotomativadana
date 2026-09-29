<?php

declare(strict_types=1);

namespace App\Module\Seo;

/**
 * The single output of the SEO layer: everything a template needs to render a <head>.
 *
 * `robots` is a string rather than a bool because the value is written straight into a meta
 * tag, and there are two different directives to write: nothing at all for an ordinary page,
 * and "noindex, follow" for a page that is deliberately kept out of the index while still
 * passing its link equity on.
 */
final readonly class SeoMetadata
{
    /**
     * @param list<SeoBreadcrumb> $breadcrumbs
     * @param list<string>        $jsonLd     already-encoded <script> bodies
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $canonicalUrl,
        public ?string $imageUrl,
        public string $type,
        public bool $indexable,
        public ?string $robots,
        public string $siteName,
        public string $locale,
        public array $breadcrumbs,
        public array $jsonLd,
    ) {
    }
}
