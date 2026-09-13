<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Repository\Commerce\ProductInventoryRepository;
use App\Repository\Commerce\ProductPriceRepository;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PricingInventoryPersistenceTest extends KernelTestCase
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

    public function testPriceAndInventoryRoundTripThroughDoctrineRepositories(): void
    {
        $product = new Product('PERSIST-001', 'Persistence Product', 'persistence-product');
        $price = new ProductPrice(
            $product,
            Money::ofMinor(259_900, 'TRY'),
            TaxCategory::of('replacement-part'),
            TaxRate::fromBasisPoints(2_000),
        );
        $price->scheduleSale(
            Money::ofMinor(219_900, 'TRY'),
            new \DateTimeImmutable('2026-09-14 09:00:00'),
            new \DateTimeImmutable('2026-09-21 18:00:00'),
        );
        $inventory = new ProductInventory($product, 17, false);

        $this->entityManager->persist($product);
        $this->entityManager->persist($price);
        $this->entityManager->persist($inventory);
        $this->entityManager->flush();
        $productId = $product->id();
        $this->entityManager->clear();

        $reloadedProduct = $this->entityManager->find(Product::class, $productId);
        self::assertInstanceOf(Product::class, $reloadedProduct);

        $prices = $this->entityManager->getRepository(ProductPrice::class);
        $inventories = $this->entityManager->getRepository(ProductInventory::class);
        self::assertInstanceOf(ProductPriceRepository::class, $prices);
        self::assertInstanceOf(ProductInventoryRepository::class, $inventories);

        $reloadedPrice = $prices->findOneByProduct($reloadedProduct);
        $reloadedInventory = $inventories->findOneByProduct($reloadedProduct);
        self::assertInstanceOf(ProductPrice::class, $reloadedPrice);
        self::assertInstanceOf(ProductInventory::class, $reloadedInventory);
        self::assertSame(259_900, $reloadedPrice->basePrice()->minorAmount());
        self::assertSame('TRY', $reloadedPrice->basePrice()->currency());
        self::assertSame(219_900, $reloadedPrice->salePrice()?->minorAmount());
        self::assertSame('replacement-part', $reloadedPrice->taxCategory()->key());
        self::assertSame(2_000, $reloadedPrice->taxRate()->basisPoints());
        self::assertSame('2026-09-14 09:00:00', $reloadedPrice->saleStartsAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-21 18:00:00', $reloadedPrice->saleEndsAt()?->format('Y-m-d H:i:s'));
        self::assertSame(17, $reloadedInventory->quantity());
        self::assertFalse($reloadedInventory->availableForSale());
    }

    public function testDatabaseAllowsOnlyOnePricePerProduct(): void
    {
        $productId = $this->persistProduct('UNIQUE-PRICE');
        $this->connection->insert('commerce_product_price', $this->validPriceRow($productId));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->connection->insert('commerce_product_price', $this->validPriceRow($productId));
    }

    public function testDatabaseAllowsOnlyOneInventoryPerProduct(): void
    {
        $productId = $this->persistProduct('UNIQUE-INVENTORY');
        $this->connection->insert('commerce_product_inventory', $this->validInventoryRow($productId));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->connection->insert('commerce_product_inventory', $this->validInventoryRow($productId));
    }

    public function testDeletingAProductCascadesToPriceAndInventory(): void
    {
        $productId = $this->persistProduct('CASCADE-001');
        $this->connection->insert('commerce_product_price', $this->validPriceRow($productId));
        $this->connection->insert('commerce_product_inventory', $this->validInventoryRow($productId));

        $this->connection->delete('catalog_product', ['id' => $productId]);

        self::assertSame(0, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM commerce_product_price WHERE product_id = ?',
            [$productId],
        ));
        self::assertSame(0, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM commerce_product_inventory WHERE product_id = ?',
            [$productId],
        ));
    }

    /**
     * @param array<string, int|string|null> $invalidValues
     */
    #[DataProvider('invalidPriceRows')]
    public function testDatabaseRejectsInvalidPriceRows(array $invalidValues): void
    {
        $productId = $this->persistProduct('INVALID-PRICE');

        $this->expectException(DbalException::class);

        $this->connection->insert(
            'commerce_product_price',
            array_replace($this->validPriceRow($productId), $invalidValues),
        );
    }

    /** @return iterable<string, array{array<string, int|string|null>}> */
    public static function invalidPriceRows(): iterable
    {
        yield 'negative base amount' => [['base_minor_amount' => -1, 'sale_minor_amount' => null]];
        yield 'negative sale amount' => [['sale_minor_amount' => -1]];
        yield 'sale equal to base amount' => [['base_minor_amount' => 50_000, 'sale_minor_amount' => 50_000]];
        yield 'sale greater than base amount' => [['base_minor_amount' => 50_000, 'sale_minor_amount' => 50_001]];
        yield 'negative tax basis points' => [['tax_rate_basis_points' => -1]];
        yield 'tax above one hundred percent' => [['tax_rate_basis_points' => 10_001]];
        yield 'reversed bounded sale window' => [[
            'sale_starts_at' => '2026-09-21 18:00:00',
            'sale_ends_at' => '2026-09-14 09:00:00',
        ]];
    }

    public function testDatabaseRejectsNegativeInventoryQuantity(): void
    {
        $productId = $this->persistProduct('INVALID-INVENTORY');

        $this->expectException(DbalException::class);

        $this->connection->insert(
            'commerce_product_inventory',
            array_replace($this->validInventoryRow($productId), ['quantity' => -1]),
        );
    }

    private function persistProduct(string $sku): int
    {
        $product = new Product($sku, $sku.' Product', strtolower($sku));
        $this->entityManager->persist($product);
        $this->entityManager->flush();
        $productId = $product->id();
        self::assertIsInt($productId);

        return $productId;
    }

    /** @return array<string, int|string|null> */
    private function validPriceRow(int $productId): array
    {
        return [
            'product_id' => $productId,
            'base_minor_amount' => 100_000,
            'sale_minor_amount' => 90_000,
            'currency' => 'TRY',
            'tax_category' => 'standard',
            'tax_rate_basis_points' => 2_000,
            'sale_starts_at' => '2026-09-14 09:00:00',
            'sale_ends_at' => '2026-09-21 18:00:00',
            'created_at' => '2026-09-13 12:00:00',
            'updated_at' => '2026-09-13 12:00:00',
        ];
    }

    /** @return array<string, int|bool|string> */
    private function validInventoryRow(int $productId): array
    {
        return [
            'product_id' => $productId,
            'quantity' => 10,
            'available_for_sale' => true,
            'created_at' => '2026-09-13 12:00:00',
            'updated_at' => '2026-09-13 12:00:00',
        ];
    }
}
