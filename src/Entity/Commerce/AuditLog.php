<?php

declare(strict_types=1);

namespace App\Entity\Commerce;

use App\Module\Audit\AuditAction;
use App\Module\Audit\AuditActorType;
use App\Shared\Logging\SecretRedactor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One attributable fact: somebody, or something, did this to this resource, at this moment.
 *
 * This is a cross-cutting trail, deliberately not a fifth event table beside
 * `OrderStatusChange`, `PaymentEvent`, `ShipmentEvent` and `ReturnEvent`. Those four answer
 * "what happened to this order"; this one answers "who did it, from which address, over
 * which request", and it answers that for actions that have no aggregate of their own at
 * all — a settings save, a catalogue delete, a refused login.
 *
 * Two properties are load-bearing:
 *
 * 1. **No foreign key.** An audit row that cascades away with its subject is not an audit
 *    row. Deleting a customer must not delete the record that they were deactivated, so this
 *    entity holds plain identifiers and nothing that can cascade.
 * 2. **No update path.** There are no setters and no lifecycle callbacks. A trail that can be
 *    rewritten after the fact answers "what does the database say", which is a different and
 *    much weaker question than "what happened".
 *
 * The actor e-mail is a free-text identifier, not a foreign key to `AdminUser`, for the same
 * reason: a staff account that is later deleted must not erase the attribution of the action
 * it performed, and a renamed account must not retroactively re-label an old action.
 */
#[ORM\Entity]
#[ORM\Table(name: 'commerce_audit_log')]
#[ORM\Index(name: 'idx_audit_log_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_audit_log_action_time', columns: ['action', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_log_actor_time', columns: ['actor_email', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_log_resource', columns: ['resource_type', 'resource_id'])]
final class AuditLog
{
    /** Longest anything in the payload may be before it is dropped rather than stored. */
    public const int MAX_PAYLOAD_DEPTH = SecretRedactor::MAX_DEPTH;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\Column(length: 60, enumType: AuditAction::class)]
    private AuditAction $action;

    #[ORM\Column(name: 'actor_type', length: 20, enumType: AuditActorType::class)]
    private AuditActorType $actorType;

    /**
     * Null for a system or provider action: "the scheduler ran this" has no e-mail, and a
     * fabricated `system@localhost` would put a fake identity in the column whose only job is
     * to state who did something.
     */
    #[ORM\Column(name: 'actor_email', length: 180, nullable: true)]
    private ?string $actorEmail;

    #[ORM\Column(name: 'resource_type', length: 60, nullable: true)]
    private ?string $resourceType;

    #[ORM\Column(name: 'resource_id', length: 120, nullable: true)]
    private ?string $resourceId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    #[ORM\Column(name: 'ip_address', length: 45, nullable: true)]
    private ?string $ipAddress;

    /**
     * Groups the actions of one HTTP request. A settings save touches twelve rows and a
     * checkout touches four; without this, "who did this" is unanswerable because the answer
     * is spread across rows.
     */
    #[ORM\Column(name: 'request_id', length: 64, nullable: true)]
    private ?string $requestId;

    #[ORM\Column(name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        AuditAction $action,
        AuditActorType $actorType,
        ?string $actorEmail,
        ?string $resourceType,
        ?string $resourceId,
        array $payload,
        ?string $ipAddress,
        ?string $requestId,
        \DateTimeImmutable $occurredAt,
    ) {
        $this->action = $action;
        $this->actorType = $actorType;
        $this->actorEmail = self::normalizeEmail($actorEmail);
        $this->resourceType = self::normalizeToken($resourceType, 60, 'resource type');
        $this->resourceId = self::normalizeToken($resourceId, 120, 'resource id');
        // The payload is redacted on the way in, not on the way out: an audit table is read
        // by more people, and kept longer, than a log file, so it is the wrong place to be the
        // one copy of a provider secret that nobody thought to scrub.
        $this->payload = SecretRedactor::context($payload);
        $this->ipAddress = self::normalizeIpAddress($ipAddress);
        $this->requestId = self::normalizeToken($requestId, 64, 'request id');
        $this->occurredAt = $occurredAt;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function action(): AuditAction
    {
        return $this->action;
    }

    public function actorType(): AuditActorType
    {
        return $this->actorType;
    }

    public function actorEmail(): ?string
    {
        return $this->actorEmail;
    }

    public function resourceType(): ?string
    {
        return $this->resourceType;
    }

    public function resourceId(): ?string
    {
        return $this->resourceId;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function ipAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    private static function normalizeEmail(?string $email): ?string
    {
        if (null === $email) {
            return null;
        }
        $email = mb_strtolower(trim($email));

        return '' === $email ? null : mb_substr($email, 0, 180);
    }

    private static function normalizeToken(?string $value, int $limit, string $label): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);
        if ('' === $value) {
            return null;
        }
        if (mb_strlen($value) > $limit) {
            throw new \InvalidArgumentException(sprintf('Audit %s must be at most %d characters.', $label, $limit));
        }

        return $value;
    }

    /**
     * Stored as given, bounded to the column, but never a value that is not an address.
     *
     * This is deliberately not a filter against a trusted-proxy list: an audit trail records
     * what the request claimed, and the trusted-proxy configuration — not this column —
     * decides whether that claim is believed. Storing something that cannot be an address
     * would only mean a caller passed a header value straight through.
     */
    private static function normalizeIpAddress(?string $ip): ?string
    {
        if (null === $ip) {
            return null;
        }
        $ip = trim($ip);

        return '' === $ip ? null : mb_substr($ip, 0, 45);
    }
}
