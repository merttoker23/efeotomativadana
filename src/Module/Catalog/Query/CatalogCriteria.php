<?php

namespace App\Module\Catalog\Query;

use Symfony\Component\HttpFoundation\InputBag;

/**
 * One catalogue listing request: what is filtered, how it is sorted, and which slice of it is asked
 * for.
 *
 * The default page size of 12 is the paged reading of the catalogue. A listing that loads the rest
 * of itself as the customer scrolls passes a size of its own instead — see
 * {@see self::fromQuery()} — because that is a different reading of the same query rather than a
 * different query: the filters, the sort and the page number all stay exactly as they were.
 *
 * `onSaleOnly` is the one restriction that is not a filter a customer chose. It is the question a
 * collections page asks — which published products are on a discount right now — and it belongs to
 * the page rather than to the address, so it is deliberately absent from
 * {@see self::filterParameters()}: the next page of a collections listing is asked for at the same
 * collections address, and a reader who edits the query string cannot switch it off.
 */
final readonly class CatalogCriteria
{
    public ?string $query;
    public ?string $categorySlug;
    public ?string $brandSlug;
    public bool $inStockOnly;
    public bool $onSaleOnly;
    public CatalogSort $sort;
    public int $page;
    public int $perPage;

    public function __construct(
        ?string $query = null,
        ?string $categorySlug = null,
        ?string $brandSlug = null,
        bool $inStockOnly = false,
        CatalogSort $sort = CatalogSort::Newest,
        int $page = 1,
        int $perPage = 12,
        bool $onSaleOnly = false,
    ) {
        $this->query = self::text($query, 120);
        $this->categorySlug = self::slug($categorySlug);
        $this->brandSlug = self::slug($brandSlug);
        $this->inStockOnly = $inStockOnly;
        $this->onSaleOnly = $onSaleOnly;
        $this->sort = $sort;
        $this->page = max(1, $page);
        $this->perPage = min(48, max(1, $perPage));
    }

    /**
     * @template TInput of string|int|float|bool|null
     *
     * @param InputBag<TInput> $query
     */
    public static function fromQuery(
        InputBag $query,
        ?string $categorySlug = null,
        ?string $brandSlug = null,
        ?int $perPage = null,
        bool $onSaleOnly = false,
    ): self {
        return new self(
            query: $query->getString('q'),
            categorySlug: $categorySlug ?? $query->getString('category'),
            brandSlug: $brandSlug ?? $query->getString('brand'),
            inStockOnly: 'in-stock' === $query->getString('availability'),
            sort: CatalogSort::tryFrom($query->getString('sort')) ?? CatalogSort::Newest,
            page: $query->getInt('page', 1),
            perPage: $perPage ?? 12,
            onSaleOnly: $onSaleOnly,
        );
    }

    /** @return array<string, string> */
    public function filterParameters(?string $excludedFilter = null): array
    {
        $parameters = [];
        if (null !== $this->query) {
            $parameters['q'] = $this->query;
        }
        if (null !== $this->categorySlug && 'category' !== $excludedFilter) {
            $parameters['category'] = $this->categorySlug;
        }
        if (null !== $this->brandSlug && 'brand' !== $excludedFilter) {
            $parameters['brand'] = $this->brandSlug;
        }
        if ($this->inStockOnly) {
            $parameters['availability'] = 'in-stock';
        }

        return $parameters;
    }

    /** @return array<string, string|int> */
    public function queryParameters(?int $page = null, ?string $excludedFilter = null): array
    {
        return [
            ...$this->filterParameters($excludedFilter),
            'sort' => $this->sort->value,
            ...null === $page ? [] : ['page' => max(1, $page)],
        ];
    }

    private static function text(?string $value, int $maxLength): ?string
    {
        $value = null === $value ? '' : trim($value);

        return '' === $value ? null : mb_substr($value, 0, $maxLength);
    }

    private static function slug(?string $value): ?string
    {
        $value = self::text($value, 255);

        return null === $value ? null : mb_strtolower($value);
    }
}
