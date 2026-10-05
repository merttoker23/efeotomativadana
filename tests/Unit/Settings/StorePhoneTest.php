<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settings;

use App\Module\Settings\StorePhone;
use PHPUnit\Framework\TestCase;

/**
 * Telefon, insanın yazdığı biçimden tek bir kanonik biçime indirgenir. Buradaki her biçim
 * footer'da aynı metin olarak görünür ve aynı `tel:` bağlantısına gider.
 */
final class StorePhoneTest extends TestCase
{
    public function testEveryWayOfWritingTheSameNumberBecomesTheSameNumber(): void
    {
        $expected = '+90 322 123 45 67';

        foreach ([
            '+90 322 123 45 67',
            '+90 322 123 45 67',
            '0090 322 123 45 67',
            '90 322 123 45 67',
            '0 322 123 45 67',
            '0322 123 45 67',
            '322 123 45 67',
            '322 123 4567',
            '3221234567',
            '(0322) 123-45-67',
            '  +90 (322) 123.45.67  ',
        ] as $written) {
            self::assertSame($expected, StorePhone::normalize($written), sprintf('"%s" should normalise.', $written));
        }
    }

    public function testAMobileNumberKeepsItsOperatorPrefix(): void
    {
        self::assertSame('+90 532 123 45 67', StorePhone::normalize('0532 123 45 67'));
    }

    public function testABlankFieldStaysBlankRatherThanBecomingAnEmptyString(): void
    {
        self::assertNull(StorePhone::normalize(null));
        self::assertNull(StorePhone::normalize(''));
        self::assertNull(StorePhone::normalize('   '));
        self::assertFalse(StorePhone::isValid(''));
    }

    public function testAnythingThatIsNotAPhoneNumberIsRefused(): void
    {
        $invalid = [
            '444',
            '322 123 45',
            '322 123 45 678',
            '+90 322 123 45 67 99',
            'tel:+903221234567',
            '<script>alert(1)</script>',
            '0212 123 45 67 ext. 5',
            '322 123 45 6a',
        ];

        foreach ($invalid as $written) {
            self::assertFalse(StorePhone::isValid($written), sprintf('"%s" should be refused.', $written));

            // The same rule as an exception rather than as a boolean, so a caller that forgets to
            // ask `isValid()` first cannot store the raw value by accident.
            try {
                StorePhone::normalize($written);
                self::fail(sprintf('"%s" should have been refused.', $written));
            } catch (\InvalidArgumentException) {
                // Expected: the exception is the assertion.
            }
        }
    }

    public function testATwelveDigitNumberBeginningWithNinetyIsReadAsACountryCode(): void
    {
        // `902212345678` cannot be told apart from +90 221 234 5678 by shape alone; reading the
        // leading 90 as Turkey's country code is the only reading that can produce a callable
        // number, and the alternative would silently drop a digit.
        self::assertSame('+90 221 234 56 78', StorePhone::normalize('902212345678'));
    }

    public function testTheTelLinkIsInternationalAndUnformatted(): void
    {
        self::assertSame('tel:+903221234567', StorePhone::link('+90 322 123 45 67'));
        self::assertSame('tel:+903221234567', StorePhone::link('0322 123 45 67'));
        self::assertNull(StorePhone::link(null));
        self::assertNull(StorePhone::link(''));
    }
}
