<?php

declare(strict_types=1);

namespace App\Module\Shipping;

/**
 * The carrier has not taken the parcel and the attempt should be made again.
 *
 * Retryable, unlike {@see ShippingProviderNotAvailable}: the parcel still exists and the store
 * still intends to send it. The caller raises this so Messenger's retry strategy applies, which is
 * why the shipment is left in a state where a second attempt is still allowed.
 */
final class ShipmentProviderRetryable extends \RuntimeException
{
}
