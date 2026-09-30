<?php

namespace App\Module\Cart;

use App\Entity\Catalog\Product;
use App\Module\Catalog\PublicationStatus;
use App\Module\Catalog\Query\CatalogQuery;
use App\Repository\Catalog\ProductRepository;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class ComparisonManager
{
    public const MAX_ITEMS = 4;
    private const SESSION_KEY = 'storefront_comparison_product_ids';

    public function __construct(
        private RequestStack $requests,
        private ProductRepository $products,
        private CatalogQuery $catalog,
    ) {
    }

    public function add(Product $product): void
    {
        if (PublicationStatus::Published !== $product->publicationStatus() || null === $product->id()) {
            throw new CartViolation('Bu ürün karşılaştırmaya eklenemez.');
        }

        $ids = $this->ids();
        if (in_array($product->id(), $ids, true)) {
            return;
        }
        if (count($ids) >= self::MAX_ITEMS) {
            throw new CartViolation(sprintf('En fazla %d ürün karşılaştırabilirsiniz.', self::MAX_ITEMS));
        }

        $ids[] = $product->id();
        $this->requests->getSession()->set(self::SESSION_KEY, $ids);
    }

    public function remove(int $productId): void
    {
        $ids = array_values(array_filter($this->ids(), static fn (int $id): bool => $id !== $productId));
        $this->requests->getSession()->set(self::SESSION_KEY, $ids);
    }

    public function view(): ComparisonView
    {
        $views = [];
        $rows = [];
        $validIds = [];

        $ids = $this->ids();
        $byId = [];
        foreach ($ids === [] ? [] : $this->products->findBy(['id' => $ids]) as $product) {
            $byId[$product->id() ?? 0] = $product;
        }

        // One batched read for price, stock and lead image across the whole comparison instead of
        // four queries per compared product.
        $snapshots = $this->catalog->snapshots($ids);

        foreach ($ids as $id) {
            $product = $byId[$id] ?? null;
            if (!$product instanceof Product || PublicationStatus::Published !== $product->publicationStatus()) {
                continue;
            }

            $validIds[] = $id;
            $snapshot = $snapshots[$id] ?? null;
            $attributes = [];
            foreach ($product->attributes() as $attribute) {
                $attributes[$attribute->key()] = $attribute->value();
                $rows[$attribute->key()]['label'] = mb_convert_case(str_replace('-', ' ', $attribute->key()), \MB_CASE_TITLE, 'UTF-8');
                $rows[$attribute->key()]['values'][$id] = $attribute->value();
            }
            $views[] = new SavedProductView(
                selectionId: $id,
                productId: $id,
                name: $product->name(),
                slug: $product->slug(),
                sku: $product->sku(),
                imagePath: $snapshot?->imagePath,
                price: $snapshot?->sellPrice,
                sellable: true === $snapshot?->sellable,
                attributes: $attributes,
            );
        }

        if ($validIds !== $this->ids()) {
            $this->requests->getSession()->set(self::SESSION_KEY, $validIds);
        }
        ksort($rows, \SORT_STRING);
        $rowViews = [];
        foreach ($rows as $key => $row) {
            /** @var array<int, string> $values */
            $values = $row['values'];
            $rowViews[] = new ComparisonRowView($key, $row['label'], $values);
        }

        return new ComparisonView($views, $rowViews);
    }

    /** @return list<int> */
    private function ids(): array
    {
        $ids = $this->requests->getSession()->get(self::SESSION_KEY, []);
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_filter($ids, static fn (mixed $id): bool => is_int($id) && $id > 0));
    }
}
