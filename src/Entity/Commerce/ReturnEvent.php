<?php

declare(strict_types=1);

namespace App\Entity\Commerce;

use App\Module\Returns\ReturnState;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row of a return's audit trail.
 *
 * Append-only, and the reason the phase's "return state is auditable" criterion is met by data
 * rather than by convention: a request that was approved, withdrawn and re-opened as a different
 * request is a sequence of rows, and the sequence is what an operator reads. There is deliberately
 * no update and no delete path on this entity.
 */
#[ORM\Entity]
#[ORM\Table(name: 'commerce_return_event')]
#[ORM\Index(name: 'idx_return_event_request_time', columns: ['return_request_id', 'occurred_at'])]
final class ReturnEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ReturnRequest::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ReturnRequest $returnRequest;

    #[ORM\Column(length: 30, enumType: ReturnState::class)]
    private ReturnState $toState;

    #[ORM\Column(length: 30)]
    private string $source;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $detail;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $actorEmail;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    public function __construct(ReturnRequest $returnRequest, ReturnState $toState, string $source, ?string $detail, \DateTimeImmutable $occurredAt, ?string $actorEmail = null)
    {
        $source = trim($source);
        if ('' === $source || mb_strlen($source) > 30) {
            throw new \DomainException('A return event source must be between 1 and 30 characters.');
        }
        if (null !== $detail && mb_strlen($detail) > 500) {
            throw new \DomainException('A return event detail cannot exceed 500 characters.');
        }
        $actorEmail = null === $actorEmail ? null : mb_strtolower(trim($actorEmail));

        $this->returnRequest = $returnRequest;
        $this->toState = $toState;
        $this->source = $source;
        $this->detail = $detail;
        $this->actorEmail = '' === (string) $actorEmail ? null : $actorEmail;
        $this->occurredAt = $occurredAt;
    }

    public function id(): ?int { return $this->id; }
    public function returnRequest(): ReturnRequest { return $this->returnRequest; }
    public function returnNumber(): string { return $this->returnRequest->returnNumber(); }
    public function toState(): ReturnState { return $this->toState; }
    public function source(): string { return $this->source; }
    public function detail(): ?string { return $this->detail; }
    public function actorEmail(): ?string { return $this->actorEmail; }
    public function occurredAt(): \DateTimeImmutable { return $this->occurredAt; }
}
