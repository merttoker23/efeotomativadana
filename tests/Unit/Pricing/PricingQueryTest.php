<?php

namespace App\Tests\Unit\Pricing;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductPrice;
use App\Module\Pricing\PriceView;
use App\Module\Pricing\PricingQuery;
use App\Module\Pricing\ProductPriceRepositoryInterface;
use App\Module\Pricing\TaxCalculator;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PricingQueryTest extends TestCase
{
    public function testItReturnsNullWhenTheProductHasNoPrice(): void
    {
        $product = self::product();
        $query = self::query(new InMemoryProductPriceRepository());

        self::assertNull($query->forProduct($product));
    }

    public function testItReturnsTheRegularTaxInclusivePriceWhenThereIsNoSale(): void
    {
        $price = self::price();
        $query = self::query(new InMemoryProductPriceRepository($price));

        $view = $query->forProduct($price->product());

        self::assertNotNull($view);
        self::assertTrue(Money::ofMinor(12000, 'TRY')->equals($view->basePrice()));
        self::assertTrue(Money::ofMinor(12000, 'TRY')->equals($view->sellPrice()));
        self::assertFalse($view->onSale());
        self::assertSame('standard', $view->taxCategory()->key());
        self::assertSame(2000, $view->taxRate()->basisPoints());
    }

    public function testItBuildsTheSameExactViewFromTheCheckoutLockedRead(): void
    {
        $price = self::price();
        $query = self::query(new InMemoryProductPriceRepository($price));

        $view = $query->forProductForUpdate($price->product());

        self::assertNotNull($view);
        self::assertTrue(Money::ofMinor(12000, 'TRY')->equals($view->sellPrice()));
        self::assertSame(2000, $view->taxRate()->basisPoints());
    }

    public function testItReturnsAnActiveSaleAndItsExactTwentyPercentTaxSplit(): void
    {
        $price = self::price();
        $price->scheduleSale(
            Money::ofMinor(9600, 'TRY'),
            new \DateTimeImmutable('2026-09-13T09:00:00+03:00'),
            new \DateTimeImmutable('2026-09-13T11:00:00+03:00'),
        );
        $query = self::query(new InMemoryProductPriceRepository($price));

        $view = $query->forProduct($price->product());

        self::assertNotNull($view);
        self::assertTrue(Money::ofMinor(9600, 'TRY')->equals($view->sellPrice()));
        self::assertTrue(Money::ofMinor(8000, 'TRY')->equals($view->netPrice()));
        self::assertTrue(Money::ofMinor(1600, 'TRY')->equals($view->taxAmount()));
        self::assertTrue($view->onSale());
    }

    public function testItUsesTheBasePriceAtTheExclusiveSaleEndBoundary(): void
    {
        $price = self::price();
        $price->scheduleSale(
            Money::ofMinor(9600, 'TRY'),
            new \DateTimeImmutable('2026-09-13T09:00:00+03:00'),
            new \DateTimeImmutable('2026-09-13T10:00:00+03:00'),
        );
        $query = self::query(new InMemoryProductPriceRepository($price));

        $view = $query->forProduct($price->product());

        self::assertNotNull($view);
        self::assertTrue(Money::ofMinor(12000, 'TRY')->equals($view->sellPrice()));
        self::assertTrue(Money::ofMinor(10000, 'TRY')->equals($view->netPrice()));
        self::assertTrue(Money::ofMinor(2000, 'TRY')->equals($view->taxAmount()));
        self::assertFalse($view->onSale());
    }

    public function testPriceViewRejectsCurrenciesThatDoNotMatchTheSellPrice(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PriceView(
            Money::ofMinor(12000, 'TRY'),
            Money::ofMinor(9600, 'TRY'),
            Money::ofMinor(8000, 'EUR'),
            Money::ofMinor(1600, 'TRY'),
            TaxCategory::of('standard'),
            TaxRate::fromBasisPoints(2000),
            true,
        );
    }

    public function testPriceViewRejectsTaxTotalsThatDoNotAddUpToTheSellPrice(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PriceView(
            Money::ofMinor(12000, 'TRY'),
            Money::ofMinor(9600, 'TRY'),
            Money::ofMinor(8000, 'TRY'),
            Money::ofMinor(1500, 'TRY'),
            TaxCategory::of('standard'),
            TaxRate::fromBasisPoints(2000),
            true,
        );
    }

    private static function query(ProductPriceRepositoryInterface $prices): PricingQuery
    {
        return new PricingQuery(
            $prices,
            new TaxCalculator(),
            new MockClock('2026-09-13T10:00:00+03:00'),
        );
    }

    private static function price(): ProductPrice
    {
        return new ProductPrice(
            self::product(),
            Money::ofMinor(12000, 'TRY'),
            TaxCategory::of('standard'),
            TaxRate::fromBasisPoints(2000),
        );
    }

    private static function product(): Product
    {
        return new Product('BAL-001', 'Ön Fren Balatası', 'on-fren-balatasi');
    }
}

final class InMemoryProductPriceRepository implements ProductPriceRepositoryInterface
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
}
