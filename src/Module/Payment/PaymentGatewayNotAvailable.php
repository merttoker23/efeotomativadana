<?php

declare(strict_types=1);

namespace App\Module\Payment;

/**
 * The store cannot settle a payment with the configured provider. Raised instead of falling
 * back silently, so a fake or test-only adapter can never quietly take real money in prod.
 */
final class PaymentGatewayNotAvailable extends \RuntimeException
{
}
