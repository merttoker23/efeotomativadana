<?php

declare(strict_types=1);

namespace App\Module\Audit;

/**
 * The resolved "who, from where" of one audited action.
 *
 * Resolved once per record from the security token storage and the current request, rather
 * than threaded down through every service that might mutate something. The alternative is an
 * audit trail only as complete as the call sites remember to be, and a missing actor is the
 * one field an auditor cannot work around.
 *
 * Every field is nullable and null is a legitimate value: a Messenger handler has no request
 * and no session, and a provider webhook has no customer. What must never happen is recording
 * such a case as if a person did it.
 */
final readonly class AuditContext
{
    public function __construct(
        public AuditActorType $actorType,
        public ?string $actorEmail,
        public ?string $ipAddress,
        public ?string $requestId,
    ) {
    }

    /**
     * The value stored in the row's actor column.
     *
     * Null for a system or provider action: "the scheduler ran this" has no e-mail, and
     * inventing `system@localhost` would put a fake identity in the column whose only purpose
     * is to state who did something.
     */
    public function actorEmail(): ?string
    {
        return $this->actorEmail;
    }
}
