<?php

namespace App\Tests\Unit\Integration\B2b;

use App\Entity\Integration\B2bSyncObservation;
use App\Entity\Integration\B2bSyncRun;
use App\Module\Integration\B2b\B2bProviderRegistry;
use App\Module\Integration\B2b\B2bProviderStatus;
use App\Module\Integration\B2b\B2bResourceType;
use App\Module\Integration\B2b\B2bRunProcessorInterface;
use App\Module\Integration\B2b\Exception\B2bLockUnavailableException;
use App\Module\Integration\B2b\B2bSyncLock;
use App\Module\Integration\B2b\B2bSyncMode;
use App\Module\Integration\B2b\B2bSyncRunManager;
use App\Module\Integration\B2b\B2bSyncService;
use App\Module\Settings\StoreConfiguration;
use App\Repository\Commerce\StoreSettingRepository;
use App\Repository\Integration\B2bSyncRunRepository;
use App\EventListener\B2bRunFailureSubscriber;
use App\Message\RunB2bSync;
use App\MessageHandler\RunB2bSyncHandler;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class B2bRunFailureSubscriberTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private B2bSyncRunManager $runs;
    private StoreConfiguration $configuration;
    private B2bSyncRunRepository $runRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $runRepository = $entityManager->getRepository(B2bSyncRun::class);
        self::assertInstanceOf(B2bSyncRunRepository::class, $runRepository);
        $this->runRepository = $runRepository;
        $errorRepository = $entityManager->getRepository(\App\Entity\Integration\B2bSyncError::class);
        self::assertInstanceOf(B2bSyncRunRepository::class, $this->runRepository);
        self::assertInstanceOf(\App\Repository\Integration\B2bSyncErrorRepository::class, $errorRepository);
        $settingRepository = $entityManager->getRepository(\App\Entity\Commerce\StoreSetting::class);
        self::assertInstanceOf(StoreSettingRepository::class, $settingRepository);
        $this->runs = new B2bSyncRunManager($this->runRepository, $errorRepository, $entityManager, self::getContainer()->get('doctrine'), new MockClock('2026-07-25T12:00:00+00:00'), 1000);
        $this->configuration = new StoreConfiguration(
            $settingRepository,
            $entityManager,
            self::getContainer()->get(ValidatorInterface::class),
            new B2bProviderRegistry([new HandlerFakeProvider()]),
        );
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testDisabledHandlerCancelsDispatchedRunWithoutCallingProcessor(): void
    {
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $processor = new class implements B2bRunProcessorInterface {
            public bool $called = false;
            public function process(int $runId): void { $this->called = true; }
        };
        $messageBus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $messageBus);
        $handler = new RunB2bSyncHandler($this->configuration, $this->runRepository, $this->runs, $processor, new B2bSyncLock($this->connection), $messageBus);

        $handler(new RunB2bSync($runId, B2bSyncMode::Daily));

        self::assertFalse($processor->called);
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(B2bSyncRun::class, $runId);
        self::assertInstanceOf(B2bSyncRun::class, $reloaded);
        self::assertSame('cancelled', $reloaded->state()->value);
        self::assertNull($reloaded->activeProviderKey());
    }

    public function testLockContentionSchedulesADelayedRedeliveryInsteadOfAcknowledgingTheRun(): void
    {
        $this->connection->update('store_setting', ['value' => 'true'], ['setting_key' => 'commerce.b2b_enabled']);
        $this->entityManager->clear();
        $configuration = self::getContainer()->get(StoreConfiguration::class);
        self::assertInstanceOf(StoreConfiguration::class, $configuration);
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $processor = new class implements B2bRunProcessorInterface {
            public function process(int $runId): void
            {
                throw new B2bLockUnavailableException();
            }
        };
        $bus = new class implements MessageBusInterface {
            public ?Envelope $envelope = null;

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return $this->envelope = new Envelope($message, $stamps);
            }
        };
        $handler = new RunB2bSyncHandler($configuration, $this->runRepository, $this->runs, $processor, new B2bSyncLock($this->connection), $bus);

        $handler(new RunB2bSync($runId, B2bSyncMode::Daily));

        self::assertInstanceOf(Envelope::class, $bus->envelope);
        self::assertInstanceOf(RunB2bSync::class, $bus->envelope->getMessage());
        $delay = $bus->envelope->last(DelayStamp::class);
        self::assertInstanceOf(DelayStamp::class, $delay);
        self::assertSame(60_000, $delay->getDelay());
    }

    public function testLateFailureEventsAreIgnoredForTerminalRuns(): void
    {
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->runs->markRunning($run);
        $this->runs->fail($run, 'already terminal');
        $subscriber = new B2bRunFailureSubscriber($this->runRepository, $this->runs);
        $message = new RunB2bSync($runId, B2bSyncMode::Daily);

        $retry = new WorkerMessageFailedEvent(new Envelope($message), 'async', new \RuntimeException('late retry'));
        $retry->setForRetry();
        $subscriber->onMessageFailed($retry);
        $subscriber->onMessageFailed(new WorkerMessageFailedEvent(new Envelope($message), 'async', new \RuntimeException('late final')));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(B2bSyncRun::class, $runId);
        self::assertInstanceOf(B2bSyncRun::class, $reloaded);
        self::assertSame('failed', $reloaded->state()->value);
    }

    public function testRetryableFailureKeepsRunQueuedAndFinalFailureTerminatesIt(): void
    {
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->runs->markRunning($run);
        $this->entityManager->persist(B2bSyncObservation::create(
            $run,
            '1001',
            B2bResourceType::Product,
            0,
            str_repeat('a', 64),
            new \DateTimeImmutable('2026-09-25T10:00:00+00:00'),
            'RETRY-SKU',
        ));
        $this->entityManager->flush();
        $subscriber = new B2bRunFailureSubscriber($this->runRepository, $this->runs);
        $message = new RunB2bSync($runId, B2bSyncMode::Daily);

        $retry = new WorkerMessageFailedEvent(new Envelope($message), 'async', new \RuntimeException('temporary'));
        $retry->setForRetry();
        $subscriber->onMessageFailed($retry);
        $this->entityManager->clear();
        $queued = $this->entityManager->find(B2bSyncRun::class, $runId);
        self::assertInstanceOf(B2bSyncRun::class, $queued);
        self::assertSame('queued', $queued->state()->value);
        self::assertSame('efe', $queued->activeProviderKey());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM integration_b2b_sync_observation WHERE run_id = '.(int) $runId));

        $final = new WorkerMessageFailedEvent(new Envelope($message), 'async', new \RuntimeException('permanent'));
        $subscriber->onMessageFailed($final);
        $this->entityManager->clear();
        $failed = $this->entityManager->find(B2bSyncRun::class, $runId);
        self::assertInstanceOf(B2bSyncRun::class, $failed);
        self::assertSame('failed', $failed->state()->value);
        self::assertNull($failed->activeProviderKey());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM integration_b2b_sync_observation WHERE run_id = '.(int) $runId));
    }
}

final class HandlerFakeProvider implements \App\Module\Integration\B2b\B2bFeedProviderInterface
{
    public function providerKey(): string { return 'efe'; }
    public function status(): B2bProviderStatus { return B2bProviderStatus::fromEndpoint('efe', 'https://example.com/feed'); }
    public function prepareSnapshot(\App\Module\Integration\B2b\B2bSnapshotRequest $request): \App\Module\Integration\B2b\B2bSnapshot { throw new \LogicException(); }
    public function streamItems(\App\Module\Integration\B2b\B2bSnapshot $snapshot, \App\Module\Integration\B2b\B2bSyncCheckpoint $checkpoint): iterable { return []; }
}
