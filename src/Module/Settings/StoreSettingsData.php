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
        public bool $seoIndexingEnabled = true,
        // Nullable on purpose: an empty default description is a legitimate state, and the SEO
        // layer has its own final fallback. Inventing a sentence the merchant did not write is
        // not this application's job.
        #[Assert\Length(max: 500)]
        public ?string $seoDefaultDescription = null,
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/\A#[0-9a-fA-F]{6}\z/', message: 'Renk #RRGGBB biçiminde olmalıdır.')]
        public string $storefrontNotice = '#071e3c',
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/\A#[0-9a-fA-F]{6}\z/', message: 'Renk #RRGGBB biçiminde olmalıdır.')]
        public string $storefrontNavy = '#092a53',
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/\A#[0-9a-fA-F]{6}\z/', message: 'Renk #RRGGBB biçiminde olmalıdır.')]
        public string $storefrontNavyLight = '#123d70',
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/\A#[0-9a-fA-F]{6}\z/', message: 'Renk #RRGGBB biçiminde olmalıdır.')]
        public string $storefrontYellow = '#fed243',
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/\A#[0-9a-fA-F]{6}\z/', message: 'Renk #RRGGBB biçiminde olmalıdır.')]
        public string $storefrontBody = '#ebebf0',
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/\A#[0-9a-fA-F]{6}\z/', message: 'Renk #RRGGBB biçiminde olmalıdır.')]
        public string $storefrontCard = '#ffffff',
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/\A#[0-9a-fA-F]{6}\z/', message: 'Renk #RRGGBB biçiminde olmalıdır.')]
        public string $storefrontInk = '#171c22',
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/\A#[0-9a-fA-F]{6}\z/', message: 'Renk #RRGGBB biçiminde olmalıdır.')]
        public string $storefrontMuted = '#69717a',
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/\A#[0-9a-fA-F]{6}\z/', message: 'Renk #RRGGBB biçiminde olmalıdır.')]
        public string $storefrontLine = '#e1e3e6',
        #[Assert\Regex(pattern: Ga4MeasurementId::PATTERN, message: 'Geçerli bir GA4 Measurement ID girin (ör. G-XXXXXXXXXX).')]
        public ?string $ga4MeasurementId = null,
        #[Assert\Range(min: 0, max: 999_999_999)]
        public int $shippingFee = 25_000,
        #[Assert\Range(min: 0, max: 999_999_999)]
        public int $freeShippingThreshold = 150_000,
        #[Assert\Email]
        #[Assert\Length(max: 254)]
        public ?string $contactEmail = null,
        // Optional because a store may genuinely have no landline published yet; what is not
        // optional is that a stored number is a real one, which StorePhone decides.
        #[Assert\Length(max: StorePhone::MAX_INPUT_LENGTH)]
        public ?string $phone = null,
        // İl and ilçe are validated against the local Türkiye catalog rather than by shape:
        // "Adaa" is a perfectly well-formed string and a province that does not exist.
        #[Assert\Length(max: 100)]
        public ?string $city = null,
        #[Assert\Length(max: 100)]
        public ?string $district = null,
    ) {
    }
}
