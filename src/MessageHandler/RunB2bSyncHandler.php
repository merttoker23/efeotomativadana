<?php

namespace App\MessageHandler;

use App\Entity\Integration\B2bSyncRun;
use App\Message\RunB2bSync;
use App\Module\Integration\B2b\B2bRunProcessorInterface;
use App\Module\Integration\B2b\B2bSyncLock;
use App\Module\Integration\B2b\B2bSyncRunManager;
use App\Module\Integration\B2b\B2bSyncState;
use App\Module\Integration\B2b\Exception\B2bLockUnavailableException;
use App\Module\Integration\B2b\Exception\B2bPermanentProviderException;
use App\Module\Settings\StoreConfiguration;
use App\Repository\Integration\B2bSyncRunRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]

final readonly class RunB2bSyncHandler
{
    private const LOCK_RETRY_DELAY_MS = 60_000;

    public function __construct(
        private StoreConfiguration $configuration,
        private B2bSyncRunRepository $runs,
        private B2bSyncRunManager $runManager,
        private B2bRunProcessorInterface $processor,
        private B2bSyncLock $syncLock,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(RunB2bSync $message): void
    {
        $run = $this->runs->find($message->runId);
        if (!$run instanceof B2bSyncRun || in_array($run->state(), [B2bSyncState::Completed, B2bSyncState::Failed, B2bSyncState::Cancelled], true)) {
            return;
        }
        if ($run->mode() !== $message->mode) {
            throw new UnrecoverableMessageHandlingException('The B2B message mode does not match its durable run.');
        }
        if (!$this->configuration->isB2bEnabled()) {
            if (B2bSyncState::Running !== $run->state()) {
                $this->runManager->cancel($run);

                return;
            }
            $lock = $this->syncLock->acquire($run->providerKey());
            if (null === $lock) {
                $this->messageBus->dispatch(
                    new RunB2bSync($message->runId, $message->mode),
                    [new DelayStamp(self::LOCK_RETRY_DELAY_MS)],
                );

                return;
            }
            try {
                $this->runManager->cancel($run);
            } finally {
                $lock->release();
            }

            return;
        }

        try {
            $this->processor->process($message->runId);
        } catch (B2bLockUnavailableException) {
            $this->messageBus->dispatch(
                new RunB2bSync($message->runId, $message->mode),
                [new DelayStamp(self::LOCK_RETRY_DELAY_MS)],
            );
        } catch (B2bPermanentProviderException $exception) {
            throw new UnrecoverableMessageHandlingException('The B2B provider contract is permanently invalid.', 0, $exception);
        }
    }
}
