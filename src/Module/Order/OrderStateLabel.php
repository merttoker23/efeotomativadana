<?php

declare(strict_types=1);

namespace App\Module\Order;

/**
 * How each order state is worded for a customer.
 *
 * Separate from the enum on purpose. `OrderState` is compared against database rows and provider
 * payloads, so its values have to stay machine-shaped forever; the storefront's Turkish lives here
 * where it can be reworded without a schema change or a data migration.
 */
final readonly class OrderStateLabel
{
    public static function for(OrderState $state): string
    {
        return match ($state) {
            OrderState::Placed => 'Siparişiniz alındı, ödeme bekleniyor',
            OrderState::Confirmed => 'Hazırlanıyor',
            OrderState::Completed => 'Tamamlandı',
            OrderState::Cancelled => 'İptal edildi',
        };
    }
}
