<?php

namespace App\Module\Integration\B2b;

use App\Entity\Integration\B2bSyncError;
use App\Entity\Integration\B2bSyncObservation;
use App\Entity\Integration\B2bSyncRun;
use App\Repository\Integration\B2bSyncErrorRepository;
use App\Repository\Integration\B2bSyncObservationRepository;
use App\Repository\Integration\B2bSyncRunRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final class B2bSyncRunManager
{
    public function __construct(
        private B2bSyncRunRepository $runs,
        private B2bSyncErrorRepository $errors,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $managerRegistry,
        private ClockInterface $clock,
        private int $maxErrorDetails = 1000,
        private ?B2bSyncObservationRepository $observations = null,
        private ?LoggerInterface $logger = null,
    ) {
        if ($this->maxErrorDetails < 0) {
            throw new \InvalidArgumentException('B2B error detail cap cannot be negative.');
        }
    }

    public function createOrGetActive(string $providerKey, B2bSyncMode $mode): B2bRunCreationResult
    {
        $active = $this->runs->findActive($providerKey);
        if (null !== $active) {
            return new B2bRunCreationResult($active, false);
        }

        $run = B2bSyncRun::queue($providerKey, $mode, $this->clock->now());
        $this->runs->save($run);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $freshManager = $this->managerRegistry->resetManager();
            if (!$freshManager instanceof EntityManagerInterface) {
                throw new \RuntimeException('The active B2B entity manager could not be recovered.');
            }
            $freshRepository = $freshManager->getRepository(B2bSyncRun::class);
            if (!$freshRepository instanceof B2bSyncRunRepository) {
                throw new \RuntimeException('The active B2B run repository could not be recovered.');
            }
            $active = $freshRepository->findActive($providerKey);
            if (!$active instanceof B2bSyncRun) {
                throw new \RuntimeException('The active B2B run could not be recovered after a uniqueness conflict.');
            }
            $freshErrors = $freshManager->getRepository(B2bSyncError::class);
            if (!$freshErrors instanceof B2bSyncErrorRepository) {
                throw new \RuntimeException('The active B2B error repository could not be recovered.');
            }
            $this->runs = $freshRepository;
            $this->errors = $freshErrors;
            $this->entityManager = $freshManager;
            $this->observations = null;

            return new B2bRunCreationResult($active, false);
        }

        return new B2bRunCreationResult($run, true);
    }

    public function markRunning(B2bSyncRun $run): void
    {
        $run->markRunning($this->clock->now());
        $this->save($run);
    }

    public function markMessageDispatched(B2bSyncRun $run): void
    {
        $runId = $run->id() ?? throw new \LogicException('A B2B run must be persisted before marking dispatch.');
        $managedRun = $this->runs->find($runId);
        if (!$managedRun instanceof B2bSyncRun) {
            throw new \RuntimeException('The B2B run disappeared while marking dispatch.');
        }
        $managedRun->markMessageDispatched($this->clock->now());
        $this->save($managedRun);
    }

    public function recordBatch(B2bSyncRun $run, B2bSyncCounters $counters, int $checkpoint): void
    {
        $run->recordBatch($counters, $checkpoint, $this->clock->now());
        $this->save($run);
    }

    /** @param list<B2bItemError> $errors @return int */
    public function recordErrors(B2bSyncRun $run, array $errors): int
    {
        $runId = $run->id();
        if (null === $runId) {
            throw new \LogicException('A B2B run must be persisted before recording errors.');
        }
        $managedRun = $this->runs->find($runId);
        if (!$managedRun instanceof B2bSyncRun) {
            throw new \RuntimeException('The B2B run disappeared while recording item errors.');
        }
        $available = max(0, $this->maxErrorDetails - $this->errors->countForRun($runId));
        $selected = array_slice($errors, 0, $available);
        foreach ($selected as $error) {
            $this->errors->save(B2bSyncError::create(
                $managedRun,
                $error->externalId(),
                $error->errorType(),
                $error->retryable(),
                $error->message(),
                $this->boundedContext($error->context()),
                $this->clock->now(),
            ));
        }
        if ([] !== $selected) {
            $this->entityManager->flush();
        }

        return count($selected);
    }

    public function complete(B2bSyncRun $run): void
    {
        $run->complete($this->clock->now());
        $this->save($run);
        $this->cleanupObservations($run);
    }

    public function retry(B2bSyncRun $run, string $error): void
    {
        $run->retry($error, $this->clock->now());
        $this->save($run);
    }

    public function fail(B2bSyncRun $run, string $error): void
    {
        $run->fail($error, $this->clock->now());
        $this->save($run);
        $this->cleanupObservations($run);
    }

    public function cancel(B2bSyncRun $run): void
    {
        $run->cancel($this->clock->now());
        $this->save($run);
        $this->cleanupObservations($run);
    }

    private function save(B2bSyncRun $run): void
    {
        $this->runs->save($run);
        $this->entityManager->flush();
    }

    private function cleanupObservations(B2bSyncRun $run): void
    {
        $runId = $run->id();
        if (null === $runId) {
            return;
        }
        $observations = $this->observations;
        if (null === $observations) {
            $candidate = $this->entityManager->getRepository(B2bSyncObservation::class);
            $observations = $candidate instanceof B2bSyncObservationRepository ? $candidate : null;
        }
        if (null === $observations) {
            return;
        }
        try {
            $observations->deleteForRun($runId);
        } catch (\Throwable $exception) {
            $this->logger?->warning('B2B terminal-run observation cleanup failed.', ['run_id' => $runId, 'exception' => $exception]);
        }
    }

    /**
     * @param array<string, bool|int|string|null> $context
     * @return array<string, bool|int|string|null>
     */
    private function boundedContext(array $context): array
    {
        $bounded = [];
        foreach ($context as $key => $value) {
            $key = mb_substr((string) $key, 0, 100);
            $bounded[$key] = is_string($value) ? mb_substr($value, 0, 1000) : $value;
        }

        return $bounded;
    }
}
