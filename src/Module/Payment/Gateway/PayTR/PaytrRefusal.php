<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway\PayTR;

/**
 * A request this store cannot ask PayTR to charge, carrying the reason code to record.
 *
 * Kept apart from a plain invalid-argument error so the failure an administrator reads names the
 * actual problem — a missing customer address is not the same thing as a currency PayTR cannot
 * express — without every intermediate step having to return a result object.
 */
final class PaytrRefusal extends \InvalidArgumentException
{
    public function __construct(private readonly string $failureCode, string $message)
    {
        parent::__construct($message);
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }
}
