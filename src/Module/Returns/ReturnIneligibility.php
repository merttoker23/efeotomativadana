<?php

declare(strict_types=1);

namespace App\Module\Returns;

/**
 * Why an order cannot be returned, in the customer's own language.
 *
 * The storefront shows one of these instead of a refusal, because "this order cannot be returned"
 * with no reason is the answer that produces a support call. Each message is deliberately about the
 * *order* and never about a product, a price or another customer.
 */
enum ReturnIneligibility: string
{
    /** The order exists but the store has not accepted the customer's money for it yet. */
    case OrderNotSettled = 'order_not_settled';
    /** Too long since the order was placed. */
    case ReturnWindowExpired = 'return_window_expired';
    /** The order was cancelled, so there is nothing to send back. */
    case OrderCancelled = 'order_cancelled';
    /** Every line of the order has already been claimed by an open return. */
    case NothingLeftToReturn = 'nothing_left_to_return';

    public function customerMessage(): string
    {
        return match ($this) {
            ReturnIneligibility::OrderNotSettled => 'Sipariş henüz ödemeniz onaylanmadı.',
            ReturnIneligibility::ReturnWindowExpired => 'İade süresi doldu. Bu sipariş artık iade edilemez.',
            ReturnIneligibility::OrderCancelled => 'Bu sipariş iptal edildiği için iade alınamaz.',
            ReturnIneligibility::NothingLeftToReturn => 'Bu siparişin tüm ürünleri için iade talebi oluşturulmuş.',
        };
    }
}
