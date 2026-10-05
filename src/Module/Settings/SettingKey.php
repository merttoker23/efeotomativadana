<?php

namespace App\Module\Settings;

enum SettingKey: string
{
    case B2bEnabled = 'commerce.b2b_enabled';
    case B2bProvider = 'commerce.b2b_provider';
    case StoreName = 'store.name';
    case ContactEmail = 'store.contact_email';
    case StorePhone = 'store.phone';
    case StoreCountry = 'store.country';
    case StoreCity = 'store.city';
    case StoreDistrict = 'store.district';
    case StoreCurrency = 'store.currency';
    case Ga4MeasurementId = 'analytics.ga4_measurement_id';
    case CookieScript = 'cookies.script';
    case StoreDefaultLocale = 'store.default_locale';
    case StoreDefaultTaxRate = 'store.default_tax_rate';
    case LoyaltyEnabled = 'loyalty.enabled';
    case LoyaltyEarnPercentage = 'loyalty.earn_percentage';
    case PaymentProvider = 'payment.provider';
    case ShippingProvider = 'shipping.provider';
    case ShippingFee = 'shipping.fee';
    case FreeShippingThreshold = 'shipping.free_threshold';
    // Indexing stays on by default: a store that has not launched should be switched off from
    // its own settings, not be invisible in production until someone remembers a code change.
    case SeoIndexingEnabled = 'seo.indexing_enabled';
    case SeoDefaultDescription = 'seo.default_description';
    case StorefrontNotice = 'storefront.color.notice';
    case StorefrontNavy = 'storefront.color.navy';
    case StorefrontNavyLight = 'storefront.color.navy-light';
    case StorefrontYellow = 'storefront.color.yellow';
    case StorefrontBody = 'storefront.color.body';
    case StorefrontCard = 'storefront.color.card';
    case StorefrontInk = 'storefront.color.ink';
    case StorefrontMuted = 'storefront.color.muted';
    case StorefrontLine = 'storefront.color.line';

    public function defaultValue(): bool|int|string|null
    {
        return match ($this) {
            self::B2bEnabled, self::LoyaltyEnabled => false,
            self::B2bProvider, self::PaymentProvider, self::ShippingProvider, self::SeoDefaultDescription, self::Ga4MeasurementId, self::ContactEmail, self::CookieScript, self::StorePhone, self::StoreCity, self::StoreDistrict => null,
            self::SeoIndexingEnabled => true,
            self::StoreName => 'Efe Otomotiv Adana',
            self::StoreCountry => 'Türkiye',
            self::StoreCurrency => 'TRY',
            self::StoreDefaultLocale => 'tr',
            self::StoreDefaultTaxRate => 20,
            self::LoyaltyEarnPercentage => 1,
            self::ShippingFee => 25_000,
            self::FreeShippingThreshold => 150_000,
            self::StorefrontNotice => '#071e3c',
            self::StorefrontNavy => '#092a53',
            self::StorefrontNavyLight => '#123d70',
            self::StorefrontYellow => '#fed243',
            self::StorefrontBody => '#ebebf0',
            self::StorefrontCard => '#ffffff',
            self::StorefrontInk => '#171c22',
            self::StorefrontMuted => '#69717a',
            self::StorefrontLine => '#e1e3e6',
        };
    }
}
