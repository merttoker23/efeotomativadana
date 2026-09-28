<?php

declare(strict_types=1);

namespace App\Module\Shipping;

/**
 * The configured carrier is unusable: not installed, or not permitted here.
 *
 * Distinct from a carrier that answered and said no. This one means the store's own
 * configuration cannot be honoured, which is an operator problem rather than a delivery problem.
 */
final class ShippingProviderNotAvailable extends \RuntimeException
{
}
