<?php

declare(strict_types=1);

namespace App\Module\Loyalty;

use Symfony\Component\Validator\Constraints as Assert;

final class RewardAdjustmentData
{
    #[Assert\NotEqualTo(value: 0)]
    #[Assert\Range(min: -1_000_000_000, max: 1_000_000_000)]
    public int $points = 0;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $reason = '';

    #[Assert\Regex(pattern: '/^[a-zA-Z0-9_-]{1,64}$/D')]
    public string $requestKey;

    public function __construct()
    {
        $this->requestKey = bin2hex(random_bytes(16));
    }
}
