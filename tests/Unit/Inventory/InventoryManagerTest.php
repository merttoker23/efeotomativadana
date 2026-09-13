<?php

namespace App\Tests\Unit\Inventory;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;
use App\Module\Inventory\Exception\InsufficientStock;
use App\Module\Inventory\Exception\InventoryNotFound;
use App\Module\Inventory\InventoryManager;
use App\Module\Inventory\ProductInventoryRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class InventoryManagerTest extends TestCase
{
    public function testUpsertCreatesPersistsAndFlushesInventory(): void
    {
        $repository = new RecordingProductInventoryRepository();
        $entityManager = $this->entityManager(flushes: 1);
        $product = self::product();

        $inventory = (new InventoryManager($repository, $entityManager))->upsert($product, 8, false);

        self::assertSame($product, $inventory->product());
        self::assertSame(8, $inventory->quantity());
        self::assertFalse($inventory->availableForSale());
        self::assertSame($inventory, $repository->current());
        self::assertSame(1, $repository->saves());
    }

    public function testUpsertReplacesAnExistingInventoryRecord(): void
    {
        $product = self::product();
        $existing = new ProductInventory($product, 3, false);
        $repository = new RecordingProductInventoryRepository($existing);
        $entityManager = $this->entityManager(flushes: 1);

        $inventory = (new InventoryManager($repository, $entityManager))->upsert($product, 11, true);

        self::assertSame($existing, $inventory);
        self::assertSame(11, $inventory->quantity());
        self::assertTrue($inventory->availableForSale());
        self::assertSame(1, $repository->ordinaryFinds());
        self::assertSame(1, $repository->saves());
    }

    public function testUpsertValidatesADetachedCandidateBeforeLookingUpOrMutatingExistingInventory(): void
    {
        $product = self::product();
        $existing = new ProductInventory($product, 7, false);
        $repository = new RecordingProductInventoryRepository($existing);
        $entityManager = $this->entityManager();

        try {
            (new InventoryManager($repository, $entityManager))->upsert($product, 3, 1);
            self::fail('Expected invalid replacement input to be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame(7, $existing->quantity());
            self::assertFalse($existing->availableForSale());
            self::assertSame(0, $repository->ordinaryFinds());
            self::assertSame(0, $repository->saves());
        }
    }

    public function testPositiveAdjustmentUsesTheLockedRecordPersistsAndFlushesInsideATransaction(): void
    {
        $product = self::product();
        $existing = new ProductInventory($product, 5);
        $repository = new RecordingProductInventoryRepository($existing);
        $entityManager = $this->entityManager(transactions: 1, flushes: 1);

        $inventory = (new InventoryManager($repository, $entityManager))->adjust($product, 4);

        self::assertSame($existing, $inventory);
        self::assertSame(9, $inventory->quantity());
        self::assertSame(0, $repository->ordinaryFinds());
        self::assertSame(1, $repository->lockedFinds());
        self::assertSame(1, $repository->saves());
    }

    public function testNegativeAdjustmentUsesTheLockedRecordAndCanReachZero(): void
    {
        $product = self::product();
        $existing = new ProductInventory($product, 5);
        $repository = new RecordingProductInventoryRepository($existing);
        $entityManager = $this->entityManager(transactions: 1, flushes: 1);

        $inventory = (new InventoryManager($repository, $entityManager))->adjust($product, -5);

        self::assertSame(0, $inventory->quantity());
        self::assertFalse($inventory->isSellable());
        self::assertSame(1, $repository->lockedFinds());
        self::assertSame(1, $repository->saves());
    }

    public function testAdjustmentThrowsWhenLockedInventoryDoesNotExist(): void
    {
        $repository = new RecordingProductInventoryRepository();
        $entityManager = $this->entityManager(transactions: 1);

        try {
            (new InventoryManager($repository, $entityManager))->adjust(self::product(), 1);
            self::fail('Expected missing inventory to be rejected.');
        } catch (InventoryNotFound) {
            self::assertSame(1, $repository->lockedFinds());
            self::assertSame(0, $repository->saves());
        }
    }

    public function testInsufficientStockLeavesTheLockedRecordUntouchedAndDoesNotPersist(): void
    {
        $product = self::product();
        $existing = new ProductInventory($product, 2);
        $repository = new RecordingProductInventoryRepository($existing);
        $entityManager = $this->entityManager(transactions: 1);

        try {
            (new InventoryManager($repository, $entityManager))->adjust($product, -3);
            self::fail('Expected insufficient stock to be rejected.');
        } catch (InsufficientStock) {
            self::assertSame(2, $existing->quantity());
            self::assertSame(1, $repository->lockedFinds());
            self::assertSame(0, $repository->saves());
        }
    }

    /** @return EntityManagerInterface&MockObject */
    private function entityManager(int $transactions = 0, int $flushes = 0): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::exactly($transactions))
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback($entityManager));
        $entityManager->expects(self::exactly($flushes))->method('flush');

        return $entityManager;
    }

    private static function product(): Product
    {
        return new Product('BAL-001', 'Ön Fren Balatası', 'on-fren-balatasi');
    }
}

final class RecordingProductInventoryRepository implements ProductInventoryRepositoryInterface
{
    private int $ordinaryFinds = 0;

    private int $lockedFinds = 0;

    private int $saves = 0;

    public function __construct(private ?ProductInventory $inventory = null)
    {
    }

    public function findOneByProduct(Product $product): ?ProductInventory
    {
        ++$this->ordinaryFinds;

        return $this->matches($product);
    }

    public function findOneByProductForUpdate(Product $product): ?ProductInventory
    {
        ++$this->lockedFinds;

        return $this->matches($product);
    }

    public function save(ProductInventory $inventory): void
    {
        ++$this->saves;
        $this->inventory = $inventory;
    }

    public function current(): ?ProductInventory
    {
        return $this->inventory;
    }

    public function ordinaryFinds(): int
    {
        return $this->ordinaryFinds;
    }

    public function lockedFinds(): int
    {
        return $this->lockedFinds;
    }

    public function saves(): int
    {
        return $this->saves;
    }

    private function matches(Product $product): ?ProductInventory
    {
        return null !== $this->inventory && $this->inventory->product() === $product ? $this->inventory : null;
    }
}
