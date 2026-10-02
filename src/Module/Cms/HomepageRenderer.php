<?php

declare(strict_types=1);

namespace App\Module\Cms;

use App\Module\Catalog\Query\CatalogOption;
use App\Module\Catalog\Query\CatalogProductView;
use App\Module\Catalog\Query\CatalogQuery;
use App\Repository\Cms\BlogPostRepository;
use App\Repository\Cms\HomeSectionRepository;
use App\Entity\Cms\HomeSection;

final readonly class HomepageRenderer
{
    public function __construct(
        private HomeSectionRepository $sections,
        private CatalogQuery $catalog,
        private BlogPostRepository $posts,
    ) {}

    /**
     * The whole homepage, resolved once.
     *
     * Two shapes of query used to repeat per section and, inside a tabbed section, once per tab:
     * a carousel or tab listing twenty products issued twenty lookups, and a category or brand
     * strip loaded the entire published list of categories or brands — each row carrying a
     * correlated product count — to pick the two or three slugs it was configured with.
     * Collecting the slugs first and resolving each kind once makes the whole homepage cost a
     * fixed number of queries no matter how many sections it has or how many entries they hold.
     *
     * The theme's own composition is decided here rather than in the template: the upper band
     * takes the first category menu, hero slider and product carousel, and the lower band the
     * first testimonials and blog feed. The first of a kind fills its slot because the theme has
     * exactly one of it, and any further section of that kind stays in the block list as a
     * standalone block rather than being silently discarded.
     */
    public function render(): HomepageView
    {
        $sections = $this->sections->ordered(true);
        $productSlugs = [];
        $categorySlugs = [];
        $brandSlugs = [];
        $validated = [];

        foreach ($sections as $section) {
            $config = SectionConfiguration::validate($section->type(), $section->configuration());
            $validated[] = $config;
            match ($section->type()) {
                HomeSectionType::CategoryMenu => $categorySlugs = array_merge($categorySlugs, $config['slugs']),
                HomeSectionType::BrandStrip => $brandSlugs = array_merge($brandSlugs, $config['slugs']),
                HomeSectionType::ProductCarousel, HomeSectionType::SplitBuilder => $productSlugs = array_merge($productSlugs, $config['slugs']),
                HomeSectionType::ProductTabs => $productSlugs = array_merge($productSlugs, ...array_column($config['tabs'], 'slugs')),
                default => null,
            };
        }

        $byProductSlug = [];
        foreach ($this->catalog->products($productSlugs) as $product) {
            $byProductSlug[$product->slug] = $product;
        }
        $categories = $this->bySlug($this->catalog->optionsBySlug('category', $categorySlugs));
        $brands = $this->bySlug($this->catalog->optionsBySlug('brand', $brandSlugs));

        $slots = [
            HomeSectionType::AnnouncementBar->value => 'announcement',
            HomeSectionType::CategoryMenu->value => 'categoryMenu',
            HomeSectionType::HeroSlider->value => 'heroSlider',
            HomeSectionType::ProductCarousel->value => 'topSellers',
            HomeSectionType::Testimonials->value => 'testimonials',
            HomeSectionType::BlogFeed->value => 'blogFeed',
        ];
        $filled = [];
        $blocks = [];

        foreach ($sections as $position => $section) {
            $sectionView = $this->view($section, $validated[$position], $byProductSlug, $categories, $brands);
            $slot = $slots[$section->type()->value] ?? null;

            if (null === $slot || isset($filled[$slot])) {
                $blocks[] = $sectionView;

                continue;
            }
            $filled[$slot] = $sectionView;
        }

        return new HomepageView(
            $filled['announcement'] ?? null,
            $filled['categoryMenu'] ?? null,
            $filled['heroSlider'] ?? null,
            $filled['topSellers'] ?? null,
            $filled['testimonials'] ?? null,
            $filled['blogFeed'] ?? null,
            $blocks,
        );
    }

    /**
     * @param array<string, mixed>                 $config    the section's own validated configuration
     * @param array<string, CatalogProductView>    $productsBySlug
     * @param array<string, CatalogOption>          $categories
     * @param array<string, CatalogOption>          $brands
     */
    private function view(HomeSection $section, array $config, array $productsBySlug, array $categories, array $brands): HomeSectionView
    {
        $data = $config;
        switch ($section->type()) {
            case HomeSectionType::CategoryMenu:
                $data['categories'] = $this->pick($categories, $config['slugs']);

                break;
            case HomeSectionType::BrandStrip:
                $data['brands'] = $this->pick($brands, $config['slugs']);

                break;
            case HomeSectionType::ProductCarousel:
                $data['products'] = $this->pick($productsBySlug, $config['slugs']);

                break;
            case HomeSectionType::SplitBuilder:
                // Both draw a row of chosen products; the split builder simply draws its row
                // beside a promotional panel rather than across a whole card.
                $data['products'] = $this->pick($productsBySlug, $config['slugs']);

                break;
            case HomeSectionType::ProductTabs:
                // A tab whose products have all been unpublished would be a tab that opens onto
                // nothing, so it is dropped with its siblings rather than offered and left empty.
                $data['tabs'] = array_values(array_filter(
                    array_map(
                        fn (array $tab): array => ['title' => $tab['title'], 'products' => $this->pick($productsBySlug, $tab['slugs'])],
                        $config['tabs'],
                    ),
                    static fn (array $tab): bool => [] !== $tab['products'],
                ));

                break;
            case HomeSectionType::BlogFeed:
                $data['posts'] = $this->posts->latestPublished($config['limit']);

                break;
            default:
                break;
        }

        return new HomeSectionView($section->type()->template(), $section->title(), $section->subtitle(), $data);
    }

    /**
     * @param list<CatalogOption> $options
     *
     * @return array<string, CatalogOption>
     */
    private function bySlug(array $options): array
    {
        $map = [];
        foreach ($options as $option) {
            $map[$option->slug] = $option;
        }

        return $map;
    }

    /**
     * @param array<string, CatalogProductView|CatalogOption> $available
     * @param list<string>                                     $slugs
     *
     * @return list<CatalogProductView|CatalogOption>
     */
    private function pick(array $available, array $slugs): array
    {
        $picked = [];
        foreach ($slugs as $slug) {
            if (isset($available[$slug])) {
                $picked[] = $available[$slug];
            }
        }

        return $picked;
    }
}
