<?php

namespace App\Repository\Catalog;

use App\Module\Catalog\ProductIdentifierType;
use App\Module\Catalog\PublicationStatus;
use App\Module\Catalog\Query\CatalogCriteria;
use App\Module\Catalog\Query\CatalogOption;
use App\Module\Catalog\Query\CatalogPage;
use App\Module\Catalog\Query\CatalogProductDetail;
use App\Module\Catalog\Query\CatalogProductView;
use App\Module\Catalog\Query\CatalogSort;
use App\Shared\Money\Money;
use App\Shared\PagedResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Psr\Clock\ClockInterface;

final readonly class CatalogReadRepository
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function search(CatalogCriteria $criteria): CatalogPage
    {
        $countQuery = $this->filteredProducts($criteria)
            ->select('COUNT(DISTINCT product.id)');
        $totalItems = (int) $countQuery->executeQuery()->fetchOne();
        $totalPages = max(1, (int) ceil($totalItems / $criteria->perPage));
        $page = min($criteria->page, $totalPages);
        $now = $this->databaseTime($this->clock->now());
        $effectivePrice = $this->effectivePriceExpression();

        $query = $this->filteredProducts($criteria)
            ->select(
                'product.id',
                'product.sku',
                'product.name',
                'product.slug',
                'brand.name AS brand_name',
                'brand.slug AS brand_slug',
                '(SELECT image.path FROM catalog_product_image image WHERE image.product_id = product.id ORDER BY image.sort_order ASC, image.id ASC LIMIT 1) AS image_path',
                '(SELECT image.alt_text FROM catalog_product_image image WHERE image.product_id = product.id ORDER BY image.sort_order ASC, image.id ASC LIMIT 1) AS image_alt',
                'price.base_minor_amount',
                'price.sale_minor_amount',
                'price.currency',
                'price.sale_starts_at',
                'price.sale_ends_at',
                'COALESCE(inventory.quantity, 0) AS quantity',
                'COALESCE(inventory.available_for_sale, 0) AS available_for_sale',
            )
            ->leftJoin(
                'product',
                'catalog_brand',
                'brand',
                'brand.id = product.brand_id AND brand.publication_status = :published',
            )
            ->leftJoin('product', 'commerce_product_price', 'price', 'price.product_id = product.id')
            ->leftJoin('product', 'commerce_product_inventory', 'inventory', 'inventory.product_id = product.id')
            ->setParameter('now', $now)
            ->setFirstResult(($page - 1) * $criteria->perPage)
            ->setMaxResults($criteria->perPage);

        match ($criteria->sort) {
            CatalogSort::Newest => $query->orderBy('product.created_at', 'DESC')->addOrderBy('product.id', 'DESC'),
            CatalogSort::NameAscending => $query->orderBy('product.name', 'ASC')->addOrderBy('product.id', 'ASC'),
            CatalogSort::PriceAscending => $query
                ->orderBy('CASE WHEN price.id IS NULL THEN 1 ELSE 0 END', 'ASC')
                ->addOrderBy($effectivePrice, 'ASC')
                ->addOrderBy('product.id', 'ASC'),
            CatalogSort::PriceDescending => $query
                ->orderBy('CASE WHEN price.id IS NULL THEN 1 ELSE 0 END', 'ASC')
                ->addOrderBy($effectivePrice, 'DESC')
                ->addOrderBy('product.id', 'ASC'),
        };

        $items = array_map(
            fn (array $row): CatalogProductView => $this->productView($row),
            $query->executeQuery()->fetchAllAssociative(),
        );

        return new CatalogPage($items, $totalItems, $page, $criteria->perPage);
    }

    public function findPublishedProduct(string $slug): ?CatalogProductDetail
    {
        $row = $this->connection->createQueryBuilder()
            ->select(
                'product.id',
                'product.sku',
                'product.name',
                'product.slug',
                'product.description',
                'brand.name AS brand_name',
                'brand.slug AS brand_slug',
                'price.base_minor_amount',
                'price.sale_minor_amount',
                'price.currency',
                'price.sale_starts_at',
                'price.sale_ends_at',
                'COALESCE(inventory.quantity, 0) AS quantity',
                'COALESCE(inventory.available_for_sale, 0) AS available_for_sale',
            )
            ->from('catalog_product', 'product')
            ->leftJoin(
                'product',
                'catalog_brand',
                'brand',
                'brand.id = product.brand_id AND brand.publication_status = :published',
            )
            ->leftJoin('product', 'commerce_product_price', 'price', 'price.product_id = product.id')
            ->leftJoin('product', 'commerce_product_inventory', 'inventory', 'inventory.product_id = product.id')
            ->where('product.slug = :slug')
            ->andWhere('product.publication_status = :published')
            ->setParameter('slug', mb_strtolower(trim($slug)))
            ->setParameter('published', PublicationStatus::Published->value)
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            return null;
        }

        $productId = (int) $row['id'];
        $images = array_map(
            static fn (array $image): array => [
                'path' => (string) $image['path'],
                'alt' => (string) ($image['alt_text'] ?: $row['name']),
            ],
            $this->connection->fetchAllAssociative(
                'SELECT path, alt_text FROM catalog_product_image WHERE product_id = ? ORDER BY sort_order ASC, id ASC',
                [$productId],
            ),
        );
        $identifiers = array_map(
            static fn (array $identifier): array => [
                'type' => (string) $identifier['identifier_type'],
                'label' => match ((string) $identifier['identifier_type']) {
                    ProductIdentifierType::Oem->value => 'OEM Kodu',
                    ProductIdentifierType::Manufacturer->value => 'Üretici Kodu',
                    ProductIdentifierType::Reference->value => 'Referans Kodu',
                    default => 'Parça Kodu',
                },
                'code' => (string) $identifier['code'],
            ],
            $this->connection->fetchAllAssociative(
                'SELECT identifier_type, code FROM catalog_product_identifier WHERE product_id = ? ORDER BY identifier_type ASC, code ASC',
                [$productId],
            ),
        );
        $attributes = array_map(
            static fn (array $attribute): array => [
                'key' => (string) $attribute['attribute_key'],
                'label' => mb_convert_case(str_replace('-', ' ', (string) $attribute['attribute_key']), \MB_CASE_TITLE, 'UTF-8'),
                'value' => (string) $attribute['attribute_value'],
            ],
            $this->connection->fetchAllAssociative(
                'SELECT attribute_key, attribute_value FROM catalog_product_attribute WHERE product_id = ? ORDER BY attribute_key ASC',
                [$productId],
            ),
        );
        $categories = array_map(
            static fn (array $category): CatalogOption => new CatalogOption(
                (string) $category['name'],
                (string) $category['slug'],
                0,
            ),
            $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT category.name, category.slug
                    FROM catalog_category category
                    INNER JOIN catalog_product_category relation ON relation.category_id = category.id
                    WHERE relation.product_id = ? AND category.publication_status = ?
                    ORDER BY category.name ASC
                    SQL,
                [$productId, PublicationStatus::Published->value],
            ),
        );
        [$basePrice, $sellPrice, $onSale] = $this->prices($row);
        $quantity = (int) $row['quantity'];
        $sellable = (bool) $row['available_for_sale'] && $quantity > 0;

        return new CatalogProductDetail(
            id: $productId,
            sku: (string) $row['sku'],
            name: (string) $row['name'],
            slug: (string) $row['slug'],
            description: null === $row['description'] ? null : (string) $row['description'],
            brandName: null === $row['brand_name'] ? null : (string) $row['brand_name'],
            brandSlug: null === $row['brand_slug'] ? null : (string) $row['brand_slug'],
            images: $images,
            identifiers: $identifiers,
            attributes: $attributes,
            categories: $categories,
            basePrice: $basePrice,
            sellPrice: $sellPrice,
            onSale: $onSale,
            quantity: $quantity,
            sellable: $sellable,
        );
    }

    /**
     * Resolves price, stock and the lead image for a known set of products in one query.
     *
     * A cart, a wishlist and a comparison all hold a handful of products and all render a
     * price, a stock badge and a thumbnail for each. Reading those through the price, inventory
     * and image repositories one product at a time costs four queries per line, which is the
     * difference between a basket page costing five queries and costing four times its size.
     *
     * Unlike {@see findPublishedProductViews()} this does not filter on publication state: a
     * saved or abandoned product has to keep rendering something legible, and the callers
     * decide separately what an unpublished product means for them.
     *
     * @param list<int> $productIds
     *
     * @return array<int, CatalogProductView> Keyed by product id
     */
    public function productSnapshots(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(intval(...), $productIds))));
        if ([] === $ids) {
            return [];
        }

        $rows = $this->connection->createQueryBuilder()
            ->select(
                'product.id',
                'product.sku',
                'product.name',
                'product.slug',
                '(SELECT image.path FROM catalog_product_image image WHERE image.product_id = product.id ORDER BY image.sort_order ASC, image.id ASC LIMIT 1) AS image_path',
                '(SELECT image.alt_text FROM catalog_product_image image WHERE image.product_id = product.id ORDER BY image.sort_order ASC, image.id ASC LIMIT 1) AS image_alt',
                'price.base_minor_amount',
                'price.sale_minor_amount',
                'price.currency',
                'price.sale_starts_at',
                'price.sale_ends_at',
                'COALESCE(inventory.quantity, 0) AS quantity',
                'COALESCE(inventory.available_for_sale, 0) AS available_for_sale',
            )
            ->from('catalog_product', 'product')
            ->leftJoin('product', 'commerce_product_price', 'price', 'price.product_id = product.id')
            ->leftJoin('product', 'commerce_product_inventory', 'inventory', 'inventory.product_id = product.id')
            ->where('product.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();

        $snapshots = [];
        foreach ($rows as $row) {
            $snapshots[(int) $row['id']] = $this->productView([
                ...$row,
                'brand_name' => null,
                'brand_slug' => null,
            ]);
        }

        return $snapshots;
    }

    /**
     * Resolves a named set of categories or brands in one query.
     *
     * A homepage section picks its entries by slug. Reading them out of the full published list
     * meant loading every category and every brand — each row carrying a correlated product
     * count — merely to pick twenty of them, once per section.
     *
     * @param list<string> $slugs
     *
     * @return list<CatalogOption>
     */
    public function optionsBySlug(string $kind, array $slugs): array
    {
        $normalized = array_values(array_unique(array_map(
            static fn (string $slug): string => mb_strtolower(trim($slug)),
            $slugs,
        )));
        if ([] === $normalized) {
            return [];
        }

        $isCategory = 'category' === $kind;
        $rows = $this->optionQuery(
            $isCategory ? 'catalog_category' : 'catalog_brand',
            $kind,
            $isCategory ? null : 'product.brand_id = option_record.id',
        )
            ->andWhere('option_record.slug IN (:slugs)')
            ->setParameter('slugs', $normalized, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[(string) $row['slug']] = new CatalogOption(
                (string) $row['name'],
                (string) $row['slug'],
                (int) $row['product_count'],
                (int) $row['id'],
            );
        }

        $options = [];
        foreach ($normalized as $slug) {
            if (isset($bySlug[$slug])) {
                $options[] = $bySlug[$slug];
            }
        }

        return $options;
    }

    /** @return list<CatalogOption> */
    public function publishedCategories(?int $limit = null): array
    {
        return $this->options('catalog_category', 'category', null, $limit);
    }

    /**
     * Resolves the lightweight product views that carousels and tabs need in one pass, so a
     * homepage section with twenty products does not fire twenty separate product detail queries.
     *
     * @param list<string> $slugs
     *
     * @return list<CatalogProductView>
     */
    public function findPublishedProductViews(array $slugs): array
    {
        $normalized = array_map(
            static fn (string $slug): string => mb_strtolower(trim($slug)),
            $slugs,
        );
        $unique = array_values(array_unique($normalized));
        if ([] === $unique) {
            return [];
        }

        $rows = $this->connection->createQueryBuilder()
            ->select(
                'product.id',
                'product.sku',
                'product.name',
                'product.slug',
                'brand.name AS brand_name',
                'brand.slug AS brand_slug',
                '(SELECT image.path FROM catalog_product_image image WHERE image.product_id = product.id ORDER BY image.sort_order ASC, image.id ASC LIMIT 1) AS image_path',
                '(SELECT image.alt_text FROM catalog_product_image image WHERE image.product_id = product.id ORDER BY image.sort_order ASC, image.id ASC LIMIT 1) AS image_alt',
                'price.base_minor_amount',
                'price.sale_minor_amount',
                'price.currency',
                'price.sale_starts_at',
                'price.sale_ends_at',
                'COALESCE(inventory.quantity, 0) AS quantity',
                'COALESCE(inventory.available_for_sale, 0) AS available_for_sale',
            )
            ->from('catalog_product', 'product')
            ->leftJoin(
                'product',
                'catalog_brand',
                'brand',
                'brand.id = product.brand_id AND brand.publication_status = :published',
            )
            ->leftJoin('product', 'commerce_product_price', 'price', 'price.product_id = product.id')
            ->leftJoin('product', 'commerce_product_inventory', 'inventory', 'inventory.product_id = product.id')
            ->where('product.slug IN (:slugs)')
            ->andWhere('product.publication_status = :published')
            ->setParameter('published', PublicationStatus::Published->value)
            ->setParameter('slugs', $unique, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[(string) $row['slug']] = $this->productView($row);
        }

        $views = [];
        foreach ($unique as $slug) {
            if (isset($bySlug[$slug])) {
                $views[] = $bySlug[$slug];
            }
        }

        return $views;
    }

    /**
     * Categories ordered by how many published products they hold, largest first, and capped.
     *
     * The catalogue sidebar is a filter, and a filter is more useful when it offers the biggest
     * buckets first. The cap is what keeps the page's cost independent of how many categories
     * the catalogue has grown; the full set lives on the paged index instead.
     *
     * @return list<CatalogOption>
     */
    public function categoriesByPopularity(int $limit): array
    {
        return $this->popularOptions('catalog_category', 'category', null, $limit);
    }

    /** @return list<CatalogOption> */
    public function brandsByPopularity(int $limit): array
    {
        return $this->popularOptions('catalog_brand', 'brand', 'product.brand_id = option_record.id', $limit);
    }

    public function brandPage(int $page, int $perPage): PagedResult
    {
        return $this->optionPage('catalog_brand', 'brand', 'product.brand_id = option_record.id', $page, $perPage);
    }

    public function categoryPage(int $page, int $perPage): PagedResult
    {
        return $this->optionPage('catalog_category', 'category', null, $page, $perPage);
    }

    /**
     * @return list<CatalogOption>
     */
    private function popularOptions(string $table, string $kind, ?string $brandRelation, int $limit): array
    {
        $rows = $this->optionQuery($table, $kind, $brandRelation)
            ->having('product_count > 0')
            ->orderBy('product_count', 'DESC')
            ->addOrderBy('option_record.name', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): CatalogOption => new CatalogOption(
                (string) $row['name'],
                (string) $row['slug'],
                (int) $row['product_count'],
                (int) $row['id'],
            ),
            $rows,
        );
    }

    private function optionPage(string $table, string $kind, ?string $brandRelation, int $page, int $perPage): PagedResult
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);

        $count = (int) $this->optionQuery($table, $kind, $brandRelation)
            ->select('COUNT(option_record.id)')
            ->executeQuery()
            ->fetchOne();

        $rows = $this->optionQuery($table, $kind, $brandRelation)
            ->orderBy('option_record.name', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->executeQuery()
            ->fetchAllAssociative();

        return new PagedResult(
            array_map(
                static fn (array $row): CatalogOption => new CatalogOption(
                    (string) $row['name'],
                    (string) $row['slug'],
                    (int) $row['product_count'],
                    (int) $row['id'],
                ),
                $rows,
            ),
            $page,
            $perPage,
            $count,
        );
    }

    /** @return list<CatalogOption> */
    public function publishedBrands(?int $limit = null): array
    {
        return $this->options('catalog_brand', 'brand', 'product.brand_id = option_record.id', $limit);
    }

    public function findPublishedCategory(string $slug): ?CatalogOption
    {
        return $this->findOption('catalog_category', 'category', null, $slug);
    }

    public function findPublishedBrand(string $slug): ?CatalogOption
    {
        return $this->findOption('catalog_brand', 'brand', 'product.brand_id = option_record.id', $slug);
    }

    private function filteredProducts(CatalogCriteria $criteria): QueryBuilder
    {
        $query = $this->connection->createQueryBuilder()
            ->from('catalog_product', 'product')
            ->where('product.publication_status = :published')
            ->setParameter('published', PublicationStatus::Published->value);

        if (null !== $criteria->query) {
            $searchPrefix = $this->escapeLike($criteria->query).'%';
            $searchUpperPrefix = $this->escapeLike(mb_strtoupper($criteria->query)).'%';
            $query
                ->innerJoin(
                    'product',
                    <<<'SQL'
                        (
                            SELECT named_product.id AS product_id
                            FROM catalog_product named_product
                            WHERE named_product.publication_status = :published
                              AND named_product.name LIKE :search_prefix ESCAPE '!'
                            UNION
                            SELECT sku_product.id
                            FROM catalog_product sku_product
                            WHERE sku_product.sku LIKE :search_upper_prefix ESCAPE '!'
                            UNION
                            SELECT identifier.product_id
                            FROM catalog_product_identifier identifier
                            WHERE identifier.identifier_type IN (:manufacturer_type, :oem_type, :reference_type)
                              AND identifier.code LIKE :search_upper_prefix ESCAPE '!'
                        )
                        SQL,
                    'search_match',
                    'search_match.product_id = product.id',
                    )
                ->setParameter('search_prefix', $searchPrefix)
                ->setParameter('search_upper_prefix', $searchUpperPrefix)
                ->setParameter('manufacturer_type', ProductIdentifierType::Manufacturer->value)
                ->setParameter('oem_type', ProductIdentifierType::Oem->value)
                ->setParameter('reference_type', ProductIdentifierType::Reference->value);
        }

        if (null !== $criteria->categorySlug) {
            $query
                ->andWhere(<<<'SQL'
                    EXISTS (
                        SELECT 1
                        FROM catalog_product_category category_relation
                        INNER JOIN catalog_category filter_category ON filter_category.id = category_relation.category_id
                        WHERE category_relation.product_id = product.id
                          AND filter_category.slug = :category_slug
                          AND filter_category.publication_status = :published
                    )
                    SQL)
                ->setParameter('category_slug', $criteria->categorySlug);
        }

        if (null !== $criteria->brandSlug) {
            $query
                ->andWhere(<<<'SQL'
                    EXISTS (
                        SELECT 1
                        FROM catalog_brand filter_brand
                        WHERE filter_brand.id = product.brand_id
                          AND filter_brand.slug = :brand_slug
                          AND filter_brand.publication_status = :published
                    )
                    SQL)
                ->setParameter('brand_slug', $criteria->brandSlug);
        }

        if ($criteria->inStockOnly) {
            $query->andWhere(<<<'SQL'
                EXISTS (
                    SELECT 1
                    FROM commerce_product_inventory filter_inventory
                    WHERE filter_inventory.product_id = product.id
                      AND filter_inventory.available_for_sale = 1
                      AND filter_inventory.quantity > 0
                )
                SQL);
        }

        if ($criteria->onSaleOnly) {
            // The collections page, and it states the same question the pricing module does: a sale
            // price that exists, that has already started and that has not ended. Written as the
            // same three conditions {@see self::saleIsActive()} evaluates in PHP, against the same
            // database clock the sort's effective price uses, so a product shown here is one whose
            // card actually prints the struck-through original beside the price.
            $query
                ->andWhere(<<<'SQL'
                    EXISTS (
                        SELECT 1
                        FROM commerce_product_price sale_price
                        WHERE sale_price.product_id = product.id
                          AND sale_price.sale_minor_amount IS NOT NULL
                          AND (sale_price.sale_starts_at IS NULL OR sale_price.sale_starts_at <= :now)
                          AND (sale_price.sale_ends_at IS NULL OR :now < sale_price.sale_ends_at)
                    )
                    SQL)
                ->setParameter('now', $this->databaseTime($this->clock->now()));
        }

        return $query;
    }

    /** @param array<string, mixed> $row */
    private function productView(array $row): CatalogProductView
    {
        [$basePrice, $sellPrice, $onSale] = $this->prices($row);
        $quantity = (int) $row['quantity'];

        return new CatalogProductView(
            id: (int) $row['id'],
            sku: (string) $row['sku'],
            name: (string) $row['name'],
            slug: (string) $row['slug'],
            brandName: null === $row['brand_name'] ? null : (string) $row['brand_name'],
            brandSlug: null === $row['brand_slug'] ? null : (string) $row['brand_slug'],
            imagePath: null === $row['image_path'] ? null : (string) $row['image_path'],
            imageAlt: null === $row['image_alt'] ? null : (string) $row['image_alt'],
            basePrice: $basePrice,
            sellPrice: $sellPrice,
            onSale: $onSale,
            quantity: $quantity,
            sellable: (bool) $row['available_for_sale'] && $quantity > 0,
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{Money|null, Money|null, bool}
     */
    private function prices(array $row): array
    {
        if (null === $row['base_minor_amount'] || null === $row['currency']) {
            return [null, null, false];
        }

        $basePrice = Money::ofMinor((int) $row['base_minor_amount'], (string) $row['currency']);
        $onSale = null !== $row['sale_minor_amount']
            && $this->saleIsActive($row['sale_starts_at'], $row['sale_ends_at']);
        $sellPrice = $onSale
            ? Money::ofMinor((int) $row['sale_minor_amount'], (string) $row['currency'])
            : $basePrice;

        return [$basePrice, $sellPrice, $onSale];
    }

    private function saleIsActive(mixed $startsAt, mixed $endsAt): bool
    {
        $now = $this->clock->now();

        return (null === $startsAt || new \DateTimeImmutable((string) $startsAt, new \DateTimeZone('UTC')) <= $now)
            && (null === $endsAt || $now < new \DateTimeImmutable((string) $endsAt, new \DateTimeZone('UTC')));
    }

    private function effectivePriceExpression(): string
    {
        return <<<'SQL'
            CASE
                WHEN price.sale_minor_amount IS NOT NULL
                  AND (price.sale_starts_at IS NULL OR price.sale_starts_at <= :now)
                  AND (price.sale_ends_at IS NULL OR :now < price.sale_ends_at)
                THEN price.sale_minor_amount
                ELSE price.base_minor_amount
            END
            SQL;
    }

    /** @return list<CatalogOption> */
    private function options(string $table, string $kind, ?string $brandRelation, ?int $limit): array
    {
        $query = $this->optionQuery($table, $kind, $brandRelation)
            ->orderBy('option_record.name', 'ASC');
        if (null !== $limit) {
            $query->setMaxResults(max(1, $limit));
        }

        return array_map(
            static fn (array $row): CatalogOption => new CatalogOption(
                (string) $row['name'],
                (string) $row['slug'],
                (int) $row['product_count'],
                (int) $row['id'],
            ),
            $query->executeQuery()->fetchAllAssociative(),
        );
    }

    private function findOption(string $table, string $kind, ?string $brandRelation, string $slug): ?CatalogOption
    {
        $row = $this->optionQuery($table, $kind, $brandRelation)
            ->andWhere('option_record.slug = :slug')
            ->setParameter('slug', mb_strtolower(trim($slug)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return new CatalogOption(
            (string) $row['name'],
            (string) $row['slug'],
            (int) $row['product_count'],
            (int) $row['id'],
        );
    }

    private function optionQuery(string $table, string $kind, ?string $brandRelation): QueryBuilder
    {
        $relation = 'category' === $kind
            ? 'INNER JOIN catalog_product_category option_relation ON option_relation.product_id = product.id AND option_relation.category_id = option_record.id'
            : '';
        $productCondition = $brandRelation ?? '1 = 1';

        return $this->connection->createQueryBuilder()
            ->select(
                'option_record.name',
                'option_record.id',
                'option_record.slug',
                sprintf(
                    '(SELECT COUNT(DISTINCT product.id) FROM catalog_product product %s WHERE %s AND product.publication_status = :published) AS product_count',
                    $relation,
                    $productCondition,
                ),
            )
            ->from($table, 'option_record')
            ->where('option_record.publication_status = :published')
            ->setParameter('published', PublicationStatus::Published->value);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function databaseTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
