<?php

namespace App\Tests\Unit\Pricing;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductPrice;
use App\Module\Pricing\PricingManager;
use App\Module\Pricing\ProductPriceRepositoryInterface;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PricingManagerTest extends TestCase
{
    public function testItCreatesPersistsAndFlushesARegularPrice(): void
    {
        $repository = new RecordingProductPriceRepository();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $manager = new PricingManager($repository, $entityManager);
        $product = self::product();

        $price = $manager->upsert(
            $product,
            Money::ofMinor(12000, 'TRY'),
            TaxCategory::of('standard'),
            TaxRate::fromBasisPoints(2000),
        );

        self::assertSame($price, $repository->current());
        self::assertSame($product, $price->product());
        self::assertTrue(Money::ofMinor(12000, 'TRY')->equals($price->basePrice()));
        self::assertNull($price->salePrice());
    }

    public function testItReplacesAnExistingSalePolicyDuringUpsert(): void
    {
        $product = self::product();
        $existing = new ProductPrice(
            $product,
            Money::ofMinor(12000, 'TRY'),
            TaxCategory::of('standard'),
            TaxRate::fromBasisPoints(2000),
        );
        $existing->scheduleSale(Money::ofMinor(9000, 'TRY'));
        $repository = new RecordingProductPriceRepository($existing);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $manager = new PricingManager($repository, $entityManager);
        $startsAt = new \DateTimeImmutable('2026-09-13T10:00:00+03:00');
        $endsAt = new \DateTimeImmutable('2026-09-14T10:00:00+03:00');

        $price = $manager->upsert(
            $product,
            Money::ofMinor(8000, 'TRY'),
            TaxCategory::of('reduced-rate'),
            TaxRate::fromBasisPoints(1000),
            Money::ofMinor(7000, 'TRY'),
            $startsAt,
            $endsAt,
        );

        self::assertSame($existing, $price);
        self::assertTrue(Money::ofMinor(8000, 'TRY')->equals($price->basePrice()));
        self::assertTrue(Money::ofMinor(7000, 'TRY')->equals($price->salePrice()));
        self::assertSame($startsAt->getTimestamp(), $price->saleStartsAt()?->getTimestamp());
        self::assertSame($endsAt->getTimestamp(), $price->saleEndsAt()?->getTimestamp());
        self::assertSame('reduced-rate', $price->taxCategory()->key());
        self::assertSame(1000, $price->taxRate()->basisPoints());
    }

    public function testItRejectsSaleBoundsWithoutASalePriceBeforePersistence(): void
    {
        $repository = new RecordingProductPriceRepository();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $manager = new PricingManager($repository, $entityManager);

        try {
            $manager->upsert(
                self::product(),
                Money::ofMinor(12000, 'TRY'),
                TaxCategory::of('standard'),
                TaxRate::fromBasisPoints(2000),
                saleStartsAt: new \DateTimeImmutable('2026-09-13T10:00:00+03:00'),
            );
            self::fail('Expected orphan sale bounds to be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertNull($repository->current());
        }
    }

    public function testItLeavesTheExistingPolicyUntouchedWhenAReplacementSaleIsInvalid(): void
    {
        $product = self::product();
        $existing = new ProductPrice(
            $product,
            Money::ofMinor(12000, 'TRY'),
            TaxCategory::of('standard'),
            TaxRate::fromBasisPoints(2000),
        );
        $existing->scheduleSale(Money::ofMinor(9000, 'TRY'));
        $repository = new RecordingProductPriceRepository($existing);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $manager = new PricingManager($repository, $entityManager);

        try {
            $manager->upsert(
                $product,
                Money::ofMinor(8000, 'TRY'),
                TaxCategory::of('reduced-rate'),
                TaxRate::fromBasisPoints(1000),
                Money::ofMinor(8000, 'TRY'),
            );
            self::fail('Expected an invalid replacement sale to be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(Money::ofMinor(12000, 'TRY')->equals($existing->basePrice()));
            self::assertTrue(Money::ofMinor(9000, 'TRY')->equals($existing->salePrice()));
            self::assertSame('standard', $existing->taxCategory()->key());
            self::assertSame(2000, $existing->taxRate()->basisPoints());
        }
    }

    private static function product(): Product
    {
        return new Product('BAL-001', 'Ön Fren Balatası', 'on-fren-balatasi');
    }
}

final class RecordingProductPriceRepository implements ProductPriceRepositoryInterface
{
    public function __construct(private ?ProductPrice $price = null)
    {
    }

    public function findOneByProduct(Product $product): ?ProductPrice
    {
        return null !== $this->price && $this->price->product() === $product ? $this->price : null;
    }

    public function findOneByProductForUpdate(Product $product): ?ProductPrice
    {
        return $this->findOneByProduct($product);
    }

    public function save(ProductPrice $price): void
    {
        $this->price = $price;
    }

    public function current(): ?ProductPrice
    {
        return $this->price;
    }
}
