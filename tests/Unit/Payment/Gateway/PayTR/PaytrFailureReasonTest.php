<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Gateway\PayTR;

use App\Module\Payment\Gateway\PayTR\PaytrFailureReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PayTR's documented notification failure codes, mapped onto the store's own taxonomy.
 *
 * The distinction that matters is retryable against permanent: code 99 is a technical error
 * PayTR itself retries, so treating it as a decline would cancel a payment that was about to
 * succeed. Everything else is the customer's card or the payment page, and retrying changes
 * nothing.
 */
final class PaytrFailureReasonTest extends TestCase
{
    #[DataProvider('documentedCodes')]
    public function testEachDocumentedCodeBecomesAStableMachineReadableCode(string $paytrCode, string $expected): void
    {
        $failure = PaytrFailureReason::fromNotification($paytrCode, 'KART HATASI');

        self::assertSame($expected, $failure->code());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documentedCodes(): iterable
    {
        yield '0 generic decline' => ['0', 'card_declined'];
        yield '1 no phone entered for authentication' => ['1', 'authentication_required'];
        yield '2 wrong authentication code' => ['2', 'authentication_failed'];
        yield '3 security check not passed' => ['3', 'security_check_failed'];
        yield '6 customer left the payment page' => ['6', 'customer_abandoned'];
        yield '8 installments not available for this card' => ['8', 'installment_not_available'];
        yield '9 not authorised for this card' => ['9', 'card_not_permitted'];
        yield '10 three d secure required' => ['10', 'three_d_secure_required'];
        yield '11 suspected fraud' => ['11', 'suspected_fraud'];
    }

    public function testTheProvidersTechnicalErrorIsRetryable(): void
    {
        $failure = PaytrFailureReason::fromNotification('99', 'İşlem başarısız: Teknik entegrasyon hatası.');

        self::assertSame('internal_error', $failure->code());
        self::assertTrue($failure->isRetryable());
    }

    #[DataProvider('paytrCodes')]
    public function testEveryCustomerFacingReasonIsPermanentAndOnlyTheTechnicalOneRetries(string $paytrCode): void
    {
        $failure = PaytrFailureReason::fromNotification($paytrCode, 'KART HATASI');

        self::assertSame('99' === $paytrCode, $failure->isRetryable());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paytrCodes(): iterable
    {
        foreach (self::documentedCodes() as $label => $case) {
            yield $label => [$case[0]];
        }
    }

    public function testAnUnknownCodeFallsBackToAGenericDecline(): void
    {
        $failure = PaytrFailureReason::fromNotification('4242', 'Bilinmeyen hata');

        self::assertSame('payment_failed', $failure->code());
        self::assertFalse($failure->isRetryable());
    }

    public function testAMissingCodeFallsBackToAGenericDecline(): void
    {
        $failure = PaytrFailureReason::fromNotification(null, 'Bilinmeyen hata');

        self::assertSame('payment_failed', $failure->code());
    }

    public function testTheProviderMessageIsKeptForTheOperators(): void
    {
        $failure = PaytrFailureReason::fromNotification('0', 'Müşteri kartı ile işlem yapmaktan vazgeçti.');

        self::assertSame('Müşteri kartı ile işlem yapmaktan vazgeçti.', $failure->message());
    }

    public function testACardNumberInTheProviderMessageNeverReachesStorage(): void
    {
        $failure = PaytrFailureReason::fromNotification('0', 'Declined 4111111111111111');

        self::assertStringNotContainsString('4111111111111111', $failure->message());
    }

    public function testAnEmptyMessageStillProducesSomethingAnOperatorCanRead(): void
    {
        $failure = PaytrFailureReason::fromNotification('0', null);

        self::assertNotSame('', $failure->message());
    }
}
