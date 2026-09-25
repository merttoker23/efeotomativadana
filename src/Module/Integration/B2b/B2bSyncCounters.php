<?php

namespace App\Module\Integration\B2b;

final class B2bSyncCounters
{
    private const KEYS = [
        'scanned',
        'created',
        'updated',
        'skipped',
        'conflicts',
        'categories_created',
        'categories_reused',
        'identifiers_imported',
        'images_imported',
        'images_failed',
        'stock_updated',
        'stock_failed',
        'price_updated',
        'price_failed',
        'daily_new_products',
    ];

    /** @param array<string, int> $values */
    private function __construct(private array $values)
    {
    }

    public static function empty(): self
    {
        return new self(array_fill_keys(self::KEYS, 0));
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        foreach (array_keys($values) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown B2B sync counter "%s".', (string) $key));
            }
            if (!is_int($values[$key]) || $values[$key] < 0) {
                throw new \InvalidArgumentException(sprintf('B2B sync counter "%s" must be a non-negative integer.', $key));
            }
        }

        return new self(array_replace(array_fill_keys(self::KEYS, 0), $values));
    }

    public function merge(self $other): self
    {
        $values = [];
        foreach (self::KEYS as $key) {
            $values[$key] = $this->values[$key] + $other->values[$key];
        }

        return new self($values);
    }

    public function recordScanned(int $quantity = 1): self { return $this->record('scanned', $quantity); }
    public function recordCreated(int $quantity = 1): self { return $this->record('created', $quantity); }
    public function recordUpdated(int $quantity = 1): self { return $this->record('updated', $quantity); }
    public function recordSkipped(int $quantity = 1): self { return $this->record('skipped', $quantity); }
    public function recordConflict(int $quantity = 1): self { return $this->record('conflicts', $quantity); }
    public function recordCategoryCreated(int $quantity = 1): self { return $this->record('categories_created', $quantity); }
    public function recordCategoryReused(int $quantity = 1): self { return $this->record('categories_reused', $quantity); }
    public function recordIdentifiersImported(int $quantity = 1): self { return $this->record('identifiers_imported', $quantity); }
    public function recordImagesImported(int $quantity = 1): self { return $this->record('images_imported', $quantity); }
    public function recordImagesFailed(int $quantity = 1): self { return $this->record('images_failed', $quantity); }
    public function recordStockUpdated(int $quantity = 1): self { return $this->record('stock_updated', $quantity); }
    public function recordStockFailed(int $quantity = 1): self { return $this->record('stock_failed', $quantity); }
    public function recordPriceUpdated(int $quantity = 1): self { return $this->record('price_updated', $quantity); }
    public function recordPriceFailed(int $quantity = 1): self { return $this->record('price_failed', $quantity); }
    public function recordDailyNewProduct(int $quantity = 1): self { return $this->record('daily_new_products', $quantity); }

    public function scanned(): int { return $this->values['scanned']; }
    public function created(): int { return $this->values['created']; }
    public function updated(): int { return $this->values['updated']; }
    public function skipped(): int { return $this->values['skipped']; }
    public function conflicts(): int { return $this->values['conflicts']; }
    public function categoriesCreated(): int { return $this->values['categories_created']; }
    public function categoriesReused(): int { return $this->values['categories_reused']; }
    public function identifiersImported(): int { return $this->values['identifiers_imported']; }
    public function imagesImported(): int { return $this->values['images_imported']; }
    public function imagesFailed(): int { return $this->values['images_failed']; }
    public function stockUpdated(): int { return $this->values['stock_updated']; }
    public function stockFailed(): int { return $this->values['stock_failed']; }
    public function priceUpdated(): int { return $this->values['price_updated']; }
    public function priceFailed(): int { return $this->values['price_failed']; }
    public function dailyNewProducts(): int { return $this->values['daily_new_products']; }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return $this->values;
    }

    private function record(string $key, int $quantity): self
    {
        if (!in_array($key, self::KEYS, true) || $quantity < 0) {
            throw new \InvalidArgumentException('Counter quantity must be non-negative.');
        }
        $values = $this->values;
        $values[$key] += $quantity;

        return new self($values);
    }
}
