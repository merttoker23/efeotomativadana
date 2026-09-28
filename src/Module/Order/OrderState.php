<?php

declare(strict_types=1);

namespace App\Module\Order;

/**
 * Order lifecycle states.
 *
 * Four states and no more, because each one is a promise the store has to be able to keep. The
 * Turkish wording a customer screen needs lives on {@see OrderStateLabel} rather than here: this
 * enum is also compared against database and provider values, and it has no business knowing how
 * the storefront chooses to say something.
 */
enum OrderState: string
{
    /** Placed but not yet paid. The stock is reserved; the money has not arrived. */
    case Placed = 'placed';
    /** Paid and accepted. This is the only state a parcel can be created from. */
    case Confirmed = 'confirmed';
    /** Called off. Terminal. */
    case Cancelled = 'cancelled';
    /** Delivered and closed. Terminal. */
    case Completed = 'completed';
}
