<?php

declare(strict_types=1);

namespace App\Module\Returns;

use App\Entity\Commerce\ReturnRequest;

/**
 * How each return state is worded for a customer.
 *
 * Separate from the enum for the same reason as {@see \App\Module\Order\OrderStateLabel}: the enum's
 * values are stored in a column and compared in queries, so its wording has to stay machine-shaped
 * forever while the storefront's Turkish can be reworded freely.
 */
final readonly class ReturnStateLabel
{
    public static function for(ReturnState $state): string
    {
        return match ($state) {
            ReturnState::Requested => 'Talebiniz inceleniyor',
            ReturnState::Approved => 'Onaylandı',
            ReturnState::Received => 'Ürünler bize ulaştı',
            ReturnState::Refunded => 'İade tamamlandı',
            ReturnState::Rejected => 'Reddedildi',
            ReturnState::Withdrawn => 'Geri alındı',
        };
    }

    /** What the customer is told to do next, or null when there is nothing to do. */
    public static function nextStepFor(ReturnRequest $return): ?string
    {
        return match ($return->state()) {
            ReturnState::Requested => 'Talebiniz incelendikten sonra sonucu e-posta ile bildireceğiz.',
            ReturnState::Approved => 'Ürünü iade etmek için bizimle iletişime geçin.',
            ReturnState::Received => 'Ücret iadesi için son adımı tamamlıyoruz.',
            ReturnState::Refunded, ReturnState::Rejected, ReturnState::Withdrawn => null,
        };
    }
}
