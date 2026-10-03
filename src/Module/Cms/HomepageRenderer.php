<?php

declare(strict_types=1);

namespace App\Module\Cms;

use App\Module\Catalog\ProductFeedSource;
use App\Module\Catalog\Query\CatalogOption;
use App\Module\Catalog\Query\CatalogProductView;
use App\Module\Catalog\Query\CatalogQuery;
use App\Repository\Cms\BlogPostRepository;
use App\Repository\Cms\HomeSectionRepository;
use App\Entity\Cms\HomeSection;

final readonly class HomepageRenderer
{
    /** The width of the theme's tab grid, and so how many products one tab shows. */
    private const int TAB_PRODUCTS = 5;

    public function __construct(
        private HomeSectionRepository $sections,
        private CatalogQuery $catalog,
        private BlogPostRepository $posts,
    ) {}

    /**
     * The whole homepage, resolved once.
     *
     * Three shapes of query used to repeat per section and, inside a tabbed section, once per tab:
     * a carousel or tab listing twenty products issued twenty lookups, and a category or brand
     * strip loaded the entire published list of categories or brands — each row carrying a
     * correlated product count — to pick the two or three slugs it was configured with.
     * Collecting the slugs first and resolving each kind once makes the whole homepage cost a
     * fixed number of queries no matter how many sections it has or how many entries they hold.
     *
     * A product tab resolves the same way, and by the same rule: its configuration names where its
     * products come from, and each distinct source present on the page is asked for exactly once no
     * matter how many tabs share it. So four tabs cost four bounded aggregate reads in total, not
     * one per tab and not one per product.
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
        $feeds = [];
        $validated = [];

        foreach ($sections as $section) {
            $config = SectionConfiguration::validate($section->type(), $section->configuration());
            $validated[] = $config;
            match ($section->type()) {
                HomeSectionType::CategoryMenu => $categorySlugs = array_merge($categorySlugs, $config['slugs']),
                HomeSectionType::BrandStrip => $brandSlugs = array_merge($brandSlugs, $config['slugs']),
                HomeSectionType::ProductCarousel, HomeSectionType::SplitBuilder => $productSlugs = array_merge($productSlugs, $config['slugs']),
                // Only the tabs that name their own products join the batch read below; the others
                // are answered by their own aggregate further down.
                HomeSectionType::ProductTabs => $productSlugs = array_merge($productSlugs, ...array_column($this->namedTabs($config['tabs']), 'slugs')),
                default => null,
            };
            if (HomeSectionType::ProductTabs === $section->type()) {
                foreach ($config['tabs'] as $tab) {
                    $source = ProductFeedSource::normalize($tab['source'] ?? null);
                    if (!$source->isManual()) {
                        $feeds[$source->value] = $source;
                    }
                }
            }
        }

        $byProductSlug = [];
        foreach ($this->catalog->products($productSlugs) as $product) {
            $byProductSlug[$product->slug] = $product;
        }
        $categories = $this->bySlug($this->catalog->optionsBySlug('category', $categorySlugs));
        $brands = $this->bySlug($this->catalog->optionsBySlug('brand', $brandSlugs));
        $ranked = [];
        foreach ($feeds as $value => $feed) {
            $ranked[$value] = $this->catalog->productsBySource($feed, self::TAB_PRODUCTS);
        }

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
            $sectionView = $this->view($section, $validated[$position], $byProductSlug, $categories, $brands, $ranked);
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
     * The tabs of one product-tab section that name their products, i.e. the manual ones.
     *
     * @param list<array<string, mixed>> $tabs
     *
     * @return list<array<string, mixed>>
     */
    private function namedTabs(array $tabs): array
    {
        return array_values(array_filter(
            $tabs,
            static fn (array $tab): bool => ProductFeedSource::normalize($tab['source'] ?? null)->isManual(),
        ));
    }

    /**
     * @param array<string, mixed>              $config      the section's own validated configuration
     * @param array<string, CatalogProductView> $productsBySlug
     * @param array<string, CatalogOption>      $categories
     * @param array<string, CatalogOption>      $brands
     * @param array<string, list<CatalogProductView>> $ranked  one resolved list per automatic source on the page
     */
    private function view(HomeSection $section, array $config, array $productsBySlug, array $categories, array $brands, array $ranked): HomeSectionView
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
                // Every tab is kept, including one with nothing to show. A tab that had all of its
                // products unpublished used to be dropped here, which meant a "Çok Satanlar" tab
                // quietly vanished and the page was left claiming less than it could have shown; the
                // panel now says why it is empty instead, which is the same answer the source itself
                // would have given. An empty source is never filled with products from elsewhere:
                // the tab and its title are what the administrator chose, and borrowing another
                // source's products would make the page say something it does not mean.
                $data['tabs'] = array_map(
                    fn (array $tab): array => [
                        'title' => $tab['title'],
                        'source' => ProductFeedSource::normalize($tab['source'] ?? null),
                        'products' => $this->tabProducts($tab, $productsBySlug, $ranked),
                    ],
                    $config['tabs'],
                );

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
     * One tab's products, from the source its own configuration named.
     *
     * @param array<string, mixed>                     $tab
     * @param array<string, CatalogProductView>        $productsBySlug
     * @param array<string, list<CatalogProductView>>  $ranked
     *
     * @return list<CatalogProductView>
     */
    private function tabProducts(array $tab, array $productsBySlug, array $ranked): array
    {
        $source = ProductFeedSource::normalize($tab['source'] ?? null);

        return $source->isManual()
            ? $this->pick($productsBySlug, $tab['slugs'])
            : ($ranked[$source->value] ?? []);
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
