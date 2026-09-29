<?php

declare(strict_types=1);

namespace App\Module\Seo;

/**
 * Everything a page can say about itself before the SEO layer decides anything.
 *
 * This is deliberately a description of facts, not of markup. The caller supplies the route
 * it is already rendering and the entity's own name and prose; the fallback order that turns
 * those into a title, a description and a canonical URL lives in one place, so a new content
 * type cannot accidentally grow a second, slightly different set of rules.
 */
final readonly class SeoPage
{
    /**
     * @param array<string, scalar> $routeParameters
     * @param list<SeoBreadcrumb>   $breadcrumbs
     */
    public function __construct(
        public string $route,
        public array $routeParameters = [],
        public ?string $label = null,
        public ?string $text = null,
        public ?SeoOverrides $overrides = null,
        public ?string $imagePath = null,
        public string $type = 'website',
        public bool $noIndex = false,
        public array $breadcrumbs = [],
    ) {
    }

    /**
     * The storefront home page. It has no label of its own — the store name is its title — and
     * no breadcrumb trail, because a single-crumb trail is not a trail.
     */
    public static function home(): self
    {
        return new self(route: 'app_home');
    }
}
