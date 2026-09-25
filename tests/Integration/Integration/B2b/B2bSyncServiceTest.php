<?php

namespace App\Tests\Integration\Integration\B2b;

use App\Entity\Commerce\StoreSetting;
use App\Entity\Integration\B2bSyncError;
use App\Entity\Integration\B2bSyncRun;
use App\Module\Integration\B2b\B2bDispatchResult;
use App\Module\Integration\B2b\B2bDispatchStatus;
use App\Module\Integration\B2b\B2bErrorType;
use App\Module\Integration\B2b\B2bItemError;
use App\Module\Integration\B2b\B2bProviderRegistry;
use App\Module\Integration\B2b\B2bProviderStatus;
use App\Module\Integration\B2b\B2bSyncMode;
use App\Module\Integration\B2b\B2bSyncRunManager;
use App\Module\Integration\B2b\B2bSyncService;
use App\Module\Settings\StoreConfiguration;
use App\Repository\Commerce\StoreSettingRepository;
use App\Repository\Integration\B2bSyncErrorRepository;
use App\Repository\Integration\B2bSyncRunRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class B2bSyncServiceTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private B2bSyncService $service;
    private B2bSyncRunManager $runs;
    private B2bSyncErrorRepository $errors;
    private StoreConfiguration $configuration;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM messenger_messages');
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $runRepository = $entityManager->getRepository(B2bSyncRun::class);
        $errorRepository = $entityManager->getRepository(B2bSyncError::class);
        $settingRepository = $entityManager->getRepository(StoreSetting::class);
        self::assertInstanceOf(B2bSyncRunRepository::class, $runRepository);
        self::assertInstanceOf(B2bSyncErrorRepository::class, $errorRepository);
        self::assertInstanceOf(StoreSettingRepository::class, $settingRepository);
        $this->errors = $errorRepository;
        $this->runs = new B2bSyncRunManager($runRepository, $errorRepository, $entityManager, self::getContainer()->get('doctrine'), new MockClock('2026-07-25T12:00:00+00:00'), 1000);
        $registry = new B2bProviderRegistry([new SyncServiceFakeProvider()]);
        $this->configuration = new StoreConfiguration(
            $settingRepository,
            $entityManager,
            self::getContainer()->get(ValidatorInterface::class),
            $registry,
        );
        $messageBus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $messageBus);
        $this->service = new B2bSyncService($this->configuration, $registry, $this->runs, $messageBus);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testDisabledIntegrationCreatesNoRunOrMessage(): void
    {
        $result = $this->service->request(B2bSyncMode::Daily);

        self::assertSame(B2bDispatchStatus::Disabled, $result->status());
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM integration_b2b_sync_run'));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
    }

    public function testEnabledRequestQueuesOneRunAndActiveRunCoalescesSecondRequest(): void
    {
        $settings = $this->configuration->current();
        $settings->b2bEnabled = true;
        $settings->b2bProvider = 'efe';
        $this->configuration->save($settings);

        $first = $this->service->request(B2bSyncMode::Full);
        $second = $this->service->request(B2bSyncMode::Daily);

        self::assertSame(B2bDispatchStatus::Queued, $first->status());
        self::assertNotNull($first->runId());
        self::assertSame(B2bDispatchStatus::Coalesced, $second->status());
        self::assertSame($first->runId(), $second->runId());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM integration_b2b_sync_run'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
    }

    public function testCoalescedRequestRepairsAQueuedRunWithNoMessage(): void
    {
        $settings = $this->configuration->current();
        $settings->b2bEnabled = true;
        $settings->b2bProvider = 'efe';
        $this->configuration->save($settings);
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Full)->run;

        $result = $this->service->request(B2bSyncMode::Daily);

        self::assertSame(B2bDispatchStatus::Coalesced, $result->status());
        self::assertSame($run->id(), $result->runId());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        self::assertStringContainsString('B2bSyncMode:Full', (string) $this->connection->fetchOne('SELECT body FROM messenger_messages LIMIT 1'));
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(B2bSyncRun::class, $run->id());
        self::assertInstanceOf(B2bSyncRun::class, $reloaded);
        self::assertNotNull($reloaded->messageDispatchedAt());
    }

    public function testErrorRecorderCapsRowsAndTruncatesContext(): void
    {
        $creation = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily);
        $run = $creation->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $errors = [];
        for ($index = 0; $index < 1001; ++$index) {
            $errors[] = new B2bItemError(
                B2bErrorType::InvalidItem,
                'Invalid row '.$index,
                'row-'.$index,
                ['detail' => str_repeat('x', 2000)],
            );
        }

        $persisted = $this->runs->recordErrors($run, $errors);
        $this->entityManager->clear();

        self::assertSame(1000, $persisted);
        self::assertSame(1000, $this->errors->countForRun($runId));
        $page = $this->errors->adminPageForRun($runId, 1, 1);
        $error = $page->items[0];
        self::assertSame(1000, mb_strlen((string) $error->context()['detail']));
    }
}

final class SyncServiceFakeProvider implements \App\Module\Integration\B2b\B2bFeedProviderInterface
{
    public function providerKey(): string { return 'efe'; }
    public function status(): B2bProviderStatus { return B2bProviderStatus::fromEndpoint('efe', 'https://example.com/feed'); }
    public function prepareSnapshot(\App\Module\Integration\B2b\B2bSnapshotRequest $request): \App\Module\Integration\B2b\B2bSnapshot { throw new \LogicException(); }
    public function streamItems(\App\Module\Integration\B2b\B2bSnapshot $snapshot, \App\Module\Integration\B2b\B2bSyncCheckpoint $checkpoint): iterable { return []; }
}
