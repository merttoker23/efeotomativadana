<?php

declare(strict_types=1);

namespace App\Module\Shipping;

/**
 * The carrier still holds the parcel and refused to give it back.
 *
 * The shipment deliberately stays where it was. Telling the store a parcel was recalled when the
 * courier is still driving it is how a customer ends up being told their order was cancelled and
 * then receiving it anyway.
 */
final class ShipmentCancellationRefused extends \RuntimeException
{
}
