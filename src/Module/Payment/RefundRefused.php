<?php

declare(strict_types=1);

namespace App\Module\Payment;

use App\Entity\Commerce\PaymentRefund;

/**
 * The provider refused a refund. The attempt is recorded as failed before this is thrown, so
 * the refusal survives in the payment history while the caller is still told it did not happen.
 */
final class RefundRefused extends \DomainException
{
    public function __construct(private readonly PaymentRefund $refund)
    {
        parent::__construct(sprintf(
            'The payment provider did not complete the refund (%s).',
            $refund->failure()?->code() ?? 'unknown_error',
        ));
    }

    public function refund(): PaymentRefund
    {
        return $this->refund;
    }
}
