<?php

namespace App\Module\Cart;

use App\Entity\Catalog\Product;
use App\Module\Catalog\PublicationStatus;
use App\Module\Inventory\InventoryQuery;
use App\Module\Pricing\PricingQuery;
use App\Repository\Catalog\ProductRepository;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class ComparisonManager
{
    public const MAX_ITEMS = 4;
    private const SESSION_KEY = 'storefront_comparison_product_ids';

    public function __construct(
        private RequestStack $requests,
        private ProductRepository $products,
        private PricingQuery $pricing,
        private InventoryQuery $inventory,
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
        foreach ($this->ids() as $id) {
            $product = $this->products->find($id);
            if (!$product instanceof Product || PublicationStatus::Published !== $product->publicationStatus()) {
                continue;
            }

            $validIds[] = $id;
            $images = $product->images();
            $attributes = [];
            foreach ($product->attributes() as $attribute) {
                $attributes[$attribute->key()] = $attribute->value();
                $rows[$attribute->key()]['label'] = mb_convert_case(str_replace('-', ' ', $attribute->key()), \MB_CASE_TITLE, 'UTF-8');
                $rows[$attribute->key()]['values'][$id] = $attribute->value();
            }
            $inventory = $this->inventory->forProduct($product);
            $views[] = new SavedProductView(
                selectionId: $id,
                productId: $id,
                name: $product->name(),
                slug: $product->slug(),
                sku: $product->sku(),
                imagePath: [] === $images ? null : $images[0]->path(),
                price: $this->pricing->forProduct($product)?->sellPrice(),
                sellable: $inventory->sellable(),
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
