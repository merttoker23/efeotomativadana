<?php

namespace App\EventListener;

use App\Entity\Integration\B2bSyncRun;
use App\Message\RunB2bSync;
use App\Module\Integration\B2b\B2bSyncRunManager;
use App\Module\Integration\B2b\B2bSyncState;
use App\Repository\Integration\B2bSyncRunRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final readonly class B2bRunFailureSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private B2bSyncRunRepository $runs,
        private B2bSyncRunManager $runManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageFailedEvent::class => 'onMessageFailed'];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof RunB2bSync) {
            return;
        }
        $run = $this->runs->find($message->runId);
        if (!$run instanceof B2bSyncRun) {
            return;
        }
        if (in_array($run->state(), [B2bSyncState::Completed, B2bSyncState::Failed, B2bSyncState::Cancelled], true)) {
            return;
        }
        if ($event->willRetry()) {
            $this->runManager->retry($run, 'B2B synchronization will retry after a temporary failure.');

            return;
        }
        $this->runManager->fail($run, 'B2B synchronization failed permanently.');
    }
}
