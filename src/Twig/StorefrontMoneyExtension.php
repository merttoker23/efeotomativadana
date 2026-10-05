<?php

namespace App\Twig;

use App\Shared\Money\Money;
use Symfony\Component\Intl\Currencies;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class StorefrontMoneyExtension extends AbstractExtension
{
    /** @return list<TwigFilter> */
    public function getFilters(): array
    {
        return [
            new TwigFilter('storefront_money', $this->format(...)),
            new TwigFilter('storefront_price_input', $this->priceInput(...)),
            new TwigFilter('storefront_price_display', $this->priceDisplay(...)),
        ];
    }

    /**
     * A price as the digits a customer types into a filter field: minor units written out plainly,
     * with a dot for the kuruş and without the trailing zeros a fixed two-decimal format adds.
     *
     * {@see self::format()} prints money the way the storefront reads it — grouped thousands, comma
     * decimals, a currency after it — and none of that can be typed into a number field, where the
     * dot is a decimal point and "399.735" would be read as three hundred and ninety-nine point
     * seven hundred thirty-five. So the fields that are edited rather than read are written this
     * way instead, and an absent bound is an empty field rather than a zero.
     */
    public function priceInput(?int $minor): string
    {
        if (null === $minor) {
            return '';
        }

        $fraction = $minor % 100;

        return 0 === $fraction
            ? (string) intdiv($minor, 100)
            : intdiv($minor, 100).'.'.str_pad((string) $fraction, 2, '0', \STR_PAD_LEFT);
    }

    public function priceDisplay(?int $minor): string
    {
        return null === $minor ? '' : number_format(intdiv($minor, 100), 0, ',', '.').','.str_pad((string) ($minor % 100), 2, '0', \STR_PAD_LEFT);
    }

    public function format(Money $money): string
    {
        $digits = Currencies::getFractionDigits($money->currency());
        $divisor = 1;
        for ($i = 0; $i < $digits; ++$i) {
            $divisor *= 10;
        }

        $major = intdiv($money->minorAmount(), $divisor);
        $formatted = number_format($major, 0, ',', '.');
        if ($digits > 0) {
            $fraction = str_pad((string) ($money->minorAmount() % $divisor), $digits, '0', \STR_PAD_LEFT);
            $formatted .= ','.$fraction;
        }

        return $formatted.' '.('TRY' === $money->currency() ? 'TL' : $money->currency());
    }
}
