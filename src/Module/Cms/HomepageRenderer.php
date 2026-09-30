<?php

namespace App\Module\Cms;

use App\Module\Catalog\Query\CatalogOption;
use App\Module\Catalog\Query\CatalogProductView;
use App\Module\Catalog\Query\CatalogQuery;
use App\Repository\Cms\BlogPostRepository;
use App\Repository\Cms\HomeSectionRepository;

final readonly class HomepageRenderer
{
    public function __construct(
        private HomeSectionRepository $sections,
        private CatalogQuery $catalog,
        private BlogPostRepository $posts,
    ) {}

    /**
     * Every section is rendered from the whole page at once.
     *
     * Two shapes of query used to repeat per section and, inside a tabbed section, once per tab:
     * a carousel or tab listing twenty products issued twenty lookups, and a category or brand
     * strip loaded the entire published list of categories or brands — each row carrying a
     * correlated product count — to pick the two or three slugs it was configured with.
     * Collecting the slugs first and resolving each kind once makes the whole homepage cost a
     * fixed number of queries no matter how many sections it has or how many entries they hold.
     *
     * @return list<HomeSectionView>
     */
    public function render(): array
    {
        $sections = $this->sections->ordered(true);
        $productSlugs = [];
        $categorySlugs = [];
        $brandSlugs = [];
        $tabsBySection = [];

        foreach ($sections as $section) {
            $config = SectionConfiguration::validate($section->type(), $section->configuration());
            match ($section->type()) {
                HomeSectionType::CategoryMenu => $categorySlugs = array_merge($categorySlugs, $config['slugs']),
                HomeSectionType::BrandStrip => $brandSlugs = array_merge($brandSlugs, $config['slugs']),
                HomeSectionType::ProductCarousel => $productSlugs = array_merge($productSlugs, $config['slugs']),
                HomeSectionType::ProductTabs => $tabsBySection[(int) $section->id()] = $config['tabs'],
                default => null,
            };
        }

        foreach ($tabsBySection as $tabs) {
            foreach ($tabs as $tab) {
                $productSlugs = array_merge($productSlugs, $tab['slugs']);
            }
        }

        $products = $this->catalog->products($productSlugs);
        $byProductSlug = [];
        foreach ($products as $product) {
            $byProductSlug[$product->slug] = $product;
        }
        $categories = $this->optionsBySlug('category', $categorySlugs);
        $brands = $this->optionsBySlug('brand', $brandSlugs);

        $result = [];
        foreach ($sections as $section) {
            $config = SectionConfiguration::validate($section->type(), $section->configuration());
            $data = $config;
            switch ($section->type()) {
                case HomeSectionType::CategoryMenu:
                    $data['categories'] = $this->pick($categories, $config['slugs']);
                    break;
                case HomeSectionType::BrandStrip:
                    $data['brands'] = $this->pick($brands, $config['slugs']);
                    break;
                case HomeSectionType::ProductCarousel:
                    $data['products'] = $this->pick($products, $config['slugs']);
                    break;
                case HomeSectionType::ProductTabs:
                    $data['tabs'] = array_map(
                        fn (array $tab): array => ['title' => $tab['title'], 'products' => $this->pick($products, $tab['slugs'])],
                        $tabsBySection[(int) $section->id()] ?? [],
                    );
                    break;
                case HomeSectionType::BlogFeed:
                    $data['posts'] = $this->posts->latestPublished($config['limit']);
                    break;
                default:
                    break;
            }
            $result[] = new HomeSectionView($section->type()->template(), $section->title(), $section->subtitle(), $data);
        }

        return $result;
    }

    /**
     * @template T of CatalogOption|CatalogProductView
     *
     * @param list<T>        $available
     * @param list<string>   $slugs
     *
     * @return list<T>
     */
    private function pick(array $available, array $slugs): array
    {
        $bySlug = [];
        foreach ($available as $option) {
            $bySlug[$option->slug] = $option;
        }

        $picked = [];
        foreach ($slugs as $slug) {
            if (isset($bySlug[$slug])) {
                $picked[] = $bySlug[$slug];
            }
        }

        return $picked;
    }

    /**
     * @param list<string> $slugs
     *
     * @return list<CatalogOption>
     */
    private function optionsBySlug(string $kind, array $slugs): array
    {
        return $this->catalog->optionsBySlug($kind, $slugs);
    }
}
