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

        self::assertSame('1.599,90 TRY', $extension->format(Money::ofMinor(159_990, 'TRY')));
        self::assertSame('42 JPY', $extension->format(Money::ofMinor(42, 'JPY')));
    }
}
