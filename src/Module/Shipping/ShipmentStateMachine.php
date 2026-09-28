<?php

declare(strict_types=1);

namespace App\Module\Shipping;

final readonly class ShipmentStateMachine
{
    public function canTransition(ShipmentState $from, ShipmentState $to): bool
    {
        return in_array($to, $this->allowedTargets($from), true);
    }

    public function assertTransition(ShipmentState $from, ShipmentState $to): ShipmentState
    {
        if (!$this->canTransition($from, $to)) {
            throw new \DomainException(sprintf('Shipment cannot transition from %s to %s.', $from->value, $to->value));
        }

        return $to;
    }

    /** @return list<ShipmentState> */
    private function allowedTargets(ShipmentState $from): array
    {
        return match ($from) {
            // A carrier may report "out for delivery" without a separate ready step ever having
            // been observed, so pending may go straight to in transit.
            ShipmentState::Pending => [ShipmentState::Ready, ShipmentState::InTransit, ShipmentState::Failed, ShipmentState::Cancelled],
            ShipmentState::Ready => [ShipmentState::InTransit, ShipmentState::Delivered, ShipmentState::Failed, ShipmentState::Cancelled],
            ShipmentState::InTransit => [ShipmentState::Delivered, ShipmentState::Failed],
            // The same shipment row is retried rather than replaced, which is what lets the
            // provider idempotency key do its job on the second attempt. Back to pending is the
            // operator's explicit "try again" after a failure nothing automatic will fix.
            ShipmentState::Failed => [ShipmentState::Pending, ShipmentState::Ready, ShipmentState::Cancelled],
            ShipmentState::Delivered, ShipmentState::Cancelled => [],
        };
    }
}
