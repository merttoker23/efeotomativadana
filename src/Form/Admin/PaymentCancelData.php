<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/** Form model for the single reason an administrator may cancel an uncaptured payment. */
final class PaymentCancelData
{
    /**
     * Nullable so an empty submission is a form error the operator can see, rather than a
     * TypeError while mapping the request onto the model.
     */
    #[Assert\NotBlank(message: 'İptal nedeni zorunludur.')]
    #[Assert\Length(max: 255, maxMessage: 'İptal nedeni en fazla 255 karakter olabilir.')]
    public ?string $reason = null;
}
