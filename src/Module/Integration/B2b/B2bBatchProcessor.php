<?php

namespace App\Module\Integration\B2b;

use App\Entity\Integration\B2bSyncRun;
use App\Module\Integration\B2b\Exception\B2bLockUnavailableException;
use App\Module\Integration\B2b\Exception\B2bResourceConflictException;
use App\Module\Integration\B2b\Exception\B2bRetryableProviderException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockInterface;

final readonly class B2bBatchProcessor
{
    public function __construct(
        private B2bCatalogWriter $writer,
        private B2bSyncRunManager $runs,
        private B2bResourceResolver $resources,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<B2bFeedRecord> $records
     */
    public function process(array $records, B2bSyncMode $mode, B2bSyncRun $run, ?LockInterface $lock = null, ?int $startPosition = null): B2bBatchResult
    {
        $runId = $run->id() ?? throw new \LogicException('A B2B run must be persisted before processing a batch.');
        $infrastructureFailure = false;
        try {
            $this->resources->beginBatch($runId, $run->providerKey(), $records);
            $this->writer->beginMediaTransaction();
            try {
                try {
                    $results = $this->inTransaction(fn (): array => array_map(
                        fn (int $offset, B2bFeedRecord $record): B2bItemResult => $this->processRecordWithLock(
                            $record,
                            $mode,
                            $runId,
                            $lock,
                            null === $startPosition ? null : $startPosition + $offset,
                        ),
                        array_keys($records),
                        $records,
                    ));
                    $this->writer->commitMediaTransaction();
                } catch (B2bLockUnavailableException|B2bRetryableProviderException $exception) {
                    $this->resources->rollbackBatchClaims();
                    $this->writer->rollbackMediaTransaction();
                    throw $exception;
                } catch (\Throwable) {
                    $infrastructureFailure = true;
                    $this->resources->rollbackBatchClaims();
                    $this->writer->rollbackMediaTransaction();
                    $results = [];
                    foreach ($records as $offset => $record) {
                        try {
                            $this->resources->beginBatch($runId, $run->providerKey(), [$record]);
                            $this->writer->beginMediaTransaction();
                            try {
                                $results[] = $this->inTransaction(fn (): B2bItemResult => $this->processRecordWithLock(
                                    $record,
                                    $mode,
                                    $runId,
                                    $lock,
                                    null === $startPosition ? null : $startPosition + $offset,
                                ));
                                $this->writer->commitMediaTransaction();
                            } catch (B2bLockUnavailableException|B2bRetryableProviderException $exception) {
                                $this->resources->rollbackBatchClaims();
                                $this->writer->rollbackMediaTransaction();
                                throw $exception;
                            } catch (\Throwable) {
                                $this->resources->rollbackBatchClaims();
                                $this->writer->rollbackMediaTransaction();
                                $results[] = B2bItemResult::failure(new B2bItemError(B2bErrorType::Database, 'The B2B row could not be persisted.'));
                            }
                        } finally {
                            $this->resources->endBatch();
                        }
                    }
                }
            } finally {
                if ($this->writer->hasPendingMedia()) {
                    $this->writer->rollbackMediaTransaction();
                }
            }
        } finally {
            $this->resources->endBatch();
        }

        $counters = B2bSyncCounters::empty()->recordScanned(count($results));
        $errors = [];
        foreach ($results as $result) {
            $counters = $counters->merge($result->counters());
            if (null !== $result->error()) {
                $error = $result->error();
                if ($infrastructureFailure && B2bErrorType::Conflict === $error->errorType()) {
                    $error = new B2bItemError(B2bErrorType::Database, 'The B2B row could not be recovered after an infrastructure failure.', $error->externalId(), $error->context(), true);
                }
                $errors[] = $error;
                $counters = $counters->recordSkipped();
                if (B2bErrorType::Conflict === $error->errorType()) {
                    $counters = $counters->recordConflict();
                } elseif (B2bErrorType::InvalidPrice === $error->errorType()) {
                    $counters = $counters->recordPriceFailed();
                } elseif (B2bErrorType::InvalidStock === $error->errorType()) {
                    $counters = $counters->recordStockFailed();
                }
            }
            foreach ($result->deferredErrors() as $error) {
                $errors[] = $error;
            }
        }
        if ([] !== $errors) {
            $this->runs->recordErrors($run, $errors);
        }

        return new B2bBatchResult($counters, $errors);
    }

    public function beginRun(int $runId): void
    {
        $this->resources->beginRun($runId);
    }

    public function endRun(int $runId): void
    {
        $this->resources->endRun($runId);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function inTransaction(callable $operation): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $result = $operation();
            $this->entityManager->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            if ($this->entityManager->isOpen()) {
                $this->entityManager->clear();
            }

            throw $exception;
        }
    }

    private function processRecordWithLock(B2bFeedRecord $record, B2bSyncMode $mode, int $runId, ?LockInterface $lock, ?int $position): B2bItemResult
    {
        $result = $this->processRecord($record, $mode, $runId, $position);
        if (null === $lock) {
            return $result;
        }

        try {
            $lock->refresh();
        } catch (LockConflictedException $exception) {
            throw new B2bLockUnavailableException('The B2B provider lock was lost during processing.', 0, $exception);
        }

        return $result;
    }

    private function processRecord(B2bFeedRecord $record, B2bSyncMode $mode, int $runId, ?int $position): B2bItemResult
    {
        try {
            $this->resources->claimRecord($record, $runId, $position);
        } catch (B2bResourceConflictException $exception) {
            return B2bItemResult::failure(new B2bItemError(B2bErrorType::Conflict, $exception->getMessage(), $record->error()?->externalId() ?? $record->item()?->externalId()));
        }
        if (!$record->isSuccess() || null === $record->item()) {
            $error = $record->error() ?? new B2bItemError(B2bErrorType::InvalidItem, 'The feed record has no item.');
            if (B2bSyncMode::Daily === $mode && null !== $error->externalId()) {
                $this->resources->markProductSeenIfMapped(
                    $error->externalId(),
                    $runId,
                    \DateTimeImmutable::createFromInterface($this->clock->now()),
                );
            }

            return B2bItemResult::failure($error);
        }

        return B2bSyncMode::Daily === $mode
            ? $this->writer->updateDaily($record->item(), $runId)
            : $this->writer->importFull($record->item(), $runId);
    }
}
