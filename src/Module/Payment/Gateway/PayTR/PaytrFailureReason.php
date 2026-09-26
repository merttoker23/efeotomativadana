<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway\PayTR;

use App\Module\Payment\SanitizedFailure;

/**
 * PayTR's documented notification failure codes, mapped onto the store's own taxonomy.
 *
 * The distinction that matters is retryable against permanent. Code 99 is a technical error
 * PayTR itself retries, so treating it as a decline would cancel a payment that was about to
 * succeed; every other code is the customer's card or the payment page, where retrying changes
 * nothing. The provider's own message is kept for the operators, after redaction.
 */
final class PaytrFailureReason
{
    private const array CODE_MAP = [
        '0' => 'card_declined',
        '1' => 'authentication_required',
        '2' => 'authentication_failed',
        '3' => 'security_check_failed',
        '6' => 'customer_abandoned',
        '8' => 'installment_not_available',
        '9' => 'card_not_permitted',
        '10' => 'three_d_secure_required',
        '11' => 'suspected_fraud',
        // The one code PayTR documents as a technical error it will retry itself. It is mapped
        // onto the store's retryable taxonomy so an operator is not told the customer gave up.
        '99' => 'internal_error',
    ];

    private const string FALLBACK_CODE = 'payment_failed';
    private const string FALLBACK_MESSAGE = 'The payment provider reported that the payment failed.';

    public static function fromNotification(?string $paytrCode, ?string $message): SanitizedFailure
    {
        $code = self::CODE_MAP[trim((string) $paytrCode)] ?? self::FALLBACK_CODE;

        $failure = SanitizedFailure::fromProvider($code, $message, self::FALLBACK_MESSAGE);
        if ('' === trim($message ?? '')) {
            return SanitizedFailure::fromProvider($code, self::FALLBACK_MESSAGE, null);
        }

        return $failure;
    }
}
