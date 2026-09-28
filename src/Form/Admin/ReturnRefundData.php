<?php

declare(strict_types=1);

namespace App\Form\Admin;

/**
 * The refund a payment service already issued, filed against a return.
 *
 * Amount in minor units and an explicit provider reference, because a return module must never be
 * the thing that moves money: it records what {@see \App\Module\Payment\PaymentRefundService} did,
 * and a reference is what makes that record checkable against the provider afterwards.
 */
final class ReturnRefundData
{
    public string $amountMinor = '';
    public string $currency = 'TRY';
    public string $refundReference = '';
}
