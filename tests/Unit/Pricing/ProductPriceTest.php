<?php

namespace App\Tests\Unit\Pricing;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductPrice;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

final class ProductPriceTest extends TestCase
{
    public function testItExposesItsRegularTaxInclusivePricePolicy(): void
    {
        $product = self::product();
        $price = new ProductPrice(
            $product,
            Money::ofMinor(12000, 'TRY'),
            TaxCategory::of('standard'),
            TaxRate::fromBasisPoints(2000),
        );

        self::assertSame($product, $price->product());
        self::assertTrue(Money::ofMinor(12000, 'TRY')->equals($price->basePrice()));
        self::assertNull($price->salePrice());
        self::assertSame('standard', $price->taxCategory()->key());
        self::assertSame(2000, $price->taxRate()->basisPoints());
        self::assertNull($price->saleStartsAt());
        self::assertNull($price->saleEndsAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $price->createdAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $price->updatedAt());
        self::assertTrue($price->basePrice()->equals($price->sellPriceAt(new \DateTimeImmutable('2026-09-13T10:00:00+03:00'))));
    }

    public function testItRejectsASaleInAnotherCurrency(): void
    {
        $price = self::price();

        $this->expectException(\InvalidArgumentException::class);

        $price->scheduleSale(Money::ofMinor(9000, 'EUR'));
    }

    public function testItRejectsASaleThatIsNotStrictlyBelowTheBasePrice(): void
    {
        $price = self::price();

        $this->expectException(\InvalidArgumentException::class);

        $price->scheduleSale(Money::ofMinor(12000, 'TRY'));
    }

    public function testItRejectsAReversedOrEmptySaleWindow(): void
    {
        $price = self::price();
        $instant = new \DateTimeImmutable('2026-09-13T10:00:00+03:00');

        $this->expectException(\InvalidArgumentException::class);

        $price->scheduleSale(Money::ofMinor(9000, 'TRY'), $instant, $instant);
    }

    public function testSaleWindowsAreStartInclusiveAndEndExclusive(): void
    {
        $price = self::price();
        $startsAt = new \DateTimeImmutable('2026-09-13T10:00:00+03:00');
        $endsAt = new \DateTimeImmutable('2026-09-14T10:00:00+03:00');
        $price->scheduleSale(Money::ofMinor(9000, 'TRY'), $startsAt, $endsAt);

        self::assertFalse($price->isSaleActiveAt($startsAt->modify('-1 microsecond')));
        self::assertTrue($price->isSaleActiveAt($startsAt));
        self::assertTrue($price->isSaleActiveAt($endsAt->modify('-1 microsecond')));
        self::assertFalse($price->isSaleActiveAt($endsAt));
        self::assertTrue(Money::ofMinor(9000, 'TRY')->equals($price->sellPriceAt($startsAt)));
        self::assertTrue(Money::ofMinor(12000, 'TRY')->equals($price->sellPriceAt($endsAt)));
    }

    public function testSaleWindowsCanBeOpenEnded(): void
    {
        $price = self::price();
        $boundary = new \DateTimeImmutable('2026-09-13T10:00:00+03:00');

        $price->scheduleSale(Money::ofMinor(9000, 'TRY'), null, $boundary);
        self::assertTrue($price->isSaleActiveAt($boundary->modify('-10 years')));
        self::assertFalse($price->isSaleActiveAt($boundary));

        $price->scheduleSale(Money::ofMinor(8000, 'TRY'), $boundary, null);
        self::assertFalse($price->isSaleActiveAt($boundary->modify('-1 second')));
        self::assertTrue($price->isSaleActiveAt($boundary->modify('+10 years')));

        $price->scheduleSale(Money::ofMinor(7000, 'TRY'));
        self::assertTrue($price->isSaleActiveAt($boundary->modify('-100 years')));
        self::assertTrue($price->isSaleActiveAt($boundary->modify('+100 years')));
    }

    public function testClearingASaleClearsItsPriceAndBothBounds(): void
    {
        $price = self::price();
        $price->scheduleSale(
            Money::ofMinor(9000, 'TRY'),
            new \DateTimeImmutable('2026-09-13T10:00:00+03:00'),
            new \DateTimeImmutable('2026-09-14T10:00:00+03:00'),
        );

        $price->clearSale();

        self::assertNull($price->salePrice());
        self::assertNull($price->saleStartsAt());
        self::assertNull($price->saleEndsAt());
        self::assertFalse($price->isSaleActiveAt(new \DateTimeImmutable('2026-09-13T12:00:00+03:00')));
    }

    public function testItCanReconfigureTheBaseAndTaxPolicyWhenTheSaleRemainsValid(): void
    {
        $price = self::price();
        $price->scheduleSale(Money::ofMinor(9000, 'TRY'));

        $price->reconfigure(
            Money::ofMinor(10000, 'TRY'),
            TaxCategory::of('reduced-rate'),
            TaxRate::fromBasisPoints(1000),
        );

        self::assertTrue(Money::ofMinor(10000, 'TRY')->equals($price->basePrice()));
        self::assertSame('reduced-rate', $price->taxCategory()->key());
        self::assertSame(1000, $price->taxRate()->basisPoints());
        self::assertTrue(Money::ofMinor(9000, 'TRY')->equals($price->salePrice()));
    }

    public function testItRejectsReconfigurationThatWouldInvalidateTheRemainingSale(): void
    {
        $price = self::price();
        $price->scheduleSale(Money::ofMinor(9000, 'TRY'));

        try {
            $price->reconfigure(
                Money::ofMinor(8000, 'TRY'),
                TaxCategory::of('reduced-rate'),
                TaxRate::fromBasisPoints(1000),
            );
            self::fail('Expected invalid reconfiguration to be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(Money::ofMinor(12000, 'TRY')->equals($price->basePrice()));
            self::assertSame('standard', $price->taxCategory()->key());
            self::assertSame(2000, $price->taxRate()->basisPoints());
        }
    }

    public function testItRejectsReconfigurationThatWouldChangeTheCurrencyOfTheRemainingSale(): void
    {
        $price = self::price();
        $price->scheduleSale(Money::ofMinor(9000, 'TRY'));

        $this->expectException(\InvalidArgumentException::class);

        $price->reconfigure(
            Money::ofMinor(12000, 'EUR'),
            TaxCategory::of('standard'),
            TaxRate::fromBasisPoints(2000),
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
