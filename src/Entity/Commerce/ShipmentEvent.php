<?php

declare(strict_types=1);

namespace App\Entity\Commerce;

use App\Module\Shipping\ShipmentState;
use App\Module\Shipping\ShipmentText;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable audit row for one shipment state change.
 *
 * Deliberately kept apart from {@see OrderStatusChange} for the same reason payments are: an
 * operator must be able to answer "what did the carrier say, and when" without reading the order
 * timeline, and the order timeline must stay legible for a customer who is not looking at any of
 * it. Rows are written even when a state change is refused or a carrier answer changes nothing —
 * the attempt is a fact about the shipment either way.
 */
#[ORM\Entity]
#[ORM\Table(name: 'commerce_shipment_event')]
#[ORM\Index(name: 'idx_shipment_event_shipment_time', columns: ['shipment_id', 'occurred_at'])]
final class ShipmentEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Shipment::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Shipment $shipment;

    #[ORM\Column(length: 30, enumType: ShipmentState::class)]
    private ShipmentState $toState;

    /** `store` or `provider`. */
    #[ORM\Column(length: 30)]
    private string $source;

    /**
     * Who decided, when a person did.
     *
     * Null for anything the carrier or the system said, because "the courier" is not an auditor.
     * Non-null for every operator action, so a trail cannot distinguish nothing about who moved
     * the parcel — which is the whole point of recording it separately from the order's own history.
     */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $actorEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $detail = null;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    public function __construct(Shipment $shipment, ShipmentState $toState, string $source, ?string $detail, \DateTimeImmutable $occurredAt, ?string $actorEmail = null)
    {
        $source = trim($source);
        if ('' === $source || mb_strlen($source) > 30) {
            throw new \InvalidArgumentException('Shipment event source must contain between 1 and 30 characters.');
        }
        if (null !== $actorEmail) {
            $actorEmail = mb_strtolower(trim($actorEmail));
            if ('' === $actorEmail || mb_strlen($actorEmail) > 180) {
                throw new \InvalidArgumentException('Shipment event actor email is invalid.');
            }
        }
        // Provider wording is kept, so a status mapping can be explained months later, but only
        // after it has been reduced to a single bounded line.
        $detail = ShipmentText::status($detail, 255);

        $this->shipment = $shipment;
        $this->toState = $toState;
        $this->source = $source;
        $this->actorEmail = $actorEmail;
        $this->detail = $detail;
        $this->occurredAt = $occurredAt;
    }

    public function id(): ?int { return $this->id; }
    public function shipment(): Shipment { return $this->shipment; }
    public function orderNumber(): string { return $this->shipment->orderNumber(); }
    public function toState(): ShipmentState { return $this->toState; }
    public function source(): string { return $this->source; }
    public function actorEmail(): ?string { return $this->actorEmail; }
    public function detail(): ?string { return $this->detail; }
    public function occurredAt(): \DateTimeImmutable { return $this->occurredAt; }
}
