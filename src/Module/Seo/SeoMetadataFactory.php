<?php

declare(strict_types=1);

namespace App\Module\Seo;

use App\Module\Seo\StructuredData\BreadcrumbListData;
use App\Module\Seo\StructuredData\OrganizationData;

/**
 * Turns "a page and what it is about" into the metadata a template renders.
 *
 * The fallback order is fixed and total, so no content type can produce an empty title, an
 * empty description or a missing canonical URL:
 *
 *   title       admin override  ->  "<label> | <store>"  ->  <store>
 *   description admin override  ->  summary of the page's own prose  ->  store default  ->  <store>
 *   canonical   the local route the page is already rendering, and nothing else
 *   robots      the page's own flag, or an admin override, both subject to the store switch
 *
 * The canonical is built from a route name on purpose. There is no parameter through which a
 * caller could hand in an address, so a product that arrived from the B2B feed cannot make
 * this store point its canonical at b2b.efeotoyedekparca.com.tr.
 */
final readonly class SeoMetadataFactory
{
    public function __construct(
        private SeoUrlFactory $urls,
        private SeoText $text,
        private StoreSeoContextProvider $store,
    ) {
    }

    public function for(SeoPage $page): SeoMetadata
    {
        $store = $this->store->current();
        $overrides = $page->overrides ?? new SeoOverrides();
        $indexable = $store->indexingEnabled && !$page->noIndex && true !== $overrides->noIndex;

        $graphs = [OrganizationData::for($store, $this->urls->baseUri())];
        // A trail of one is not a trail. Advertising a BreadcrumbList whose only step is "Home"
        // claims there is a hierarchy above this page when there is none, and a consumer that
        // believes it will render a breadcrumb that goes nowhere.
        if (2 <= count($page->breadcrumbs)) {
            $graphs[] = BreadcrumbListData::for($page->breadcrumbs);
        }

        return new SeoMetadata(
            title: $this->title($page, $overrides, $store),
            description: $this->description($page, $overrides, $store),
            canonicalUrl: $this->urls->absolute($page->route, $page->routeParameters),
            imageUrl: $this->urls->media($page->imagePath),
            type: $page->type,
            indexable: $indexable,
            robots: $indexable ? null : 'noindex, follow',
            siteName: $store->name,
            locale: $store->locale,
            breadcrumbs: $page->breadcrumbs,
            jsonLd: array_map(JsonLd::encode(...), $graphs),
        );
    }

    private function title(SeoPage $page, SeoOverrides $overrides, StoreSeoContext $store): string
    {
        $override = $this->clean($overrides->title);
        if (null !== $override) {
            return $override;
        }

        $label = $this->clean($page->label);

        return null === $label ? $store->name : $label.' | '.$store->name;
    }

    private function description(SeoPage $page, SeoOverrides $overrides, StoreSeoContext $store): string
    {
        $override = $this->clean($overrides->description);
        if (null !== $override) {
            return $override;
        }

        return $this->clean($this->text->summary($page->text))
            ?? $this->clean($store->defaultDescription)
            ?? $store->name;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
