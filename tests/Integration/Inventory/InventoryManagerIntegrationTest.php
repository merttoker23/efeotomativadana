<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;
use App\Module\Inventory\Exception\InsufficientStock;
use App\Module\Inventory\InventoryManager;
use App\Module\Inventory\ProductInventoryRepositoryInterface;
use App\Repository\Commerce\ProductInventoryRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InventoryManagerIntegrationTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testUpsertAndLockedAdjustmentsKeepOnePersistedInventoryRow(): void
    {
        $product = $this->persistProduct('INVENTORY-MANAGER-001');
        $repository = $this->entityManager->getRepository(ProductInventory::class);
        self::assertInstanceOf(ProductInventoryRepository::class, $repository);
        self::assertInstanceOf(ProductInventoryRepositoryInterface::class, $repository);
        $manager = new InventoryManager($repository, $this->entityManager);

        $created = $manager->upsert($product, 8, true);
        $createdId = $this->connection->fetchOne(
            'SELECT id FROM commerce_product_inventory WHERE product_id = ?',
            [$product->id()],
        );
        self::assertIsInt($createdId);
        self::assertSame(8, $created->quantity());

        $updated = $manager->upsert($product, 12, false);
        self::assertSame(12, $updated->quantity());
        self::assertFalse($updated->availableForSale());
        self::assertSame($createdId, $this->connection->fetchOne(
            'SELECT id FROM commerce_product_inventory WHERE product_id = ?',
            [$product->id()],
        ));

        self::assertSame(17, $manager->adjust($product, 5)->quantity());
        self::assertSame(14, $manager->adjust($product, -3)->quantity());
        $this->entityManager->clear();

        $reloadedProduct = $this->entityManager->find(Product::class, $product->id());
        self::assertInstanceOf(Product::class, $reloadedProduct);
        $reloaded = $repository->findOneByProduct($reloadedProduct);
        self::assertInstanceOf(ProductInventory::class, $reloaded);
        self::assertSame(1, $repository->count(['product' => $reloadedProduct]));
        self::assertSame(14, $reloaded->quantity());
        self::assertFalse($reloaded->availableForSale());
    }

    public function testAdjustmentBelowZeroDoesNotPersistInvalidStock(): void
    {
        $product = $this->persistProduct('INVENTORY-MANAGER-002');
        $repository = $this->entityManager->getRepository(ProductInventory::class);
        self::assertInstanceOf(ProductInventoryRepository::class, $repository);
        $manager = new InventoryManager($repository, $this->entityManager);
        $manager->upsert($product, 4, true);

        try {
            $manager->adjust($product, -5);
            self::fail('An adjustment below zero must be rejected.');
        } catch (InsufficientStock) {
            self::assertSame(4, $this->connection->fetchOne(
                'SELECT quantity FROM commerce_product_inventory WHERE product_id = ?',
                [$product->id()],
            ));
        }
    }

    public function testAdjustmentRefreshesManagedStockWithALockingSelect(): void
    {
        $product = $this->persistProduct('INVENTORY-MANAGER-STALE');
        $repository = $this->entityManager->getRepository(ProductInventory::class);
        self::assertInstanceOf(ProductInventoryRepository::class, $repository);
        $manager = new InventoryManager($repository, $this->entityManager);
        $managed = $manager->upsert($product, 10, true);

        // Reproduce a database change after an earlier inventory read in this entity manager.
        $this->connection->update('commerce_product_inventory', ['quantity' => 4], ['product_id' => $product->id()]);
        self::assertSame(10, $managed->quantity());

        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();

        $adjusted = $manager->adjust($product, -3);

        self::assertSame(1, $adjusted->quantity());
        self::assertSame(1, $this->connection->fetchOne(
            'SELECT quantity FROM commerce_product_inventory WHERE product_id = ?',
            [$product->id()],
        ));

        $sql = array_column($queries->getData()['default'], 'sql');
        self::assertMatchesRegularExpression(
            '/SELECT\b[^;]*\bFROM\s+commerce_product_inventory\b[^;]*\bFOR\s+UPDATE\b/i',
            implode(';', $sql),
        );
    }

    private function persistProduct(string $sku): Product
    {
        $product = new Product($sku, $sku.' Product', strtolower($sku));
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }
}
