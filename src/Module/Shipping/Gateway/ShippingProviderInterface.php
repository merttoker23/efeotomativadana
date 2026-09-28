<?php

declare(strict_types=1);

namespace App\Module\Shipping\Gateway;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The provider-neutral shipping boundary.
 *
 * A concrete cargo adapter (PHASE_17) implements this and nothing else in the application
 * changes: the shipment aggregate, the orchestrator, the Messenger handler and the admin
 * screens all speak these types. The contract is shaped around the four things every carrier
 * can be asked for — create a parcel, recall it, report where it is, produce a label — so no
 * vendor payload, endpoint shape or field name leaks into the domain.
 *
 * Implementations must treat the instruction's idempotency key as the parcel's identity: the
 * same key answered twice has to return the same reference, because a retried message must
 * never buy a second delivery.
 */
#[AutoconfigureTag('app.shipping_provider')]
interface ShippingProviderInterface
{
    /** Stable provider key, matched against the `shipping.provider` store setting. */
    public function key(): string;

    /**
     * Operator-facing name for the admin shipment screens.
     *
     * Deliberately not `label()`: that name belongs to the printable-label capability below, and
     * one method cannot be both the carrier's name and the act of fetching its label.
     */
    public function displayName(): string;

    /**
     * Whether this adapter may move real parcels in production. A test-only or fake adapter
     * must return false, which makes the registry refuse it outside dev/test.
     */
    public function productionReady(): bool;

    /**
     * Hand one parcel to the carrier. Must be safe to call again with the same idempotency key:
     * a repeat returns the reference the first call produced.
     */
    public function create(ShipmentCreationInstruction $instruction): ShipmentCreationOutcome;

    /**
     * Recall a parcel that has not left the building. A carrier that has already dispatched
     * must refuse rather than pretend; the application decides what a refusal means.
     */
    public function cancel(ShipmentCancellationInstruction $instruction): ShipmentCancellationOutcome;

    /**
     * Report where one parcel is. A status the adapter cannot map must come back as
     * {@see ShipmentStatusReport::unrecognised()} rather than as a guess.
     */
    public function status(ShipmentStatusRequest $request): ShipmentStatusReport;

    /**
     * Fetch the carrier's printable label for one parcel, or null for a carrier that has none.
     * This is a read; it must not create a second parcel.
     */
    public function label(ShipmentLabelRequest $request): ?ShipmentLabel;
}
