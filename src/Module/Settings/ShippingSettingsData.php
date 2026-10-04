<?php

namespace App\Module\Settings;

use Symfony\Component\Validator\Constraints as Assert;

final class ShippingSettingsData
{
    public function __construct(
        #[Assert\Length(max: 100)]
        #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9._-]*$/')]
        public ?string $shippingProvider = null,
        #[Assert\Range(min: 0, max: 999_999_999)]
        public int $shippingFee = 25_000,
        #[Assert\Range(min: 0, max: 999_999_999)]
        public int $freeShippingThreshold = 150_000,
    ) {
    }
}
