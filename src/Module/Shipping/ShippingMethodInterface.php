<?php

declare(strict_types=1);

namespace App\Module\Shipping;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One delivery service the store knows how to perform, independent of any carrier.
 *
 * The method is what an order names in its sealed shipping snapshot; this is what the shipping
 * side answers when asked to fulfil that order. Splitting the two is deliberate: the customer
 * chooses a service at checkout, while who actually carries the parcel is a fulfilment decision
 * that may be the store's own van today and a carrier tomorrow.
 *
 * Keys are the same strings the checkout offers as shipping options, so an order can never name a
 * delivery the shipping side is unable to perform.
 */
#[AutoconfigureTag('app.shipping_method')]
interface ShippingMethodInterface
{
    /** The key an order's shipping snapshot carries. */
    public function key(): string;

    /** Customer-facing name of the service. */
    public function label(): string;

    /**
     * Which provider fulfils it. The store's own hand delivery uses the reserved `manual` key,
     * which no carrier adapter may claim.
     *
     * That key is the only discriminator between hand delivery and a carrier, rather than a second
     * flag alongside it. A flag would let a method claim "no carrier needed" while naming one, and
     * the two answers would then disagree; a reserved key cannot. It is also what a shipment
     * persists, so the answer survives the method being retired.
     */
    public function providerKey(): string;
}
