<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form model for handing a parcel to the store's own driver.
 *
 * The tracking number is optional because a hand-delivered parcel may genuinely have none, and a
 * required field would push operators towards writing a placeholder into the one field a customer
 * later reads.
 */
final class ShipmentHandOverData
{
    #[Assert\Length(max: 120, maxMessage: 'Takip numarası en fazla 120 karakter olabilir.')]
    public ?string $trackingNumber = null;
}
