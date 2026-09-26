<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Gateway\PayTR;

use App\Module\Payment\Gateway\GatewayInitiationInstruction;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

/**
 * A concrete provider turned out to need more than the eight fields the instruction originally
 * carried: PayTR's Direct API requires the customer's name, phone and address, and a basket.
 *
 * These are ordinary order and customer facts, not provider concepts, so they are added to the
 * instruction as optional trailing values. Every existing caller keeps working unchanged, and a
 * gateway that needs none of them is unaffected.
 */
final class GatewayInitiationInstructionCustomerTest extends TestCase
{
    public function testTheCustomerFieldsAreCarriedWhenTheOrderHasThem(): void
    {
        $instruction = new GatewayInitiationInstruction(
            'EOA-20260925-ABCDEF123456',
            '1',
            str_repeat('a', 64),
            Money::ofMinor(159_990, 'TRY'),
            'https://store.test/odeme/sonuc/x',
            'https://store.test/odeme/iptal/x',
            'musteri@example.com',
            'tr',
            'Efe Yilmaz',
            '05000000000',
            'Ataturk Cad. 1, Seyhan, Adana',
            [['Ürün adı', '1599.90', 1]],
        );

        self::assertSame('Efe Yilmaz', $instruction->customerName());
        self::assertSame('05000000000', $instruction->customerPhone());
        self::assertSame('Ataturk Cad. 1, Seyhan, Adana', $instruction->customerAddress());
        self::assertSame([['Ürün adı', '1599.90', 1]], $instruction->basketLines());
    }

    public function testTheyAreOptionalSoAGatewayThatNeedsNoneIsUnaffected(): void
    {
        $instruction = new GatewayInitiationInstruction(
            'EOA-20260925-ABCDEF123456',
            '1',
            str_repeat('a', 64),
            Money::ofMinor(159_990, 'TRY'),
            'https://store.test/odeme/sonuc/x',
            'https://store.test/odeme/iptal/x',
            'musteri@example.com',
            'tr',
        );

        self::assertNull($instruction->customerName());
        self::assertNull($instruction->customerPhone());
        self::assertNull($instruction->customerAddress());
        self::assertSame([], $instruction->basketLines());
    }

    public function testABlankCustomerNameBecomesAbsentRatherThanAnEmptyString(): void
    {
        $instruction = new GatewayInitiationInstruction(
            'EOA-20260925-ABCDEF123456',
            '1',
            str_repeat('a', 64),
            Money::ofMinor(159_990, 'TRY'),
            'https://store.test/odeme/sonuc/x',
            'https://store.test/odeme/iptal/x',
            'musteri@example.com',
            'tr',
            '   ',
        );

        self::assertNull($instruction->customerName());
    }

    public function testABasketLineWithNoNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GatewayInitiationInstruction(
            'EOA-20260925-ABCDEF123456',
            '1',
            str_repeat('a', 64),
            Money::ofMinor(159_990, 'TRY'),
            'https://store.test/odeme/sonuc/x',
            'https://store.test/odeme/iptal/x',
            'musteri@example.com',
            'tr',
            'Efe Yilmaz',
            '05000000000',
            'Adana',
            [['', '10.00', 1]],
        );
    }

    public function testABasketLineWithANonPositiveQuantityIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GatewayInitiationInstruction(
            'EOA-20260925-ABCDEF123456',
            '1',
            str_repeat('a', 64),
            Money::ofMinor(159_990, 'TRY'),
            'https://store.test/odeme/sonuc/x',
            'https://store.test/odeme/iptal/x',
            'musteri@example.com',
            'tr',
            'Efe Yilmaz',
            '05000000000',
            'Adana',
            [['Ürün', '10.00', 0]],
        );
    }

    public function testABasketLineWithAMalformedPriceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GatewayInitiationInstruction(
            'EOA-20260925-ABCDEF123456',
            '1',
            str_repeat('a', 64),
            Money::ofMinor(159_990, 'TRY'),
            'https://store.test/odeme/sonuc/x',
            'https://store.test/odeme/iptal/x',
            'musteri@example.com',
            'tr',
            'Efe Yilmaz',
            '05000000000',
            'Adana',
            [['Ürün', '10,00', 1]],
        );
    }
}
