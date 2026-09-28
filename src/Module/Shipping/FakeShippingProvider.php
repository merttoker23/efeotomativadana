<?php

declare(strict_types=1);

namespace App\Module\Shipping;

use App\Module\Shipping\Gateway\ShipmentCancellationInstruction;
use App\Module\Shipping\Gateway\ShipmentCancellationOutcome;
use App\Module\Shipping\Gateway\ShipmentCreationInstruction;
use App\Module\Shipping\Gateway\ShipmentCreationOutcome;
use App\Module\Shipping\Gateway\ShipmentLabel;
use App\Module\Shipping\Gateway\ShipmentLabelRequest;
use App\Module\Shipping\Gateway\ShipmentStatusReport;
use App\Module\Shipping\Gateway\ShipmentStatusRequest;
use App\Module\Shipping\Gateway\ShippingProviderInterface;

/**
 * A deterministic, in-memory carrier used only by the test environment.
 *
 * Registered through `when@test` in `config/services.yaml`, reports itself as not production
 * ready, and is refused by {@see ShippingProviderRegistry} outside dev/test — so it cannot
 * quietly move a real parcel even if it is left wired up.
 *
 * Every answer is queued explicitly and running out throws instead of inventing a success, so a
 * test can never pass because the fake defaulted to "shipped". Creations are memoised by
 * idempotency key, which is the behaviour a real carrier has to provide and the property the
 * replay-safety test actually exercises.
 */
final class FakeShippingProvider implements ShippingProviderInterface
{
    /** @var list<ShipmentCreationOutcome> */
    private array $creations = [];

    /** @var list<ShipmentCancellationOutcome> */
    private array $cancellations = [];

    /** @var list<ShipmentStatusReport> */
    private array $statuses = [];

    /** @var list<ShipmentLabel|null> */
    private array $labels = [];

    /** @var array<string, ShipmentCreationOutcome> idempotency key => the parcel that key created */
    private array $issued = [];

    /**
     * Every time this carrier was actually asked to take a parcel, including calls it answered
     * from its idempotency memo. A replay that reached the carrier twice would show 2 here, so a
     * test can tell "the handler never asked again" apart from "the handler asked again and the
     * fake deduplicated".
     */
    private int $createRequestCount = 0;

    /** How many of those calls produced a parcel rather than being answered from the memo. */
    private int $acceptedCreateCount = 0;

    private ?ShipmentCreationInstruction $lastInstruction = null;

    public function key(): string { return 'fake'; }

    public function displayName(): string { return 'Test kargo firması'; }

    public function productionReady(): bool { return false; }

    public function create(ShipmentCreationInstruction $instruction): ShipmentCreationOutcome
    {
        // The idempotency key is the parcel's identity, so it is the only safe memo key: an order
        // number and a sequence would both repeat across different orders.
        ++$this->createRequestCount;
        if (isset($this->issued[$instruction->idempotencyKey()])) {
            return $this->issued[$instruction->idempotencyKey()];
        }

        $outcome = array_shift($this->creations) ?? throw new \OutOfBoundsException('The fake shipping provider has no queued creation outcome.');
        $this->lastInstruction = $instruction;
        // Only an accepted parcel is remembered. A carrier that refused took nothing, so the same
        // key presented again has to be a fresh attempt rather than the same refusal forever.
        if ($outcome->isAccepted()) {
            ++$this->acceptedCreateCount;
            $this->issued[$instruction->idempotencyKey()] = $outcome;
        }

        return $outcome;
    }

    public function cancel(ShipmentCancellationInstruction $instruction): ShipmentCancellationOutcome
    {
        return array_shift($this->cancellations) ?? throw new \OutOfBoundsException('The fake shipping provider has no queued cancellation outcome.');
    }

    public function status(ShipmentStatusRequest $request): ShipmentStatusReport
    {
        return array_shift($this->statuses) ?? ShipmentStatusReport::unrecognised('The fake shipping provider was not told what to report.');
    }

    public function label(ShipmentLabelRequest $request): ?ShipmentLabel
    {
        if ([] === $this->labels) {
            return null;
        }

        return array_shift($this->labels);
    }

    public function queueCreation(ShipmentCreationOutcome $outcome): void
    {
        $this->creations[] = $outcome;
    }

    public function queueCancellation(ShipmentCancellationOutcome $outcome): void
    {
        $this->cancellations[] = $outcome;
    }

    public function queueStatus(ShipmentStatusReport $report): void
    {
        $this->statuses[] = $report;
    }

    public function queueLabel(?ShipmentLabel $label): void
    {
        $this->labels[] = $label;
    }

    public function createRequestCount(): int
    {
        return $this->createRequestCount;
    }

    /** How many distinct parcels this carrier actually took on. */
    public function acceptedCreateCount(): int
    {
        return $this->acceptedCreateCount;
    }

    /**
     * The last instruction that reached this carrier, so a test can assert what a real adapter
     * would have been told rather than only that it was told something.
     */
    public function lastCreationInstruction(): ?ShipmentCreationInstruction
    {
        return $this->lastInstruction;
    }
}
