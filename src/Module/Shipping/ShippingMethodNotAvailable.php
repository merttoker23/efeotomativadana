<?php

declare(strict_types=1);

namespace App\Module\Shipping;

/**
 * The order named a delivery service the store cannot perform.
 *
 * A configuration problem rather than a delivery problem: no carrier is involved yet, because the
 * store does not know who would carry the parcel. Refusing loudly is the point — quietly falling
 * back to some other service would ship an order on terms the customer never chose.
 */
final class ShippingMethodNotAvailable extends \RuntimeException
{
}
