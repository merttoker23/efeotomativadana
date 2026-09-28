<?php

declare(strict_types=1);

namespace App\Module\Shipping;

/**
 * A carrier-backed delivery method, wired only in the test environment.
 *
 * It exists to prove the boundary the plan is really about: adding a carrier must mean
 * implementing {@see Gateway\ShippingProviderInterface} and describing the service, and nothing
 * else in the application may have to change. This method names the test-only carrier in exactly
 * the shape PHASE_17's real adapter will use, so the orchestration, messaging, cancellation,
 * status and tracking paths are all exercised end to end without a real endpoint.
 *
 * It is registered through `when@test` in `config/services.yaml`; no production environment can
 * offer a service that resolves to an adapter refused there.
 */
final readonly class TestCarrierShippingMethod implements ShippingMethodInterface
{
    public function key(): string { return 'carrier_express'; }

    public function label(): string { return 'Ekspres kargo'; }

    public function providerKey(): string { return 'fake'; }
}
