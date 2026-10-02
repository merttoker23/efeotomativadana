<?php

declare(strict_types=1);

namespace App\Module\Cms;

use App\Module\Catalog\Query\CatalogCriteria;
use App\Module\Catalog\Query\CatalogOption;
use App\Module\Catalog\Query\CatalogQuery;
use App\Module\Catalog\Query\CatalogSort;

/**
 * The searchable source behind every "pick a product / category / brand" control.
 *
 * Three separate shapes are needed and one bounded rule covers all of them: an administrator
 * never gets the whole catalogue. Categories and brands are small enough that a filtered list
 * of the published set is genuinely usable, so the filter is applied in memory over a capped
 * read. Products are not — the catalogue is expected to hold tens of thousands of rows — so a
 * product lookup is always a query, and an empty query returns the newest published products so
 * the control is never blank.
 *
 * Only published records are ever returned. A section may therefore reference a product that is
 * later unpublished, and the storefront resolves what is left of it; it can never be made to
 * advertise a draft.
 */
final readonly class CmsOptionCatalog
{
    /** Enough categories and brands to browse, small enough that the form stays a fixed cost. */
    private const int OPTION_SCAN = 400;

    /** Rows per product search. The result is a picker, not a catalogue page. */
    private const int PRODUCT_PAGE = 20;

    public function __construct(private CatalogQuery $catalog) {}

    /** @return list<CmsSelectOption> */
    public function categories(?string $query = null, int $limit = self::OPTION_SCAN): array
    {
        return $this->options($this->catalog->categories(), $query, $limit);
    }

    /** @return list<CmsSelectOption> */
    public function brands(?string $query = null, int $limit = self::OPTION_SCAN): array
    {
        return $this->options($this->catalog->brands(), $query, $limit);
    }

    /** @return list<CmsSelectOption> */
    public function products(?string $query = null): array
    {
        $query = $this->search($query);
        $page = $this->catalog->search(new CatalogCriteria(
            query: $query,
            sort: CatalogSort::Newest,
            page: 1,
            perPage: self::PRODUCT_PAGE,
        ));

        $options = [];
        foreach ($page->items as $product) {
            $options[] = new CmsSelectOption(
                $product->slug,
                $product->name,
                trim($product->sku.('' === $product->brandName ? '' : ' · '.$product->brandName), ' ·'),
            );
        }

        return $options;
    }

    /**
     * The options a section form offers, for the kind and the search term it asked for.
     *
     * The kind is whitelisted against the three the catalogue has. A request cannot name a fourth
     * kind, and an unknown one is refused rather than silently falling back, so a form always
     * renders the options its own fields expect.
     *
     * @return list<CmsSelectOption>
     */
    public function fromRequest(string $kind, ?string $query): array
    {
        $term = $this->search($query);

        return match ($kind) {
            'products' => $this->products($term),
            'categories' => $this->categories($term),
            'brands' => $this->brands($term),
            default => throw new \InvalidArgumentException('Unknown selection kind.'),
        };
    }

    /**
     * Labels for slugs a section already references, so an existing row can be rendered as a
     * name instead of as its slug. Anything no longer published is simply absent, which is the
     * same silent drop the storefront applies and not an error to raise here.
     *
     * @param list<string> $slugs
     *
     * @return array<string, CmsSelectOption>
     */
    public function labelSlugs(string $kind, array $slugs): array
    {
        $known = match ($kind) {
            'products' => $this->labelProducts($slugs),
            'categories' => $this->labelOptions($this->catalog->categories(), $slugs),
            'brands' => $this->labelOptions($this->catalog->brands(), $slugs),
            default => throw new \InvalidArgumentException('Unknown selection kind.'),
        };

        $labels = [];
        foreach ($known as $option) {
            $labels[$option->slug] = $option;
        }

        return $labels;
    }

    /**
     * @param list<string> $slugs
     *
     * @return list<CmsSelectOption>
     */
    private function labelProducts(array $slugs): array
    {
        if ([] === $slugs) {
            return [];
        }

        $options = [];
        foreach ($this->catalog->products($slugs) as $product) {
            $options[] = new CmsSelectOption($product->slug, $product->name, $product->sku);
        }

        return $options;
    }

    /**
     * @param list<CatalogOption> $options
     * @param list<string>        $slugs
     *
     * @return list<CmsSelectOption>
     */
    private function labelOptions(array $options, array $slugs): array
    {
        $wanted = array_flip($slugs);
        $labelled = [];
        foreach ($options as $option) {
            if (isset($wanted[$option->slug])) {
                $labelled[] = new CmsSelectOption($option->slug, $option->name, $option->productCount.' ürün');
            }
        }

        return $labelled;
    }

    /**
     * @param list<CatalogOption> $options
     *
     * @return list<CmsSelectOption>
     */
    private function options(array $options, ?string $query, int $limit): array
    {
        $needle = mb_strtolower($this->search($query) ?? '');
        $selected = [];
        foreach ($options as $option) {
            if ('' === $needle || str_contains(mb_strtolower($option->name), $needle) || str_contains($option->slug, $needle)) {
                $selected[] = new CmsSelectOption($option->slug, $option->name, $option->productCount.' ürün');
            }
            if (count($selected) >= $limit) {
                break;
            }
        }

        return $selected;
    }

    private function search(?string $query): ?string
    {
        $query = mb_substr(trim((string) $query), 0, 120);

        return '' === $query ? null : $query;
    }
}
