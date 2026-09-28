<?php

declare(strict_types=1);

namespace App\Module\Returns;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\OrderItem;
use App\Module\Order\OrderState;

/**
 * Which orders may be returned, and how much of one is left.
 *
 * Four rules, all about the *order*, none about the customer:
 *
 * - the money has to have been accepted. A `placed` order is one whose payment has not settled, and
 *   there is nothing to give back;
 * - a cancelled order is settled the other way, so there is likewise nothing to send back;
 * - the request has to arrive inside a window. Fourteen days is the Turkish consumer distance-sales
 *   period and the one the store's own announcements promise, so the code and the storefront make
 *   the same promise rather than two different ones;
 * - what is left of an order is what was bought less what an open return already holds. A rejected
 *   or withdrawn request gives its quantity back, because the store said no and the goods never
 *   moved.
 *
 * Delivery is deliberately *not* required. The store ships by hand through
 * {@see \App\Module\Shipping\LocalManualShippingMethod} whenever no carrier is configured, and
 * requiring a checkbox somebody has to remember would make returns impossible whenever nobody
 * remembered — which is exactly when a customer is most likely to need one.
 */
final readonly class ReturnPolicy
{
    public const int WINDOW_DAYS = 14;

    /** Why this order cannot be returned right now, or null when it can. */
    public function whyNotReturnable(CustomerOrder $order, \DateTimeImmutable $now): ?ReturnIneligibility
    {
        return match (true) {
            OrderState::Cancelled === $order->state() => ReturnIneligibility::OrderCancelled,
            !in_array($order->state(), [OrderState::Confirmed, OrderState::Completed], true) => ReturnIneligibility::OrderNotSettled,
            !$this->isWithinWindow($order, $now) => ReturnIneligibility::ReturnWindowExpired,
            default => null,
        };
    }

    public function isWithinWindow(CustomerOrder $order, \DateTimeImmutable $now): bool
    {
        return $order->createdAt()->modify(sprintf('+%d days', self::WINDOW_DAYS)) >= $now;
    }

    /**
     * How many units of one order line are still free to be returned.
     *
     * @param array<int, int> $claimed order item id => quantity held by an open or approved return
     */
    public function returnableQuantityFor(CustomerOrder $order, OrderItem $item, array $claimed): int
    {
        $owned = $this->quantityOf($item);
        $held = $item->id() === null ? 0 : ($claimed[$item->id()] ?? 0);

        return max(0, $owned - $held);
    }

    /**
     * The summed returnable quantity for a whole order, in one pass.
     *
     * A form for a twenty-line order asks this once rather than twenty times, which is what keeps
     * the "how much can I still return" question one round trip.
     *
     * @param array<int, int> $claimed
     */
    public function returnableQuantityForOrder(CustomerOrder $order, array $claimed): int
    {
        $total = 0;
        foreach ($order->items() as $item) {
            $total += $this->returnableQuantityFor($order, $item, $claimed);
        }

        return $total;
    }

    /**
     * The purchased quantity of an order line.
     *
     * Read off the order's own sealed snapshot rather than the current cart or product row, so a
     * catalogue edit cannot change what a customer bought last month.
     */
    private function quantityOf(OrderItem $item): int
    {
        return $item->quantity();
    }
}
