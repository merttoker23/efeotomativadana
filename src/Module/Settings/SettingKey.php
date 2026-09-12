<?php

namespace App\Module\Settings;

enum SettingKey: string
{
    case B2bEnabled = 'commerce.b2b_enabled';
    case B2bProvider = 'commerce.b2b_provider';
    case StoreName = 'store.name';
    case StoreCurrency = 'store.currency';
    case StoreDefaultLocale = 'store.default_locale';
    case StoreDefaultTaxRate = 'store.default_tax_rate';
    case LoyaltyEnabled = 'loyalty.enabled';
    case LoyaltyEarnPercentage = 'loyalty.earn_percentage';
    case PaymentProvider = 'payment.provider';
    case ShippingProvider = 'shipping.provider';

    public function defaultValue(): bool|int|string|null
    {
        return match ($this) {
            self::B2bEnabled, self::LoyaltyEnabled => false,
            self::B2bProvider, self::PaymentProvider, self::ShippingProvider => null,
            self::StoreName => 'Efe Otomotiv Adana',
            self::StoreCurrency => 'TRY',
            self::StoreDefaultLocale => 'tr',
            self::StoreDefaultTaxRate => 20,
            self::LoyaltyEarnPercentage => 1,
        };
    }
}
