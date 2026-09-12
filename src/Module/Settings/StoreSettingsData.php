<?php

namespace App\Module\Settings;

use Symfony\Component\Validator\Constraints as Assert;

final class StoreSettingsData
{
    public function __construct(
        public bool $b2bEnabled = false,
        #[Assert\Length(max: 100)]
        #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9._-]*$/', message: 'Use lowercase letters, numbers, dots, underscores, or dashes.')]
        public ?string $b2bProvider = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 160)]
        public string $storeName = 'Efe Otomotiv Adana',
        #[Assert\Currency]
        public string $currency = 'TRY',
        #[Assert\Locale]
        public string $defaultLocale = 'tr',
        #[Assert\Range(min: 0, max: 100)]
        public int $defaultTaxRate = 20,
        public bool $loyaltyEnabled = false,
        #[Assert\Range(min: 0, max: 100)]
        public int $loyaltyEarnPercentage = 1,
        #[Assert\Length(max: 100)]
        #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9._-]*$/', message: 'Use lowercase letters, numbers, dots, underscores, or dashes.')]
        public ?string $paymentProvider = null,
        #[Assert\Length(max: 100)]
        #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9._-]*$/', message: 'Use lowercase letters, numbers, dots, underscores, or dashes.')]
        public ?string $shippingProvider = null,
    ) {
    }
}
