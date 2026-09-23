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

    /** @return list<HomeSectionView> */
    public function render(): array
    {
        $result = [];
        foreach ($this->sections->ordered(true) as $section) {
            $config = SectionConfiguration::validate($section->type(), $section->configuration());
            $data = $config;
            switch ($section->type()) {
                case HomeSectionType::CategoryMenu:
                    $data['categories'] = $this->options($this->catalog->categories(), $config['slugs']);
                    break;
                case HomeSectionType::BrandStrip:
                    $data['brands'] = $this->options($this->catalog->brands(), $config['slugs']);
                    break;
                case HomeSectionType::ProductCarousel:
                    $data['products'] = $this->products($config['slugs']);
                    break;
                case HomeSectionType::ProductTabs:
                    $data['tabs'] = array_map(fn (array $tab): array => ['title' => $tab['title'], 'products' => $this->products($tab['slugs'])], $config['tabs']);
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

    /** @param list<CatalogOption> $available
     *  @param list<string> $slugs
     *  @return list<CatalogOption>
     */
    private function options(array $available, array $slugs): array
    {
        $bySlug = [];
        foreach ($available as $option) { $bySlug[$option->slug] = $option; }
        return array_values(array_filter(array_map(static fn (string $slug): ?CatalogOption => $bySlug[$slug] ?? null, $slugs)));
    }

    /** @param list<string> $slugs
     *  @return list<CatalogProductView>
     */
    private function products(array $slugs): array
    {
        $products = [];
        foreach ($slugs as $slug) {
            $detail = $this->catalog->product($slug);
            if (null === $detail) { continue; }
            $products[] = new CatalogProductView(
                $detail->id, $detail->sku, $detail->name, $detail->slug,
                $detail->brandName, $detail->brandSlug,
                $detail->images[0]['path'] ?? null, $detail->images[0]['alt'] ?? null,
                $detail->basePrice, $detail->sellPrice, $detail->onSale,
                $detail->quantity, $detail->sellable,
            );
        }
        return $products;
    }
}
