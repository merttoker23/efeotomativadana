<?php

declare(strict_types=1);

namespace App\Module\Order;

use Symfony\Component\Validator\Constraints as Assert;

final class OrderTransitionData
{
    #[Assert\NotNull]
    public ?OrderState $nextState = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 500)]
    public string $reason = '';

    public string $version = '';
}
