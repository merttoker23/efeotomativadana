<?php

namespace App\Module\Cart;

use App\Entity\Catalog\Product;
use App\Module\Catalog\PublicationStatus;
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

        $lines = [];
        $itemCount = 0;
        $hasUnavailableLine = false;
        foreach ($cart->items() as $item) {
            $product = $item->product();
            $price = $this->pricing->forProduct($product);
            $inventory = $this->inventory->forProduct($product);
            $unitPrice = $price?->sellPrice();
            $lineTotal = $unitPrice?->multiply($item->quantity());
            $updatable = null !== $unitPrice
                && PublicationStatus::Published === $product->publicationStatus()
                && $inventory->sellable();
            $sellable = $updatable && $item->quantity() <= $inventory->quantity();
            if ($sellable && null !== $lineTotal) {
                $total ??= \App\Shared\Money\Money::ofMinor(0, $unitPrice->currency());
                $total = $total->add($lineTotal);
            } else {
                $hasUnavailableLine = true;
            }
            $images = $product->images();
            $lines[] = new CartLineView(
                id: $item->id() ?? 0,
                productId: $product->id() ?? 0,
                name: $product->name(),
                slug: $product->slug(),
                sku: $product->sku(),
                imagePath: [] === $images ? null : $images[0]->path(),
                quantity: $item->quantity(),
                availableQuantity: min(99, $inventory->quantity()),
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
