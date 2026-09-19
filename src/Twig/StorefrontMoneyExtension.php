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
        return [new TwigFilter('storefront_money', $this->format(...))];
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

        return $formatted.' '.$money->currency();
    }
}
