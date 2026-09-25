<?php

namespace App\Tests\Unit\Integration\B2b\Provider\Efe;

use App\Module\Integration\B2b\Exception\B2bPermanentProviderException;
use App\Module\Integration\B2b\Provider\Efe\EfePriceNormalizer;
use App\Module\Pricing\PercentageDiscountCalculator;
use App\Module\Pricing\TaxCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EfePriceNormalizerTest extends TestCase
{
    private EfePriceNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new EfePriceNormalizer(
            new TaxCalculator(),
            new PercentageDiscountCalculator(),
        );
    }

    public function testItConvertsNetListPriceToTaxInclusiveGrossWithoutFloatRounding(): void
    {
        $gross = $this->normalizer->grossFromNet('838.87', '0', 20);

        self::assertSame(100_664, $gross->minorAmount());
        self::assertSame('TRY', $gross->currency());
    }

    /**
     * The live Efe feed publishes 964 rows with listefiyati "0.00". A zero sell price is an
     * impossible price for the storefront, so the row must be rejected per item instead of
     * being imported as "0,00 TRY".
     */
    #[DataProvider('zeroPrices')]
    public function testItRejectsAZeroSellPrice(string $listPrice, string $discount): void
    {
        $this->expectException(B2bPermanentProviderException::class);
        $this->expectExceptionMessageMatches('/price/i');

        $this->normalizer->grossFromNet($listPrice, $discount, 20);
    }

    /** @return iterable<string, array{string, string}> */
    public static function zeroPrices(): iterable
    {
        yield 'zero list price' => ['0.00', '0'];
        yield 'zero without decimals' => ['0', '0'];
        yield 'fully discounted to zero' => ['100.00', '100'];
    }

    public function testItAppliesProviderDiscountBeforeAddingVat(): void
    {
        $gross = $this->normalizer->grossFromNet('100.00', '10', 20);

        self::assertSame(10_800, $gross->minorAmount());
    }

    public function testItAcceptsFractionalProviderDiscountsExactly(): void
    {
        $gross = $this->normalizer->grossFromNet('100.00', '9.5', 20);

        self::assertSame(10_860, $gross->minorAmount());
    }

    #[DataProvider('invalidPrices')]
    public function testItRejectsMalformedOrImpossibleNetPrices(string $price): void
    {
        $this->expectException(B2bPermanentProviderException::class);

        $this->normalizer->grossFromNet($price, '0', 20);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPrices(): iterable
    {
        yield 'negative' => ['-1.00'];
        yield 'too precise' => ['1.001'];
        yield 'not numeric' => ['1,00'];
        yield 'scientific' => ['1e2'];
    }

    #[DataProvider('invalidDiscounts')]
    public function testItRejectsMalformedOrOutOfRangeDiscounts(string $discount): void
    {
        $this->expectException(B2bPermanentProviderException::class);

        $this->normalizer->grossFromNet('100.00', $discount, 20);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDiscounts(): iterable
    {
        yield 'negative' => ['-0.01'];
        yield 'over one hundred' => ['100.01'];
        yield 'too precise' => ['1.00001'];
        yield 'not numeric' => ['ten'];
    }

    public function testItRejectsInvalidVatPercentage(): void
    {
        $this->expectException(B2bPermanentProviderException::class);

        $this->normalizer->grossFromNet('100.00', '0', 101);
    }
}
