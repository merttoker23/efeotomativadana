<?php

namespace App\Tests\Unit\Inventory;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;
use App\Module\Inventory\InventoryQuery;
use App\Module\Inventory\InventoryView;
use App\Module\Inventory\ProductInventoryRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InventoryQueryTest extends TestCase
{
    public function testMissingInventoryReturnsAZeroUnavailableView(): void
    {
        $view = (new InventoryQuery(new InMemoryProductInventoryRepository()))->forProduct(self::product());

        self::assertSame(0, $view->quantity());
        self::assertFalse($view->availableForSale());
        self::assertFalse($view->sellable());
    }

    public function testZeroInventoryPreservesTheAvailabilityFlagButIsNotSellable(): void
    {
        $inventory = new ProductInventory(self::product(), 0, true);

        $view = (new InventoryQuery(new InMemoryProductInventoryRepository($inventory)))->forProduct($inventory->product());

        self::assertSame(0, $view->quantity());
        self::assertTrue($view->availableForSale());
        self::assertFalse($view->sellable());
    }

    public function testDisabledPositiveInventoryIsNotSellable(): void
    {
        $inventory = new ProductInventory(self::product(), 5, false);

        $view = (new InventoryQuery(new InMemoryProductInventoryRepository($inventory)))->forProduct($inventory->product());

        self::assertSame(5, $view->quantity());
        self::assertFalse($view->availableForSale());
        self::assertFalse($view->sellable());
    }

    public function testEnabledPositiveInventoryIsSellable(): void
    {
        $inventory = new ProductInventory(self::product(), 5, true);

        $view = (new InventoryQuery(new InMemoryProductInventoryRepository($inventory)))->forProduct($inventory->product());

        self::assertSame(5, $view->quantity());
        self::assertTrue($view->availableForSale());
        self::assertTrue($view->sellable());
    }

    #[DataProvider('invalidViews')]
    public function testInventoryViewRejectsInconsistentOrWeaklyCoercibleState(
        mixed $quantity,
        mixed $availableForSale,
        mixed $sellable,
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        new InventoryView($quantity, $availableForSale, $sellable);
    }

    /** @return iterable<string, array{mixed, mixed, mixed}> */
    public static function invalidViews(): iterable
    {
        yield 'negative quantity' => [-1, true, false];
        yield 'float quantity' => [1.0, true, true];
        yield 'integer availability' => [1, 1, true];
        yield 'integer sellability' => [1, true, 1];
        yield 'sellable zero stock' => [0, true, true];
        yield 'sellable disabled stock' => [1, false, true];
        yield 'unsellable enabled positive stock' => [1, true, false];
    }

    private static function product(): Product
    {
        return new Product('BAL-001', 'Ön Fren Balatası', 'on-fren-balatasi');
    }
}

final class InMemoryProductInventoryRepository implements ProductInventoryRepositoryInterface
{
    public function __construct(private ?ProductInventory $inventory = null)
    {
    }

    public function findOneByProduct(Product $product): ?ProductInventory
    {
        return null !== $this->inventory && $this->inventory->product() === $product ? $this->inventory : null;
    }

    public function findOneByProductForUpdate(Product $product): ?ProductInventory
    {
        return $this->findOneByProduct($product);
    }

    public function save(ProductInventory $inventory): void
    {
        $this->inventory = $inventory;
    }
}
