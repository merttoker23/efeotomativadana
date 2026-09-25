<?php

namespace App\Module\Integration\B2b\Provider\Efe;

use App\Module\Integration\B2b\Exception\B2bPermanentProviderException;
use App\Module\Pricing\PercentageDiscountCalculator;
use App\Module\Pricing\TaxCalculator;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;

final readonly class EfePriceNormalizer
{
    public function __construct(
        private TaxCalculator $taxCalculator,
        private PercentageDiscountCalculator $discountCalculator,
    ) {
    }

    public function grossFromNet(string $listPrice, string $discount, int $vatPercentage): Money
    {
        try {
            $net = Money::ofMinor($this->decimalToMinor($listPrice, 'List price', 2), 'TRY');
            $discountedNet = $this->discountCalculator->apply($net, $this->percentageToBasisPoints($discount, 'Discount'));
            $taxRate = TaxRate::fromPercentage($vatPercentage);
            $gross = $this->taxCalculator->fromTaxExclusive($discountedNet, $taxRate)->gross();
            if (0 === $gross->minorAmount()) {
                // The live feed publishes rows with listefiyati "0.00". A zero sell price is an
                // impossible price for the storefront, so the row is rejected per item rather
                // than imported as "0,00 TRY". The message keeps the word "price" so the feed
                // adapter classifies it as an invalid price instead of a generic item failure.
                throw new \InvalidArgumentException('The provider list price must resolve to a sell price greater than zero.');
            }

            return $gross;
        } catch (\Throwable $exception) {
            if ($exception instanceof B2bPermanentProviderException) {
                throw $exception;
            }

            throw new B2bPermanentProviderException('Efe price fields are invalid.', 0, $exception);
        }
    }

    private function decimalToMinor(string $value, string $field, int $scale): int
    {
        $value = trim($value);
        if (1 !== preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,'.$scale.'}))?$/', $value, $matches)) {
            throw new \InvalidArgumentException(sprintf('%s must be a non-negative decimal with at most %d fraction digits.', $field, $scale));
        }

        $fraction = str_pad($matches[2] ?? '', $scale, '0');
        $fractionMinor = '' === $fraction ? 0 : (int) $fraction;
        $whole = (int) $matches[1];
        if ($whole > intdiv(PHP_INT_MAX - $fractionMinor, 10 ** $scale)) {
            throw new \OverflowException(sprintf('%s exceeds the integer range.', $field));
        }

        return ($whole * (10 ** $scale)) + $fractionMinor;
    }

    private function percentageToBasisPoints(string $value, string $field): int
    {
        $value = trim($value);
        if (1 !== preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/', $value, $matches)) {
            throw new \InvalidArgumentException(sprintf('%s must be a percentage with at most two fraction digits.', $field));
        }
        $whole = (int) $matches[1];
        if ($whole > 100) {
            throw new \InvalidArgumentException(sprintf('%s must be between 0 and 100 percent.', $field));
        }
        $fraction = str_pad($matches[2] ?? '', 2, '0');

        return ($whole * 100) + (int) $fraction;
    }
}
