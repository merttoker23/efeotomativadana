<?php

declare(strict_types=1);

namespace App\Module\Audit;

use App\Entity\Commerce\AuditLog;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The single writer of audit rows.
 *
 * Three decisions are worth stating plainly, because each is the kind of thing that quietly
 * makes an audit trail decorative.
 *
 * **It never throws.** An audit failure must not roll back a real capture or refuse a customer
 * whose order was already committed — that would turn a bookkeeping problem into a money
 * problem, and a store that cannot take orders because it cannot write a log line is worse than
 * one with a gap in its trail. A failure is reported on the `audit` channel and swallowed, and
 * `tests/Security/AuditTrailTest` proves a broken logger does not stop the business action.
 *
 * **It flushes only when nothing else will.** Inside a transaction — which is where every
 * business action that matters runs — the caller owns the commit, so an audit row commits with
 * the thing it describes and rolls back with it. Outside one, the request would end with the row
 * still pending and silently lose it, so the logger flushes itself. Both halves are load-bearing:
 * flushing unconditionally would commit rows describing changes that then failed, and never
 * flushing would lose every row written by an event listener, which is exactly where
 * authentication outcomes are recorded.
 *
 * **It persists but never reads back.** A service cannot therefore start treating its own audit
 * trail as a query API; this is an observation channel.
 */
final readonly class AuditLogger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditContextResolver $contexts,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private Connection $connection,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function record(
        AuditAction $action,
        ?string $resourceId = null,
        array $payload = [],
        ?string $resourceType = null,
        ?AuditContext $context = null,
    ): ?AuditLog {
        $context ??= $this->contexts->resolve();

        try {
            $log = new AuditLog(
                $action,
                $context->actorType,
                $context->actorEmail(),
                $resourceType ?? $action->subjectHint(),
                $resourceId,
                $payload,
                $context->ipAddress,
                $context->requestId,
                \DateTimeImmutable::createFromInterface($this->clock->now()),
            );
            $this->entityManager->persist($log);
            if (!$this->connection->isTransactionActive()) {
                $this->entityManager->flush();
            }
        } catch (\Throwable $failure) {
            // Deliberately swallowed, and deliberately only the exception *class* recorded: the
            // message of a Doctrine failure contains the SQL, the SQL contains the payload, and
            // the payload is the thing being redacted everywhere else in this application.
            $this->logger->error('Audit row could not be recorded.', [
                'action' => $action->value,
                'exception_class' => $failure::class,
            ]);

            return null;
        }

        $this->logger->info($action->value, [
            'actor_type' => $context->actorType->value,
            'actor' => $context->actorEmail(),
            'resource_type' => $resourceType ?? $action->subjectHint(),
            'resource_id' => $resourceId,
            'ip_address' => $context->ipAddress,
        ] + $payload);

        return $log;
    }
}
