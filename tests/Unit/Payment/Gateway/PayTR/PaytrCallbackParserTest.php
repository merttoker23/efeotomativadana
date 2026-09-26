<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Gateway\PayTR;

use App\Module\Payment\Gateway\PayTR\PaytrCallbackParser;
use App\Module\Payment\Gateway\PayTR\PaytrSignature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The notification is a form post, so the parser works on the raw body rather than on a
 * re-encoded array. Anything the documented contract calls required has to be structurally
 * present and well formed, otherwise the callback is refused before its hash is trusted.
 */
final class PaytrCallbackParserTest extends TestCase
{
    /** The order number as PayTR echoes it back: the store's own number without hyphens. */
    private const string ORDER_REFERENCE = 'EOA20260925ABCDEF123456';
    private const string HASH = 'FhKd/yf6T2wgP+vEOgnXCdOS+hiOOiG2/2rroFEPpe4=';

    public function testASuccessfulNotificationIsParsed(): void
    {
        $report = (new PaytrCallbackParser())->parse($this->body([
            'merchant_oid' => self::ORDER_REFERENCE,
            'status' => 'success',
            'total_amount' => '159990',
            'payment_amount' => '159990',
            'hash' => self::HASH,
            'payment_type' => 'card',
            'currency' => 'TL',
            'test_mode' => '1',
        ]));

        self::assertNotNull($report);
        self::assertSame(self::ORDER_REFERENCE, $report->merchantOid());
        self::assertTrue($report->isSuccess());
        self::assertSame(159_990, $report->totalAmountMinor());
        self::assertSame(159_990, $report->paymentAmountMinor());
        self::assertSame('card', $report->paymentType());
        self::assertSame('TRY', $report->currency());
        self::assertTrue($report->isTestMode());
    }

    public function testAFailedNotificationCarriesTheProviderReason(): void
    {
        $report = (new PaytrCallbackParser())->parse($this->body([
            'merchant_oid' => self::ORDER_REFERENCE,
            'status' => 'failed',
            'total_amount' => '159990',
            'payment_amount' => '159990',
            'hash' => self::HASH,
            'failed_reason_code' => '99',
            'failed_reason_msg' => 'İşlem başarısız: Teknik entegrasyon hatası.',
        ]));

        self::assertNotNull($report);
        self::assertFalse($report->isSuccess());
        self::assertSame('99', $report->failedReasonCode());
        self::assertSame('İşlem başarısız: Teknik entegrasyon hatası.', $report->failedReasonMessage());
    }

    public function testAnInstallmentPaymentReportsATotalAboveTheOrderAmount(): void
    {
        // The customer hands the bank more than the store is owed; both figures arrive.
        $report = (new PaytrCallbackParser())->parse($this->body([
            'merchant_oid' => self::ORDER_REFERENCE,
            'status' => 'success',
            'payment_amount' => '159990',
            'total_amount' => '175989',
            'hash' => self::HASH,
        ]));

        self::assertNotNull($report);
        self::assertSame(159_990, $report->paymentAmountMinor());
        self::assertSame(175_989, $report->totalAmountMinor());
    }

    public function testOptionalFieldsStayNullWhenTheProviderOmitsThem(): void
    {
        $report = (new PaytrCallbackParser())->parse($this->body([
            'merchant_oid' => self::ORDER_REFERENCE,
            'status' => 'success',
            'total_amount' => '159990',
            'hash' => self::HASH,
        ]));

        self::assertNotNull($report);
        self::assertNull($report->paymentAmountMinor());
        self::assertNull($report->paymentType());
        self::assertNull($report->currency());
        self::assertNull($report->failedReasonCode());
        self::assertFalse($report->isTestMode());
    }

    /** @param array<string, string> $fields */
    #[DataProvider('unusableNotifications')]
    public function testANotificationMissingSomethingRequiredIsRefused(array $fields): void
    {
        self::assertNull((new PaytrCallbackParser())->parse($this->body($fields)));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function unusableNotifications(): iterable
    {
        $valid = [
            'merchant_oid' => self::ORDER_REFERENCE,
            'status' => 'success',
            'total_amount' => '159990',
            'hash' => self::HASH,
        ];

        yield 'no order number' => [array_diff_key($valid, ['merchant_oid' => null])];
        yield 'blank order number' => [['merchant_oid' => '  '] + $valid];
        yield 'an order number that was never sent' => [['merchant_oid' => 'EOA-20260925-ABCDEF123456'] + $valid];
        yield 'no status' => [array_diff_key($valid, ['status' => null])];
        yield 'an unknown status' => [['status' => 'maybe'] + $valid];
        yield 'no total' => [array_diff_key($valid, ['total_amount' => null])];
        yield 'a decimal total' => [['total_amount' => '159.90'] + $valid];
        yield 'a negative total' => [['total_amount' => '-159990'] + $valid];
        yield 'no hash' => [array_diff_key($valid, ['hash' => null])];
    }

    public function testAnEmptyBodyIsRefused(): void
    {
        self::assertNull((new PaytrCallbackParser())->parse(''));
    }

    public function testAJsonBodyIsRefusedRatherThanPartlyRead(): void
    {
        self::assertNull((new PaytrCallbackParser())->parse('{"status":"success"}'));
    }

    public function testABracketedFieldIsRefusedRatherThanFlattened(): void
    {
        $body = 'merchant_oid[0]=abc&status=success&total_amount=159990&hash='.self::HASH;

        self::assertNull((new PaytrCallbackParser())->parse($body));
    }

    public function testAnOverlongOrderNumberIsRefused(): void
    {
        $report = (new PaytrCallbackParser())->parse($this->body([
            'merchant_oid' => str_repeat('A', 65),
            'status' => 'success',
            'total_amount' => '159990',
            'hash' => self::HASH,
        ]));

        self::assertNull($report);
    }

    public function testTheStatusIsKeptExactlyAsSentBecauseTheHashCoversIt(): void
    {
        $report = (new PaytrCallbackParser())->parse($this->body([
            'merchant_oid' => self::ORDER_REFERENCE,
            'status' => 'Success',
            'total_amount' => '159990',
            'hash' => self::HASH,
        ]));

        self::assertNotNull($report);
        self::assertSame('Success', $report->status());
        self::assertTrue($report->isSuccess());
    }

    public function testTheHashIsExposedForVerificationAgainstTheRawValues(): void
    {
        $report = (new PaytrCallbackParser())->parse($this->body([
            'merchant_oid' => self::ORDER_REFERENCE,
            'status' => 'success',
            'total_amount' => '159990',
            'hash' => self::HASH,
        ]));

        self::assertNotNull($report);
        self::assertTrue((new PaytrSignature('testKey', 'testSalt'))->callbackHashMatches(
            $report->merchantOid(),
            $report->status(),
            $report->totalAmountAsSent(),
            $report->hash(),
        ));
    }

    /**
     * @param array<string, string> $fields
     */
    private function body(array $fields): string
    {
        return http_build_query($fields, '', '&', \PHP_QUERY_RFC1738);
    }
}
