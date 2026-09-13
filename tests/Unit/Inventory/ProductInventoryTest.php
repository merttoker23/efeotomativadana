<?php

namespace App\Tests\Unit\Inventory;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;
use App\Module\Inventory\Exception\InsufficientStock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductInventoryTest extends TestCase
{
    public function testZeroStockIsNotEffectivelySellableByDefault(): void
    {
        $product = self::product();
        $inventory = new ProductInventory($product);

        self::assertSame($product, $inventory->product());
        self::assertSame(0, $inventory->quantity());
        self::assertTrue($inventory->availableForSale());
        self::assertFalse($inventory->isSellable());
        self::assertInstanceOf(\DateTimeImmutable::class, $inventory->createdAt());
        self::assertSame($inventory->createdAt(), $inventory->updatedAt());
    }

    public function testPositiveAvailableStockIsEffectivelySellable(): void
    {
        $inventory = new ProductInventory(self::product(), 4);

        self::assertSame(4, $inventory->quantity());
        self::assertTrue($inventory->availableForSale());
        self::assertTrue($inventory->isSellable());
    }

    public function testManualDisablingMakesPositiveStockUnsellable(): void
    {
        $inventory = new ProductInventory(self::product(), 4, false);

        self::assertSame(4, $inventory->quantity());
        self::assertFalse($inventory->availableForSale());
        self::assertFalse($inventory->isSellable());
    }

    public function testItReplacesQuantityAndAvailabilityTogether(): void
    {
        $inventory = new ProductInventory(self::product(), 4, false);

        $inventory->replace(9, true);

        self::assertSame(9, $inventory->quantity());
        self::assertTrue($inventory->availableForSale());
        self::assertTrue($inventory->isSellable());
    }

    public function testItCanIncrementDecrementAndApplyAZeroAdjustment(): void
    {
        $inventory = new ProductInventory(self::product(), 5);

        $inventory->adjust(3);
        self::assertSame(8, $inventory->quantity());

        $inventory->adjust(-6);
        self::assertSame(2, $inventory->quantity());

        $inventory->adjust(0);
        self::assertSame(2, $inventory->quantity());
    }

    public function testItRejectsAnAdjustmentThatWouldMakeStockNegativeWithoutMutation(): void
    {
        $inventory = new ProductInventory(self::product(), 2);

        try {
            $inventory->adjust(-3);
            self::fail('Expected insufficient stock to be rejected.');
        } catch (InsufficientStock) {
            self::assertSame(2, $inventory->quantity());
        }
    }

    public function testItRejectsIntegerOverflowWithoutMutation(): void
    {
        $inventory = new ProductInventory(self::product(), PHP_INT_MAX);

        try {
            $inventory->adjust(1);
            self::fail('Expected integer overflow to be rejected.');
        } catch (\OverflowException) {
            self::assertSame(PHP_INT_MAX, $inventory->quantity());
        }
    }

    #[DataProvider('invalidQuantities')]
    public function testItRejectsNonIntegerOrNegativeQuantities(mixed $quantity): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProductInventory(self::product(), $quantity);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidQuantities(): iterable
    {
        yield 'negative integer' => [-1];
        yield 'float' => [1.0];
        yield 'boolean' => [true];
        yield 'numeric string' => ['1'];
    }

    #[DataProvider('invalidDeltas')]
    public function testItRejectsWeaklyCoercibleAdjustmentDeltas(mixed $delta): void
    {
        $inventory = new ProductInventory(self::product(), 5);

        try {
            $inventory->adjust($delta);
            self::fail('Expected a non-integer delta to be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame(5, $inventory->quantity());
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDeltas(): iterable
    {
        yield 'float' => [1.0];
        yield 'boolean' => [true];
        yield 'numeric string' => ['1'];
    }

    public function testReplacementValidatesEveryInputBeforeMutatingState(): void
    {
        $inventory = new ProductInventory(self::product(), 7, false);

        try {
            $inventory->replace(3, 1);
            self::fail('Expected a non-boolean availability flag to be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame(7, $inventory->quantity());
            self::assertFalse($inventory->availableForSale());
        }
    }

    private static function product(): Product
    {
        return new Product('BAL-001', 'Ön Fren Balatası', 'on-fren-balatasi');
    }
}
