<?php

declare(strict_types=1);

namespace App\Tests\Contract\Payment\PayTR;

use App\Module\Payment\Gateway\GatewayRefundInstruction;
use App\Module\Payment\Gateway\IncomingPaymentCallback;
use App\Module\Payment\Gateway\RefundStatus;
use App\Module\Payment\Gateway\PayTR\PaytrCallbackParser;
use App\Module\Payment\Gateway\PayTR\PaytrClientIp;
use App\Module\Payment\Gateway\PayTR\PaytrConfiguration;
use App\Module\Payment\Gateway\PayTR\PaytrPaymentGateway;
use App\Module\Payment\Gateway\PayTR\PaytrSignature;
use App\Shared\Money\Money;
use App\Tests\Fixtures\Payment\PaymentRouterFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The PayTR adapter's notification and refund paths, against the provider's documented contract.
 *
 * Initiation is covered by PaytrDirectApiFormTest, because the Direct API has no server-side call
 * to make. What is left here is everything that does talk to PayTR: verifying a notification, and
 * asking for a refund. Every response is a deterministic mock, so the suite never touches the
 * network and never needs live credentials.
 */
final class PaytrNotificationAndRefundTest extends TestCase
{
    private const string MERCHANT_ID = '123456';
    private const string MERCHANT_KEY = 'testKey';
    private const string MERCHANT_SALT = 'testSalt';
    private const string ORDER_REFERENCE = 'EOA20260925ABCDEF123456A1';
    private const string REFUND_URL = 'https://www.paytr.com/odeme/iade';
    private const string CUSTOMER_IP = '88.99.140.12';

    public function testAValidNotificationIsAcceptedAsACaptureAtTheSignedTotal(): void
    {
        $authentication = $this->gateway()->authenticateCallback($this->notification());

        self::assertTrue($authentication->verified());
        self::assertSame(self::ORDER_REFERENCE, $authentication->providerReference());
        self::assertSame('succeeded', $authentication->outcome());

        $captured = $authentication->capturedAmount();
        self::assertNotNull($captured);
        self::assertSame(159_990, $captured->minorAmount());
        self::assertSame('TRY', $captured->currency());
    }

    public function testAnInstalmentPaymentIsAcceptedAtTheLargerSignedTotal(): void
    {
        // The customer hands the bank more than the order is worth. That is not an error, and the
        // signed figure is what proves it, so it must not be refused here.
        $authentication = $this->gateway()->authenticateCallback($this->notification([
            'total_amount' => '175990',
        ]));

        self::assertTrue($authentication->verified());

        $captured = $authentication->capturedAmount();
        self::assertNotNull($captured);
        self::assertSame(175_990, $captured->minorAmount());
    }

    public function testAFailedNotificationIsAcceptedAsAFailureWithNoCapturedAmount(): void
    {
        $authentication = $this->gateway()->authenticateCallback($this->notification([
            'status' => 'failed',
            // PayTR documents a total of zero on a failed transaction.
            'total_amount' => '0',
            'failed_reason_code' => '0',
            'failed_reason_msg' => 'KART HATASI',
        ]));

        self::assertTrue($authentication->verified());
        self::assertSame('failed', $authentication->outcome());
        self::assertNull($authentication->capturedAmount());
    }

    public function testAnUnsignedNotificationIsRejected(): void
    {
        $authentication = $this->gateway()->authenticateCallback($this->notification([
            'hash' => 'a-forged-hash',
        ]));

        self::assertFalse($authentication->verified());
        self::assertSame('signature_mismatch', $authentication->reason());
    }

    public function testANotificationWhoseStatusWasSwappedAfterSigningIsRejected(): void
    {
        $tampered = str_replace('status=success', 'status=failed', $this->notificationBody());

        $authentication = $this->gateway()->authenticateCallback(new IncomingPaymentCallback($tampered, [], []));

        self::assertFalse($authentication->verified());
        self::assertSame('signature_mismatch', $authentication->reason());
    }

    public function testANotificationWhoseAmountWasSwappedAfterSigningIsRejected(): void
    {
        // total_amount is inside the signed set, so lowering it must break verification rather
        // than quietly book a smaller capture.
        $tampered = str_replace('total_amount=159990', 'total_amount=1', $this->notificationBody());

        $authentication = $this->gateway()->authenticateCallback(new IncomingPaymentCallback($tampered, [], []));

        self::assertFalse($authentication->verified());
        self::assertSame('signature_mismatch', $authentication->reason());
    }

    public function testAnUnsignedFieldCannotLowerTheFigureThatIsBooked(): void
    {
        // payment_amount sits outside the signed set, so it is never what the store books: the
        // signed total is. Lowering it changes nothing that is recorded.
        $authentication = $this->gateway()->authenticateCallback($this->notification([
            'payment_amount' => '1',
        ]));

        self::assertTrue($authentication->verified());

        $captured = $authentication->capturedAmount();
        self::assertNotNull($captured);
        self::assertSame(159_990, $captured->minorAmount(), 'The signed total decides, not an unsigned field.');
    }

    public function testACaptureOfNothingIsRejected(): void
    {
        $authentication = $this->gateway()->authenticateCallback($this->notification([
            'total_amount' => '0',
            'payment_amount' => '0',
        ]));

        self::assertFalse($authentication->verified());
        self::assertSame('empty_capture', $authentication->reason());
    }

    public function testATotalBelowTheOrderAmountIsRejectedAsInconsistent(): void
    {
        $authentication = $this->gateway()->authenticateCallback($this->notification([
            'total_amount' => '100',
        ]));

        self::assertFalse($authentication->verified());
        self::assertSame('inconsistent_amount', $authentication->reason());
    }

    public function testACaptureInACurrencyPaytrDoesNotDocumentIsRejected(): void
    {
        $authentication = $this->gateway()->authenticateCallback($this->notification([
            'currency' => 'JPY',
        ]));

        self::assertFalse($authentication->verified());
        self::assertSame('unknown_currency', $authentication->reason());
    }

    public function testASimulatedCaptureIsRefusedWhileTheStoreIsInLiveMode(): void
    {
        // A test transaction reported while the store takes real money must never confirm a real
        // order, however well signed it is.
        $authentication = $this->gateway(testMode: false)->authenticateCallback($this->notification([
            'test_mode' => '1',
        ]));

        self::assertFalse($authentication->verified());
        self::assertSame('test_capture_in_live_mode', $authentication->reason());
    }

    public function testASimulatedCaptureIsAcceptedWhileTheStoreIsInTestMode(): void
    {
        $authentication = $this->gateway(testMode: true)->authenticateCallback($this->notification([
            'test_mode' => '1',
        ]));

        self::assertTrue($authentication->verified());
    }

    #[DataProvider('unusableNotifications')]
    public function testSomethingThatIsNotAWellFormedPaytrPostIsRejected(string $body): void
    {
        $authentication = $this->gateway()->authenticateCallback(new IncomingPaymentCallback($body, [], []));

        self::assertFalse($authentication->verified());
        self::assertSame('malformed_notification', $authentication->reason());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableNotifications(): iterable
    {
        yield 'empty' => [''];
        yield 'not a form post' => ['{"status":"success"}'];
        yield 'no order number' => ['status=success&total_amount=159990&hash=abc'];
        yield 'no hash' => ['merchant_oid='.self::ORDER_REFERENCE.'&status=success&total_amount=159990'];
        yield 'a bracketed field' => ['merchant_oid[0]=abc&status=success&total_amount=159990&hash=abc'];
        yield 'a decimal total' => ['merchant_oid='.self::ORDER_REFERENCE.'&status=success&total_amount=1599.90&hash=abc'];
    }

    public function testAFieldThatIsNotValidUtf8IsDroppedRatherThanBreakingRedaction(): void
    {
        // Provider text that is not valid UTF-8 would make the redaction patterns fail and turn
        // into a warning-as-exception on a public endpoint. Dropping just that optional field
        // keeps the endpoint answering, and the signature still decides the outcome on its own.
        $report = (new PaytrCallbackParser())->parse('merchant_oid='.self::ORDER_REFERENCE
            .'&status=success&total_amount=159990&failed_reason_msg=%FF%FE&hash=abc');

        self::assertNotNull($report);
        self::assertNull($report->failedReasonMessage());
    }

    public function testAMalformedOptionalFieldNeverStopsTheNotificationBeingVerified(): void
    {
        $body = $this->notificationBody(['failed_reason_msg' => null]);

        $authentication = $this->gateway()->authenticateCallback(new IncomingPaymentCallback($body, [], []));

        self::assertTrue($authentication->verified());
    }

    public function testVeryLongProviderTextIsTruncatedBeforeItCanReachStorage(): void
    {
        $body = $this->notificationBody(['failed_reason_msg' => str_repeat('x', 5000)]);

        $report = (new PaytrCallbackParser())->parse($body);

        self::assertNotNull($report);
        self::assertLessThanOrEqual(500, mb_strlen((string) $report->failedReasonMessage()));
    }

    public function testARefundPostsTheAmountAsADecimalString(): void
    {
        $body = $this->captureRefundRequest(Money::ofMinor(159_990, 'TRY'));

        self::assertSame('1599.90', $body['return_amount']);
        self::assertSame(self::ORDER_REFERENCE, $body['merchant_oid']);
        self::assertSame(self::MERCHANT_ID, $body['merchant_id']);
    }

    public function testTheRefundRequestIsSignedTheWayPaytrVerifiesIt(): void
    {
        $body = $this->captureRefundRequest(Money::ofMinor(159_990, 'TRY'));

        $expected = base64_encode(hash_hmac(
            'sha256',
            self::MERCHANT_ID.self::ORDER_REFERENCE.'1599.90'.self::MERCHANT_SALT,
            self::MERCHANT_KEY,
            true,
        ));

        self::assertSame($expected, $body['paytr_token']);
    }

    public function testAPartialRefundSendsOnlyTheRefundedAmount(): void
    {
        self::assertSame('50.00', $this->captureRefundRequest(Money::ofMinor(5_000, 'TRY'))['return_amount']);
    }

    public function testASuccessfulRefundIsReportedWithTheOrderReference(): void
    {
        $outcome = $this->gateway($this->refundClient())->refund($this->refundInstruction());

        self::assertSame(RefundStatus::Completed, $outcome->status());
        self::assertSame(self::ORDER_REFERENCE, $outcome->providerReference());
    }

    public function testARefusalFromPaytrIsRecordedRatherThanSwallowed(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'status' => 'error',
            'err_no' => '006',
            'err_msg' => 'Toplam iade tutarı odeme tutarindan fazla olamaz',
        ], \JSON_THROW_ON_ERROR)));

        $outcome = $this->gateway($client)->refund($this->refundInstruction());

        self::assertSame(RefundStatus::Failed, $outcome->status());
        self::assertFalse($outcome->failure()->isRetryable());
        self::assertStringContainsString('fazla olamaz', $outcome->failure()->message());
    }

    public function testATransportFailureDuringRefundIsRetryableAndRecorded(): void
    {
        $outcome = $this->gateway($this->failingTransport())->refund($this->refundInstruction());

        self::assertSame(RefundStatus::Failed, $outcome->status());
        self::assertTrue($outcome->failure()->isRetryable());
    }

    public function testARefundWhoseBodyStallsMidStreamIsRetryableRatherThanFatal(): void
    {
        // Headers arrive and then the body never finishes, which surfaces as a timeout while
        // reading rather than as a decoding error. It must not escape as an exception.
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('Connection reset while reading the response');
        });

        $outcome = $this->gateway($client)->refund($this->refundInstruction());

        self::assertSame(RefundStatus::Failed, $outcome->status());
        self::assertTrue($outcome->failure()->isRetryable());
    }

    public function testACurrencyPaytrCannotExpressIsARefusalRatherThanAnError(): void
    {
        $outcome = $this->gateway()->refund($this->refundInstruction(Money::ofMinor(1_000, 'JPY')));

        self::assertSame(RefundStatus::Failed, $outcome->status());
        self::assertSame('unsupported_refund_amount', $outcome->failure()?->code());
    }

    public function testAStoreWithoutCredentialsRefusesToRefund(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \LogicException('An unconfigured store must not reach PayTR.');
        });

        $outcome = $this->gateway($client, configured: false)->refund($this->refundInstruction());

        self::assertSame(RefundStatus::Failed, $outcome->status());
    }

    public function testThereIsNoAuthorizationToReleaseBecausePaytrCapturesImmediately(): void
    {
        self::assertNull($this->gateway()->releaseAuthorization($this->refundInstruction()));
    }

    private function gateway(?MockHttpClient $client = null, bool $configured = true, bool $testMode = true): PaytrPaymentGateway
    {
        $stack = new RequestStack();
        $stack->push(Request::create('https://store.test/odeme', 'POST', server: ['REMOTE_ADDR' => self::CUSTOMER_IP]));

        return new PaytrPaymentGateway(
            $client ?? new MockHttpClient(new MockResponse('{}')),
            PaytrConfiguration::fromEnvironment(
                $configured ? self::MERCHANT_ID : '',
                $configured ? self::MERCHANT_KEY : '',
                $configured ? self::MERCHANT_SALT : '',
                $testMode ? '1' : '0',
                'https://www.paytr.com/odeme',
                self::REFUND_URL,
            ),
            new PaytrCallbackParser(),
            new PaytrClientIp($stack),
            new NullLogger(),
        );
    }

    private function refundInstruction(?Money $amount = null): GatewayRefundInstruction
    {
        return new GatewayRefundInstruction(
            self::ORDER_REFERENCE,
            $amount ?? Money::ofMinor(159_990, 'TRY'),
            'refund-key-1',
            'customer request',
        );
    }

    /** @return array<string, string> */
    private function captureRefundRequest(?Money $amount = null): array
    {
        $body = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$body): MockResponse {
            parse_str((string) $options['body'], $body);

            return $this->refundResponse();
        });

        $this->gateway($client)->refund($this->refundInstruction($amount));

        self::assertNotSame([], $body, 'The refund request body was never captured.');

        return $body;
    }

    private function refundClient(): MockHttpClient
    {
        return new MockHttpClient($this->refundResponse());
    }

    private function refundResponse(): MockResponse
    {
        return new MockResponse(json_encode([
            'status' => 'success',
            'is_test' => 1,
            'merchant_oid' => self::ORDER_REFERENCE,
            'return_amount' => '1599.90',
        ], \JSON_THROW_ON_ERROR));
    }

    private function failingTransport(): MockHttpClient
    {
        return new MockHttpClient(static function (): never {
            throw new TransportException('Connection timed out');
        });
    }

    /** @param array<string, string|null> $overrides */
    private function notification(array $overrides = []): IncomingPaymentCallback
    {
        return new IncomingPaymentCallback($this->notificationBody($overrides), [], []);
    }

    /**
     * A notification signed the way PayTR signs it, so these tests exercise verification rather
     * than the parser's willingness to read a body.
     *
     * @param array<string, string|null> $overrides
     */
    private function notificationBody(array $overrides = []): string
    {
        $fields = array_merge([
            'merchant_oid' => self::ORDER_REFERENCE,
            'status' => 'success',
            'total_amount' => '159990',
            'payment_amount' => '159990',
            'currency' => 'TL',
            'payment_type' => 'card',
            'installment_count' => '0',
            'test_mode' => '1',
        ], $overrides);

        foreach ($fields as $name => $value) {
            if (null === $value) {
                unset($fields[$name]);
            }
        }

        if (!\array_key_exists('hash', $overrides)) {
            $signature = new PaytrSignature(self::MERCHANT_KEY, self::MERCHANT_SALT);
            $fields['hash'] = $signature->callbackHash(
                (string) $fields['merchant_oid'],
                (string) $fields['status'],
                (string) $fields['total_amount'],
            );
        }

        return http_build_query($fields, '', '&', \PHP_QUERY_RFC1738);
    }
}
