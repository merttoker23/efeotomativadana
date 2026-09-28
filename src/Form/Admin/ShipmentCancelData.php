<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form model for the one reason an administrator may recall a parcel.
 *
 * A recall is audited, so the reason is mandatory context rather than something an operator may
 * skip. Nullable so an empty submission is a visible form error instead of a TypeError while the
 * request is mapped onto the model.
 */
final class ShipmentCancelData
{
    #[Assert\NotBlank(message: 'İptal nedeni zorunludur.')]
    #[Assert\Length(max: 500, maxMessage: 'İptal nedeni en fazla 500 karakter olabilir.')]
    public ?string $reason = null;
}
