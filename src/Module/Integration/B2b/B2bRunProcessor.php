<?php

namespace App\Module\Integration\B2b;

use App\Entity\Integration\B2bSyncRun;
use App\Module\Integration\B2b\Exception\B2bLockUnavailableException;
use App\Module\Integration\B2b\Exception\B2bPermanentProviderException;
use App\Module\Integration\B2b\Exception\B2bRetryableProviderException;
use App\Repository\Integration\B2bSyncRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockInterface;

final readonly class B2bRunProcessor implements B2bRunProcessorInterface
{
    public function __construct(
        private B2bSyncRunRepository $runs,
        private B2bSyncRunManager $runManager,
        private B2bProviderRegistry $providers,
        private B2bBatchProcessor $batchProcessor,
        private B2bDailyReconciler $reconciler,
        private B2bSyncLock $lock,
        private B2bSnapshotCleaner $cleaner,
        private EntityManagerInterface $entityManager,
        private string $snapshotDirectory,
        private int $batchSize,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
        if ($this->batchSize < 1) {
            throw new \InvalidArgumentException('B2B batch size must be positive.');
        }
    }

    public function process(int $runId): void
    {
        $run = $this->runs->find($runId);
        if (!$run instanceof B2bSyncRun || in_array($run->state(), [B2bSyncState::Completed, B2bSyncState::Failed, B2bSyncState::Cancelled], true)) {
            return;
        }
        $lock = $this->lock->acquire($run->providerKey());
        if (null === $lock) {
            $this->logger->warning('B2B synchronization is already locked.', ['run_id' => $runId, 'provider' => $run->providerKey()]);

            throw new B2bLockUnavailableException('The B2B provider lock is currently held by another worker.');
        }
        $run = $this->runs->find($runId);
        if (!$run instanceof B2bSyncRun || in_array($run->state(), [B2bSyncState::Completed, B2bSyncState::Failed, B2bSyncState::Cancelled], true)) {
            $lock->release();

            return;
        }
        $this->batchProcessor->beginRun($runId);
        try {
            if (B2bSyncState::Queued === $run->state()) {
                $this->runManager->markRunning($run);
            }
            $provider = $this->providers->get($run->providerKey());
            if ($run->checkpoint() > 0 && null === $run->snapshotPath()) {
                throw new B2bPermanentProviderException('The B2B checkpoint has no persisted snapshot; resume was refused.');
            }
            $this->cleaner->pruneExpired($this->snapshotDirectory, $run->snapshotPath());
            try {
                $lock->refresh();
            } catch (\Symfony\Component\Lock\Exception\LockConflictedException $exception) {
                throw new B2bLockUnavailableException('The B2B provider lock was lost before snapshot preparation.', 0, $exception);
            }
            $snapshot = $provider->prepareSnapshot(new B2bSnapshotRequest(
                $run->providerKey(),
                $runId,
                $run->mode(),
                $this->snapshotDirectory,
                $run->snapshotPath(),
                $run->snapshotSha256(),
            ));
            $this->assertDailySnapshotPlausible($run, $snapshot);
            $this->assertFullSnapshotPlausible($run, $snapshot);
            $run->recordSnapshot($snapshot->path, $snapshot->declaredCount, $snapshot->sha256, $this->now());
            $this->runManager->recordBatch($run, B2bSyncCounters::empty(), $run->checkpoint());
            $this->entityManager->clear();
            $this->assertSnapshotDigest($snapshot);

            $checkpoint = $this->checkpoint($runId);
            if ($checkpoint > $snapshot->declaredCount) {
                throw new B2bRetryableProviderException('The B2B checkpoint exceeds the persisted snapshot count.');
            }
            $expectedPosition = $checkpoint;
            $nextCheckpoint = $checkpoint;
            $replays = 0;
            $batch = [];
            foreach ($provider->streamItems($snapshot, new B2bSyncCheckpoint($checkpoint)) as $key => $record) {
                $position = $this->streamPosition($key, $run, $snapshot, $checkpoint, $expectedPosition, $record);
                if ($position !== $expectedPosition) {
                    if ($position > $expectedPosition) {
                        $this->logger->error('The B2B provider stream skipped a record position; continuing would import the snapshot with a gap.', $this->streamContext($run, $snapshot, $checkpoint, $expectedPosition, $key, $record));
                        throw new B2bPermanentProviderException('The B2B provider stream skipped a record position; the snapshot gap cannot be reconciled.');
                    }
                    // The provider replayed a record that is already committed or already queued in
                    // the open batch. Re-applying it is idempotent, so the duplicate is dropped
                    // instead of failing a run that has tens of thousands of records left.
                    if (++$replays > $snapshot->declaredCount) {
                        $this->logger->error('The B2B provider stream replayed more records than the snapshot declares.', $this->streamContext($run, $snapshot, $checkpoint, $expectedPosition, $key, $record));
                        throw new B2bPermanentProviderException('The B2B provider stream replayed more records than the snapshot declares.');
                    }
                    $this->logger->warning('The B2B provider stream repeated an already handled record position; the duplicate was ignored.', $this->streamContext($run, $snapshot, $checkpoint, $expectedPosition, $key, $record));
                    continue;
                }
                ++$expectedPosition;
                $nextCheckpoint = $position + 1;
                $batch[] = $record;
                if (count($batch) >= $this->batchSize) {
                    $this->flushBatch($batch, $run->mode(), $runId, $nextCheckpoint, $lock, $checkpoint);
                    $batch = [];
                    $checkpoint = $nextCheckpoint;
                }
            }
            if ([] !== $batch) {
                $startCheckpoint = $checkpoint;
                $checkpoint = $nextCheckpoint;
                $this->flushBatch($batch, $run->mode(), $runId, $checkpoint, $lock, $startCheckpoint);
            } else {
                $checkpoint = $this->checkpoint($runId);
            }
            if ($expectedPosition !== $snapshot->declaredCount || $checkpoint !== $snapshot->declaredCount) {
                $this->logger->error('The B2B provider stream ended before the declared snapshot count.', $this->streamContext($run, $snapshot, $checkpoint, $expectedPosition, null, null));

                throw new B2bRetryableProviderException('The B2B provider stream ended before the declared snapshot count.');
            }
            $this->assertSnapshotDigest($snapshot);
            $current = $this->runs->find($runId);
            if (!$current instanceof B2bSyncRun) {
                return;
            }
            if (B2bSyncMode::Daily === $current->mode()) {
                $this->assertDailyCoverage($current->providerKey(), $runId);
                $reconciliation = $this->reconciler->reconcile($current->providerKey(), $runId, $lock);
                $current = $this->runs->find($runId);
                if (!$current instanceof B2bSyncRun) {
                    throw new \RuntimeException('The B2B run disappeared during reconciliation.');
                }
                $this->runManager->recordBatch($current, $reconciliation, $checkpoint);
            }
            $counters = $current->counters();
            $identityOrItemFailures = $counters->skipped() - $counters->priceFailed() - $counters->stockFailed();
            if ($identityOrItemFailures >= 10 && $identityOrItemFailures > $counters->scanned() * 0.25) {
                throw new B2bRetryableProviderException(sprintf('Too many B2B identity or item failures to complete (%d of %d records).', $identityOrItemFailures, $counters->scanned()));
            }
            $this->runManager->complete($current);
            $this->cleaner->remove($snapshot->path);
        } finally {
            $this->batchProcessor->endRun($runId);
            $lock->release();
        }
    }

    /**
     * A resumed run identifies every record by its absolute snapshot position, so a non-numeric
     * provider key is a contract violation rather than a temporary glitch: retrying cannot fix it
     * and silently casting it to zero would look like an endless position-zero replay.
     */
    private function streamPosition(mixed $key, B2bSyncRun $run, B2bSnapshot $snapshot, int $checkpoint, int $expectedPosition, B2bFeedRecord $record): int
    {
        if (is_int($key)) {
            return $key;
        }
        if (is_string($key) && '' !== $key && ctype_digit($key)) {
            return (int) $key;
        }
        $this->logger->error('The B2B provider stream yielded a non-numeric record position.', $this->streamContext($run, $snapshot, $checkpoint, $expectedPosition, $key, $record));

        throw new B2bPermanentProviderException('The B2B provider stream yielded a non-numeric record position.');
    }

    /** @return array<string, bool|int|string|null> */
    private function streamContext(B2bSyncRun $run, B2bSnapshot $snapshot, int $checkpoint, int $expectedPosition, mixed $receivedPosition, ?B2bFeedRecord $record): array
    {
        $item = $record?->item();

        return [
            'run_id' => $run->id(),
            'provider' => $run->providerKey(),
            'mode' => $run->mode()->value,
            'checkpoint' => $checkpoint,
            'expected_position' => $expectedPosition,
            'received_position' => is_int($receivedPosition) || is_string($receivedPosition) ? $receivedPosition : null,
            'declared_count' => $snapshot->declaredCount,
            'external_id' => $item?->externalId() ?? $record?->error()?->externalId(),
            'sku' => $item?->sku(),
        ];
    }

    /** @param list<B2bFeedRecord> $batch */
    private function flushBatch(array $batch, B2bSyncMode $mode, int $runId, int $checkpoint, LockInterface $lock, int $startCheckpoint): void
    {
        $run = $this->runs->find($runId);
        if (!$run instanceof B2bSyncRun) {
            throw new \RuntimeException('The B2B run disappeared during processing.');
        }
        $result = $this->batchProcessor->process($batch, $mode, $run, $lock, $startCheckpoint);
        foreach ($result->errors as $error) {
            if (B2bErrorType::Database === $error->errorType()
                || (B2bSyncMode::Daily === $mode
                    && in_array($error->errorType(), [B2bErrorType::Conflict, B2bErrorType::InvalidItem], true))
                || (B2bErrorType::Conflict === $error->errorType()
                    && str_contains($error->message(), 'appears more than once'))) {
                throw new B2bRetryableProviderException('A B2B persistence or identity failure blocks DAILY reconciliation and will be retried.');
            }
        }
        $managedRun = $this->runs->find($runId);
        if (!$managedRun instanceof B2bSyncRun) {
            throw new \RuntimeException('The B2B run disappeared while recording a recovered batch.');
        }
        $this->runManager->recordBatch($managedRun, $result->counters, $checkpoint);
        $this->entityManager->clear();
    }

    private function assertSnapshotDigest(B2bSnapshot $snapshot): void
    {
        if (!is_file($snapshot->path)) {
            throw new B2bPermanentProviderException('The B2B snapshot disappeared before processing.');
        }
        $digest = hash_file('sha256', $snapshot->path);
        if (false === $digest || !hash_equals($snapshot->sha256, $digest)) {
            throw new B2bPermanentProviderException('The B2B snapshot changed before processing.');
        }
    }

    private function assertDailyCoverage(string $providerKey, int $runId): void
    {
        $connection = $this->entityManager->getConnection();
        $total = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM integration_external_mapping WHERE provider_key = ? AND resource_type = ?',
            [$providerKey, 'product'],
        );
        $seen = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM integration_external_mapping WHERE provider_key = ? AND resource_type = ? AND last_seen_run_id = ?',
            [$providerKey, 'product', $runId],
        );
        $previous = $connection->fetchAssociative(
            'SELECT id, declared_count, finished_at FROM integration_b2b_sync_run WHERE provider_key = ? AND state = ? AND declared_count IS NOT NULL ORDER BY finished_at DESC, id DESC LIMIT 1',
            [$providerKey, 'completed'],
        );
        if (false === $previous) {
            if ($seen < $total) {
                throw new B2bRetryableProviderException('The first DAILY snapshot did not cover every mapped product; reconciliation was refused.');
            }

            return;
        }
        $previousFinishedAt = $previous['finished_at'] ?? null;
        if (!is_string($previousFinishedAt) || '' === $previousFinishedAt) {
            throw new B2bRetryableProviderException('The last successful DAILY baseline has no completion time.');
        }
        $priorCount = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM integration_external_mapping WHERE provider_key = ? AND resource_type = ? AND first_seen_at <= ?',
            [$providerKey, 'product', $previousFinishedAt],
        );
        $seenPrior = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM integration_external_mapping WHERE provider_key = ? AND resource_type = ? AND last_seen_run_id = ? AND first_seen_at <= ?',
            [$providerKey, 'product', $runId, $previousFinishedAt],
        );
        $maxUnseen = $priorCount < 10 ? 1 : (int) ceil($priorCount * 0.25);
        if ($priorCount - $seenPrior > $maxUnseen) {
            throw new B2bRetryableProviderException('The DAILY snapshot leaves too many previously mapped products unseen.');
        }
        $currentDeclared = (int) $connection->fetchOne(
            'SELECT declared_count FROM integration_b2b_sync_run WHERE id = ?',
            [$runId],
        );
        if ($currentDeclared < (int) ceil((int) $previous['declared_count'] * 0.5)) {
            throw new B2bRetryableProviderException('The DAILY snapshot is implausibly smaller than the last successful snapshot.');
        }
    }

    private function assertDailySnapshotPlausible(B2bSyncRun $run, B2bSnapshot $snapshot): void
    {
        if (B2bSyncMode::Daily !== $run->mode()) {
            return;
        }
        $connection = $this->entityManager->getConnection();
        $mappedProducts = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM integration_external_mapping WHERE provider_key = ? AND resource_type = ?',
            [$run->providerKey(), 'product'],
        );
        if ($mappedProducts > 0 && 0 === $snapshot->declaredCount) {
            throw new B2bRetryableProviderException('An empty DAILY snapshot cannot reconcile an existing catalog.');
        }
    }

    private function assertFullSnapshotPlausible(B2bSyncRun $run, B2bSnapshot $snapshot): void
    {
        if (B2bSyncMode::Full !== $run->mode()) {
            return;
        }
        $previous = $this->runs->latestSuccessful($run->providerKey(), B2bSyncMode::Full);
        $baseline = $previous?->declaredCount();
        // No historical baseline exists for a first FULL. Later feeds may legitimately lose
        // some rows, but a loss greater than 25% needs a fresh provider response.
        if (null !== $baseline && $snapshot->declaredCount < (int) ceil($baseline * 0.75)) {
            if (0 === $run->checkpoint() && null === $run->snapshotPath()) {
                $this->cleaner->remove($snapshot->path);
            }
            throw new B2bRetryableProviderException(sprintf('The FULL snapshot is implausibly smaller than the last successful FULL (%d versus %d records).', $snapshot->declaredCount, $baseline));
        }
    }

    private function checkpoint(int $runId): int
    {
        $run = $this->runs->find($runId);
        if (!$run instanceof B2bSyncRun) {
            throw new \RuntimeException('The B2B run disappeared during processing.');
        }

        return $run->checkpoint();
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
