<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Gateway\PayTR;

use App\Module\Payment\Gateway\PayTR\PaytrSignature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins PayTR's three documented HMAC-SHA256/base64 token shapes to fixed vectors.
 *
 * The salt is placed differently in each of them — appended for the token request and the
 * refund, but in the middle for the callback hash — so each shape gets its own vector rather
 * than one shared helper that could hide a swapped order.
 */
final class PaytrSignatureTest extends TestCase
{
    private const string MERCHANT_ID = '123456';
    private const string MERCHANT_KEY = 'testKey';
    private const string MERCHANT_SALT = 'testSalt';

    /** What PayTR is actually given as `merchant_oid`: order number, no hyphens, plus the attempt. */
    private const string ORDER_REFERENCE = 'EOA20260925ABCDEF123456A1';

    private const string TOKEN = 'SH1UrBm6E9Iy30f+VVRq/T5e0H2+Qrwwj0RA1oj+Kws=';
    private const string CALLBACK_HASH = '81EHeCyvBPQzReGVjujqpjYvAwOuRpk7Phtl01T8UAM=';
    private const string REFUND_TOKEN = 'Vujefa+LPK1g4Fs088J1vZkVG+amaI7rUzJjG0CzlRQ=';

    public function testTheInitiationTokenAppendsTheSaltToTheHashString(): void
    {
        $signature = new PaytrSignature(self::MERCHANT_KEY, self::MERCHANT_SALT);

        $hashString = self::MERCHANT_ID . '88.99.140.12' . self::ORDER_REFERENCE . 'musteri@example.com'
            . '1599.90' . 'card' . '0' . 'TL' . '1' . '0';

        self::assertSame(self::TOKEN, $signature->initiationToken($hashString));
    }

    public function testTheCallbackHashPlacesTheSaltBetweenTheOrderAndTheStatus(): void
    {
        $signature = new PaytrSignature(self::MERCHANT_KEY, self::MERCHANT_SALT);

        self::assertSame(
            self::CALLBACK_HASH,
            $signature->callbackHash(self::ORDER_REFERENCE, 'success', '159990'),
        );
    }

    public function testTheRefundTokenAppendsTheSaltAfterTheDecimalAmount(): void
    {
        $signature = new PaytrSignature(self::MERCHANT_KEY, self::MERCHANT_SALT);

        self::assertSame(
            self::REFUND_TOKEN,
            $signature->refundToken(self::MERCHANT_ID, self::ORDER_REFERENCE, '1599.90'),
        );
    }

    public function testACorrectCallbackHashIsAccepted(): void
    {
        $signature = new PaytrSignature(self::MERCHANT_KEY, self::MERCHANT_SALT);

        self::assertTrue($signature->callbackHashMatches(self::ORDER_REFERENCE, 'success', '159990', self::CALLBACK_HASH));
    }

    #[DataProvider('rejectedCallbackHashes')]
    public function testAnythingOtherThanTheExactHashIsRejected(string $provided): void
    {
        $signature = new PaytrSignature(self::MERCHANT_KEY, self::MERCHANT_SALT);

        self::assertFalse($signature->callbackHashMatches(self::ORDER_REFERENCE, 'success', '159990', $provided));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedCallbackHashes(): iterable
    {
        yield 'empty' => [''];
        yield 'truncated' => ['FhKd/yf6T2wgP+vEOgnXCdOS+hiOOiG2/2rroFEPpe'];
        yield 'one character changed' => ['FhKd/yf6T2wgP+vEOgnXCdOS+hiOOiG2/2rroFEPpe5='];
        yield 'the hash of a different amount' => ['FhKd/yf6T2wgP+vEOgnXCdOS+hiOOiG2/2rroFEPpe4x'];
    }

    public function testADifferentMerchantKeyProducesADifferentHash(): void
    {
        $signature = new PaytrSignature('anotherKey', self::MERCHANT_SALT);

        self::assertFalse($signature->callbackHashMatches(self::ORDER_REFERENCE, 'success', '159990', self::CALLBACK_HASH));
    }

    public function testAnEmptyMerchantKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PaytrSignature('', self::MERCHANT_SALT);
    }

    public function testAnEmptyMerchantSaltIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PaytrSignature(self::MERCHANT_KEY, '   ');
    }
}
