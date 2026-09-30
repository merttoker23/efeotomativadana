<?php

namespace App\Module\Cart;

use App\Entity\Catalog\Product;
use App\Module\Catalog\PublicationStatus;
use App\Module\Catalog\Query\CatalogQuery;
use App\Module\Inventory\InventoryQuery;
use App\Module\Pricing\PricingQuery;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

final class CartManager implements ResetInterface
{
    private ?CartView $cachedView = null;
    private ?CartSummary $cachedSummary = null;

    public function __construct(
        private readonly CartOwnerResolver $owner,
        private readonly CartRepositoryInterface $carts,
        private readonly PricingQuery $pricing,
        private readonly InventoryQuery $inventory,
        private readonly CatalogQuery $catalog,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function add(Product $product, mixed $quantity): void
    {
        $quantity = $this->quantity($quantity);
        if (PublicationStatus::Published !== $product->publicationStatus()) {
            throw new CartViolation('Bu ürün satışa açık değil.');
        }
        if (null === $this->pricing->forProduct($product)) {
            throw new CartViolation('Bu ürün için güncel fiyat bulunamadı.');
        }

        $cart = $this->owner->getOrCreate();
        $existing = $cart->itemFor($product)?->quantity() ?? 0;
        $target = $existing + $quantity;
        if ($target > 99) {
            throw new CartViolation('Bir üründen sepette en fazla 99 adet bulunabilir.');
        }
        $inventory = $this->inventory->forProduct($product);
        if (!$inventory->sellable() || $target > $inventory->quantity()) {
            throw new CartViolation(sprintf('En fazla %d adet ekleyebilirsiniz.', $inventory->quantity()));
        }

        $cart->add($product, $quantity);
        $this->entityManager->flush();
        $this->invalidate();
    }

    public function update(int $itemId, mixed $quantity): void
    {
        $quantity = $this->quantity($quantity);
        $cart = $this->owner->current();
        $item = $cart?->itemById($itemId);
        if (null === $item) {
            throw new CartViolation('Sepet satırı bulunamadı.');
        }

        $product = $item->product();
        $inventory = $this->inventory->forProduct($product);
        if (
            PublicationStatus::Published !== $product->publicationStatus()
            || null === $this->pricing->forProduct($product)
            || !$inventory->sellable()
            || $quantity > $inventory->quantity()
        ) {
            throw new CartViolation(sprintf('En fazla %d adet seçebilirsiniz.', $inventory->quantity()));
        }

        $cart->changeQuantity($item, $quantity);
        $this->entityManager->flush();
        $this->invalidate();
    }

    public function remove(int $itemId): void
    {
        $cart = $this->owner->current();
        $item = $cart?->itemById($itemId);
        if (null === $cart || null === $item) {
            throw new CartViolation('Sepet satırı bulunamadı.');
        }

        $cart->remove($item);
        $this->entityManager->flush();
        $this->invalidate();
    }

    public function summary(): CartSummary
    {
        if (null !== $this->cachedSummary) {
            return $this->cachedSummary;
        }

        $cart = $this->owner->current();

        return $this->cachedSummary = null === $cart
            ? new CartSummary(0, null)
            : $this->carts->summary($cart, $this->clock->now());
    }

    public function view(): CartView
    {
        if (null !== $this->cachedView) {
            return $this->cachedView;
        }

        $cart = $this->owner->current();
        $total = null;
        if (null === $cart) {
            return $this->cachedView = new CartView([], 0, $total);
        }

        // One batched read for the whole basket instead of a price, a stock row and an image
        // collection per line. A ten-line basket used to cost around forty queries here, on the
        // page a customer reaches most often and on the one every storefront header renders a
        // summary of.
        $items = $cart->items();
        $snapshots = $this->catalog->snapshots(array_values(array_filter(array_map(
            static fn ($item): ?int => $item->product()->id(),
            $items,
        ), is_int(...))));

        $lines = [];
        $itemCount = 0;
        $hasUnavailableLine = false;
        foreach ($items as $item) {
            $product = $item->product();
            $snapshot = $snapshots[$product->id() ?? 0] ?? null;
            $unitPrice = $snapshot?->sellPrice;
            $quantity = $snapshot->quantity;
            $lineTotal = $unitPrice?->multiply($item->quantity());
            $updatable = null !== $unitPrice
                && PublicationStatus::Published === $product->publicationStatus()
                && $snapshot->sellable;
            $sellable = $updatable && $item->quantity() <= $quantity;
            if ($sellable && null !== $lineTotal) {
                $total ??= \App\Shared\Money\Money::ofMinor(0, $unitPrice->currency());
                try {
                    $total = $total->add($lineTotal);
                } catch (\InvalidArgumentException) {
                    // Two products priced in different currencies cannot be added, and the store's
                    // own currency is not checked per product. Surfacing this as an unavailable
                    // line keeps the basket page answering, which is what the other two readers
                    // of a mixed-currency cart already do; adding it here would have turned a
                    // pricing mistake into a 500 for the customer.
                    $hasUnavailableLine = true;
                    $total = null;
                }
            } else {
                $hasUnavailableLine = true;
            }
            $lines[] = new CartLineView(
                id: $item->id() ?? 0,
                productId: $product->id() ?? 0,
                name: $product->name(),
                slug: $product->slug(),
                sku: $product->sku(),
                imagePath: $snapshot?->imagePath,
                quantity: $item->quantity(),
                availableQuantity: min(99, $quantity),
                unitPrice: $unitPrice,
                lineTotal: $lineTotal,
                updatable: $updatable,
                sellable: $sellable,
            );
            $itemCount += $item->quantity();
        }

        return $this->cachedView = new CartView($lines, $itemCount, $hasUnavailableLine ? null : $total);
    }

    private function quantity(mixed $quantity): int
    {
        $quantity = filter_var($quantity, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99]]);
        if (false === $quantity) {
            throw new CartViolation('Miktar 1 ile 99 arasında olmalıdır.');
        }

        return $quantity;
    }

    public function reset(): void
    {
        $this->invalidate();
    }

    private function invalidate(): void
    {
        $this->cachedView = null;
        $this->cachedSummary = null;
    }
}
