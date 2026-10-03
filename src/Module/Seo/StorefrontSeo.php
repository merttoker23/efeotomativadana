<?php

declare(strict_types=1);

namespace App\Module\Seo;

use App\Entity\Cms\BlogPost;
use App\Entity\Cms\InformationPage;
use App\Entity\Seo\SeoResourceType;
use App\Module\Catalog\Query\CatalogOption;
use App\Module\Catalog\Query\CatalogProductDetail;
use App\Module\Seo\StructuredData\ProductData;

/**
 * The one place that knows what a storefront page is *about*, expressed as SEO metadata.
 *
 * Controllers describe which page they are rendering; this turns that into a title, a
 * description, a canonical URL, a breadcrumb trail and the structured data for that page type.
 * It exists so those answers cannot drift: five controllers each assembling their own
 * breadcrumbs is how a page ends up showing a trail in the markup that disagrees with the one
 * in its JSON-LD, and a canonical that disagrees with the sitemap.
 *
 * Breadcrumb trails mirror the trail the page actually renders. A trail the visitor cannot
 * follow is decoration, and schema.org's BreadcrumbList is a claim that these are the levels
 * between the home page and this one.
 */
final readonly class StorefrontSeo
{
    public function __construct(
        private SeoMetadataFactory $metadata,
        private SeoOverrideReader $overrides,
        private ProductData $products,
        private SeoUrlFactory $urls,
    ) {
    }

    public function home(): SeoMetadata
    {
        return $this->metadata->for(SeoPage::home());
    }

    /**
     * A catalogue listing. `filter` is a facet of the same page — a brand inside the
     * catalogue, say — and is not a canonical address of its own: the canonical stays on the
     * unfiltered listing, because a filtered view is a subset somebody can produce by adding
     * a query string, and letting each combination be its own canonical is how a store
     * teaches a crawler that it has the same page many times over.
     */
    public function catalogListing(string $heading, ?string $categorySlug = null, ?string $brandSlug = null): SeoMetadata
    {
        $route = match (true) {
            null !== $categorySlug => 'storefront_catalog_category',
            null !== $brandSlug => 'storefront_catalog_brand',
            default => 'storefront_catalog_index',
        };
        $parameters = match (true) {
            null !== $categorySlug => ['slug' => $categorySlug],
            null !== $brandSlug => ['slug' => $brandSlug],
            default => [],
        };

        $trail = [];
        if ('' !== $heading) {
            $trail = $this->trail([
                new SeoBreadcrumb('Ürünler', $this->urls->absolute('storefront_catalog_index')),
                // The trail has to END at this page. A consumer renders the last step as the
                // page it is on, and a trail that stops on a different address is discarded
                // whole — while here it would also disagree with the crumb the markup shows.
                new SeoBreadcrumb($heading, $this->urls->absolute($route, $parameters)),
            ]);
        }

        return $this->metadata->for(new SeoPage(
            route: $route,
            routeParameters: $parameters,
            label: $heading,
            breadcrumbs: $trail,
        ));
    }

    public function brandsIndex(): SeoMetadata
    {
        return $this->metadata->for(new SeoPage(
            route: 'storefront_catalog_brands',
            label: 'Markalar',
            breadcrumbs: $this->trail(),
        ));
    }

    /**
     * The collections page: the products that are on a discount at this moment.
     *
     * A landing page with an address of its own rather than a filtered view of the catalogue, so
     * its canonical is the collections address and the whole catalogue is never presented to a
     * crawler as this page. The trail still starts at the catalogue, because a shopper who lands
     * here is looking at the same products the catalogue lists — from the discounted subset of them.
     */
    public function collections(): SeoMetadata
    {
        return $this->metadata->for(new SeoPage(
            route: 'storefront_collections_index',
            label: 'Koleksiyonlar',
            text: 'Şu anda indirimli olan yayınlanmış ürünler.',
            breadcrumbs: $this->trail([
                new SeoBreadcrumb('Ürünler', $this->urls->absolute('storefront_catalog_index')),
                new SeoBreadcrumb('Koleksiyonlar', $this->urls->absolute('storefront_collections_index')),
            ]),
        ));
    }

    public function product(CatalogProductDetail $product): SeoMetadata
    {
        $canonical = $this->urls->absolute('storefront_catalog_product', ['slug' => $product->slug]);
        $metadata = $this->metadata->for(new SeoPage(
            route: 'storefront_catalog_product',
            routeParameters: ['slug' => $product->slug],
            label: $product->name,
            text: $product->description,
            overrides: $this->overrides->for(SeoResourceType::Product, $product->id),
            imagePath: $product->images[0]['path'] ?? null,
            type: 'product',
            breadcrumbs: $this->trail([
                new SeoBreadcrumb('Ürünler', $this->urls->absolute('storefront_catalog_index')),
                new SeoBreadcrumb($product->name, $canonical),
            ]),
        ));

        return $this->withGraph($metadata, $this->products->for($product, $metadata->canonicalUrl));
    }

    public function blogIndex(): SeoMetadata
    {
        return $this->metadata->for(new SeoPage(
            route: 'storefront_blog_index',
            label: 'Blog',
            breadcrumbs: $this->trail(),
        ));
    }

    public function blogPost(BlogPost $post): SeoMetadata
    {
        return $this->cmsContent(
            SeoResourceType::BlogPost,
            (int) $post->id(),
            'storefront_blog_show',
            $post->slug(),
            $post->title(),
            $post->excerpt(),
        );
    }

    public function informationIndex(): SeoMetadata
    {
        return $this->metadata->for(new SeoPage(
            route: 'storefront_information_index',
            label: 'Bilgi',
            breadcrumbs: $this->trail(),
        ));
    }

    public function informationPage(InformationPage $page): SeoMetadata
    {
        return $this->cmsContent(
            SeoResourceType::InformationPage,
            (int) $page->id(),
            'storefront_information_show',
            $page->slug(),
            $page->title(),
            $page->body(),
        );
    }

    /**
     * @param list<SeoBreadcrumb> $steps after the home page
     *
     * @return list<SeoBreadcrumb>
     */
    private function trail(array $steps = []): array
    {
        return [new SeoBreadcrumb('Ana Sayfa', $this->urls->absolute('app_home')), ...$steps];
    }

    private function cmsContent(
        SeoResourceType $type,
        int $id,
        string $route,
        string $slug,
        string $title,
        string $body,
    ): SeoMetadata {
        $indexRoute = SeoResourceType::BlogPost === $type ? 'storefront_blog_index' : 'storefront_information_index';
        $indexLabel = SeoResourceType::BlogPost === $type ? 'Blog' : 'Bilgi';

        return $this->metadata->for(new SeoPage(
            route: $route,
            routeParameters: ['slug' => $slug],
            label: $title,
            text: $body,
            overrides: $this->overrides->for($type, $id),
            type: 'article',
            breadcrumbs: $this->trail([
                new SeoBreadcrumb($indexLabel, $this->urls->absolute($indexRoute)),
                new SeoBreadcrumb($title, $this->urls->absolute($route, ['slug' => $slug])),
            ]),
        ));
    }

    /**
     * @param array<string, mixed> $graph
     */
    private function withGraph(SeoMetadata $metadata, array $graph): SeoMetadata
    {
        return new SeoMetadata(
            title: $metadata->title,
            description: $metadata->description,
            canonicalUrl: $metadata->canonicalUrl,
            imageUrl: $metadata->imageUrl,
            type: $metadata->type,
            indexable: $metadata->indexable,
            robots: $metadata->robots,
            siteName: $metadata->siteName,
            locale: $metadata->locale,
            breadcrumbs: $metadata->breadcrumbs,
            jsonLd: [...$metadata->jsonLd, JsonLd::encode($graph)],
        );
    }
}
