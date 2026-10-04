<?php

namespace App\Tests\Unit\Storefront;

use App\Shared\Money\Money;
use App\Twig\StorefrontMoneyExtension;
use PHPUnit\Framework\TestCase;

final class StorefrontMoneyExtensionTest extends TestCase
{
    public function testFormatsMinorUnitsWithoutFloatingPointArithmetic(): void
    {
        $extension = new StorefrontMoneyExtension();

        $money = Money::ofMinor(152_574, 'TRY');
        self::assertSame('1.525,74 TL', $extension->format($money));
        self::assertSame('TRY', $money->currency());
        self::assertSame('1.599,90 EUR', $extension->format(Money::ofMinor(159_990, 'EUR')));
        self::assertSame('42 JPY', $extension->format(Money::ofMinor(42, 'JPY')));
    }
}
