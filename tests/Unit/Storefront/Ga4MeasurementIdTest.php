<?php

namespace App\Tests\Unit\Storefront;

use App\Module\Settings\Ga4MeasurementId;
use PHPUnit\Framework\TestCase;

final class Ga4MeasurementIdTest extends TestCase
{
    public function testNormalizesOptionalIdsAndDoesNotAcceptOtherTagsOrExecutableText(): void
    {
        self::assertNull(Ga4MeasurementId::normalize(null));
        self::assertNull(Ga4MeasurementId::normalize(" \n "));
        self::assertSame('G-ABC1234567', Ga4MeasurementId::normalize(" g-abc1234567\n"));
        foreach (['UA-1234567-1', 'GTM-ABC1234567', 'G-ABC123456', 'G-ABC12345678', 'G-ABC1234567<script>alert(1)</script>'] as $invalid) {
            self::assertFalse(Ga4MeasurementId::isValid(Ga4MeasurementId::normalize($invalid)));
        }
        self::assertFalse(Ga4MeasurementId::isValid("G-ABC1234567\n"));
    }
}
