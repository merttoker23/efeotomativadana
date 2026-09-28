<?php

declare(strict_types=1);

namespace App\Module\Shipping;

/**
 * The store fulfils its own deliveries.
 *
 * This is the method that keeps normal commerce operable: it names no carrier, asks for no
 * credentials and calls no API, so a store with `shipping.provider` unset — or set to something
 * broken — can still take, pack and hand over an order. The reserved `manual` provider key is
 * what tells the orchestrator not to look for a carrier at all.
 */
final readonly class LocalManualShippingMethod implements ShippingMethodInterface
{
    /** Reserved provider key for the store's own hand delivery. No adapter may claim it. */
    public const string MANUAL_PROVIDER_KEY = 'manual';

    public function key(): string { return 'local_standard'; }

    public function label(): string { return 'Yerel standart teslimat'; }

    public function providerKey(): string { return self::MANUAL_PROVIDER_KEY; }
}
