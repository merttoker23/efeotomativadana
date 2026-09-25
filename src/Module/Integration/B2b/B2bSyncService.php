<?php

namespace App\Module\Integration\B2b;

use App\Entity\Integration\B2bSyncRun;
use App\Message\RunB2bSync;
use App\Module\Settings\StoreConfiguration;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class B2bSyncService implements B2bSyncServiceInterface
{
    public function __construct(
        private StoreConfiguration $configuration,
        private B2bProviderRegistry $providers,
        private B2bSyncRunManager $runs,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function request(B2bSyncMode $mode): B2bDispatchResult
    {
        if (!$this->configuration->isB2bEnabled()) {
            return B2bDispatchResult::disabled();
        }
        $providerKey = $this->configuration->b2bProvider();
        if (null === $providerKey || !$this->providers->supports($providerKey)) {
            return B2bDispatchResult::rejected('B2B provider is not supported.');
        }
        if (!$this->providers->get($providerKey)->status()->configured()) {
            return B2bDispatchResult::rejected('B2B provider endpoint is not configured safely.');
        }

        $creation = $this->runs->createOrGetActive($providerKey, $mode);
        $runId = $creation->run->id();
        if (null === $runId) {
            throw new \RuntimeException('The B2B run was queued without an identifier.');
        }
        if (!$creation->created) {
            if (null === $creation->run->messageDispatchedAt() && B2bSyncState::Queued === $creation->run->state()) {
                $this->dispatch($creation->run, $creation->run->mode());
            }

            return B2bDispatchResult::coalesced($runId, $creation->run->mode());
        }

        $this->dispatch($creation->run, $mode);

        return B2bDispatchResult::queued($runId, $mode);
    }

    private function dispatch(B2bSyncRun $run, B2bSyncMode $mode): void
    {
        $runId = $run->id();
        if (null === $runId) {
            throw new \RuntimeException('The B2B run was queued without an identifier.');
        }
        try {
            $this->messageBus->dispatch(new RunB2bSync($runId, $mode));
        } catch (\Throwable $exception) {
            $this->runs->fail($run, 'B2B message could not be queued.');
            throw $exception;
        }
        $this->runs->markMessageDispatched($run);
    }
}
