<?php

namespace App\Tests\Integration\Integration\B2b;

use App\Entity\Integration\B2bSyncError;
use App\Entity\Integration\B2bSyncObservation;
use App\Entity\Integration\B2bSyncRun;
use App\Entity\Integration\ExternalResourceMapping;
use App\Module\Integration\B2b\B2bErrorType;
use App\Module\Integration\B2b\B2bResourceType;
use App\Module\Integration\B2b\B2bSyncCounters;
use App\Module\Integration\B2b\B2bSyncMode;
use App\Module\Integration\B2b\B2bSyncState;
use App\Repository\Integration\B2bSyncErrorRepository;
use App\Repository\Integration\B2bSyncObservationRepository;
use App\Repository\Integration\B2bSyncRunRepository;
use App\Repository\Integration\ExternalResourceMappingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class B2bPersistenceTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testMappingNormalizesIdentityAndTracksTheLastSeenRun(): void
    {
        $firstSeen = new \DateTimeImmutable('2026-09-25T10:00:00+00:00');
        $lastSeen = new \DateTimeImmutable('2026-09-25T11:00:00+00:00');
        $mapping = ExternalResourceMapping::product(' EFE ', ' 1001 ', 501, 9001, $firstSeen);
        $mapping->seenIn(9002, $lastSeen);

        self::assertSame('efe', $mapping->providerKey());
        self::assertSame(B2bResourceType::Product, $mapping->resourceType());
        self::assertSame('1001', $mapping->externalId());
        self::assertSame('product', $mapping->localResourceType());
        self::assertSame('501', $mapping->localResourceId());
        self::assertSame(9002, $mapping->lastSeenRunId());
        self::assertSame(2, $mapping->identityVersion());
        self::assertEquals($firstSeen, $mapping->firstSeenAt());
        self::assertEquals($lastSeen, $mapping->lastSeenAt());
        self::assertEquals($lastSeen, $mapping->updatedAt());
    }

    public function testDatabaseRejectsDuplicateProviderResourceExternalIdentity(): void
    {
        $now = new \DateTimeImmutable();
        $this->entityManager->persist(ExternalResourceMapping::product('efe', '1001', 501, 9001, $now));
        $this->entityManager->persist(ExternalResourceMapping::product('efe', '1001', 777, 9001, $now));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testRunObservationsPersistOneDurableClaimPerExternalId(): void
    {
        $now = new \DateTimeImmutable('2026-09-25T10:00:00+00:00');
        $run = B2bSyncRun::queue('efe', B2bSyncMode::Daily, $now);
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $this->entityManager->persist(B2bSyncObservation::create(
            $run,
            '1001',
            B2bResourceType::Product,
            0,
            str_repeat('a', 64),
            $now,
            'CLAIM-1001',
        ));
        $this->entityManager->persist(B2bSyncObservation::create(
            $run,
            '1002',
            B2bResourceType::Product,
            1,
            str_repeat('b', 64),
            $now,
            'CLAIM-1002',
        ));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $repository = $this->entityManager->getRepository(B2bSyncObservation::class);
        self::assertInstanceOf(B2bSyncObservationRepository::class, $repository);
        $observations = $repository->findByRunAndExternalIds($run->id() ?? 0, 'efe', B2bResourceType::Product, ['1002', 'missing', '1001']);
        self::assertCount(2, $observations);
        self::assertSame(['1001', '1002'], array_map(static fn (B2bSyncObservation $observation): string => $observation->externalId(), $observations));
        self::assertSame(0, $observations[0]->streamPosition());
        self::assertSame(1, $observations[1]->streamPosition());
        self::assertSame('CLAIM-1001', $observations[0]->sku());
        self::assertSame('CLAIM-1002', $observations[1]->sku());
    }

    public function testDatabaseRejectsDuplicateExternalIdClaimsWithinOneRun(): void
    {
        $now = new \DateTimeImmutable('2026-09-25T10:00:00+00:00');
        $run = B2bSyncRun::queue('efe', B2bSyncMode::Daily, $now);
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $this->entityManager->persist(B2bSyncObservation::create($run, '1001', B2bResourceType::Product, 0, str_repeat('a', 64), $now));
        $this->entityManager->persist(B2bSyncObservation::create($run, '1001', B2bResourceType::Product, 1, str_repeat('b', 64), $now));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testDifferentResourceTypesMayShareAnExternalId(): void
    {
        $now = new \DateTimeImmutable();
        $this->entityManager->persist(ExternalResourceMapping::create(
            'efe',
            B2bResourceType::Category,
            'STOP',
            'category',
            301,
            $now,
            9001,
        ));
        $this->entityManager->persist(ExternalResourceMapping::create(
            'efe',
            B2bResourceType::Product,
            'STOP',
            'product',
            501,
            $now,
            9001,
        ));
        $this->entityManager->flush();

        self::assertSame(
            ['category', 'product'],
            $this->connection->fetchFirstColumn(
                "SELECT resource_type FROM integration_external_mapping WHERE provider_key = 'efe' AND external_id = 'STOP' ORDER BY resource_type",
            ),
        );
    }

    public function testOnlyOneRunCanRemainActiveForAProvider(): void
    {
        $now = new \DateTimeImmutable();
        $this->entityManager->persist(B2bSyncRun::queue('efe', B2bSyncMode::Full, $now));
        $this->entityManager->persist(B2bSyncRun::queue('efe', B2bSyncMode::Daily, $now));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testRunLifecyclePersistsSnapshotCountersCheckpointAndClearsActiveKey(): void
    {
        $queuedAt = new \DateTimeImmutable('2026-09-25T10:00:00+00:00');
        $startedAt = $queuedAt->modify('+1 minute');
        $checkpointedAt = $startedAt->modify('+2 minutes');
        $completedAt = $checkpointedAt->modify('+3 minutes');
        $run = B2bSyncRun::queue('efe', B2bSyncMode::Full, $queuedAt);
        $counters = B2bSyncCounters::fromArray([
            'scanned' => 250,
            'created' => 249,
            'skipped' => 1,
            'images_imported' => 7,
        ]);

        $run->markRunning($startedAt);
        $run->recordSnapshot('/var/b2b-snapshots/efe/1.json', 92175, str_repeat('a', 64), $startedAt);
        $run->recordBatch($counters, 250, $checkpointedAt);
        $run->complete($completedAt);
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->entityManager->clear();

        $repository = $this->entityManager->getRepository(B2bSyncRun::class);
        self::assertInstanceOf(B2bSyncRunRepository::class, $repository);
        $reloaded = $repository->find($runId);
        self::assertInstanceOf(B2bSyncRun::class, $reloaded);
        self::assertSame(B2bSyncState::Completed, $reloaded->state());
        self::assertNull($reloaded->activeProviderKey());
        self::assertNull($reloaded->latestError());
        self::assertSame('/var/b2b-snapshots/efe/1.json', $reloaded->snapshotPath());
        self::assertSame(92175, $reloaded->declaredCount());
        self::assertSame(250, $reloaded->checkpoint());
        self::assertSame(250, $reloaded->counters()->scanned());
        self::assertSame(249, $reloaded->counters()->created());
        self::assertSame(1, $reloaded->counters()->skipped());
        self::assertSame(7, $reloaded->counters()->imagesImported());
        self::assertEquals($completedAt, $reloaded->finishedAt());
        self::assertNull($repository->findActive('efe'));
        self::assertSame($reloaded->id(), $repository->latestSuccessful('efe', B2bSyncMode::Full)?->id());
    }

    public function testResumingARunClearsThePreviousRetryError(): void
    {
        $now = new \DateTimeImmutable('2026-09-25T10:00:00+00:00');
        $run = B2bSyncRun::queue('efe', B2bSyncMode::Full, $now);
        $run->markRunning($now->modify('+1 minute'));
        $run->retry('temporary timeout', $now->modify('+2 minutes'));
        self::assertSame('temporary timeout', $run->latestError());

        $run->markRunning($now->modify('+3 minutes'));

        self::assertSame(B2bSyncState::Running, $run->state());
        self::assertNull($run->latestError());
    }

    public function testRetryKeepsRunActiveAndFinalFailureTerminatesIt(): void
    {
        $now = new \DateTimeImmutable('2026-09-25T10:00:00+00:00');
        $run = B2bSyncRun::queue('efe', B2bSyncMode::Daily, $now);
        $run->markRunning($now->modify('+1 minute'));
        $run->retry('temporary timeout', $now->modify('+2 minutes'));

        self::assertSame(B2bSyncState::Queued, $run->state());
        self::assertSame('efe', $run->activeProviderKey());
        self::assertSame('temporary timeout', $run->latestError());

        $run->fail('permanent failure', $now->modify('+3 minutes'));
        self::assertSame(B2bSyncState::Failed, $run->state());
        self::assertNull($run->activeProviderKey());
        self::assertSame('permanent failure', $run->latestError());
    }

    public function testBoundedErrorsPersistAndRepositoriesUseSetBasedSeenLookups(): void
    {
        $now = new \DateTimeImmutable('2026-09-25T10:00:00+00:00');
        $run = B2bSyncRun::queue('efe', B2bSyncMode::Daily, $now);
        $this->entityManager->persist($run);
        $this->entityManager->persist(ExternalResourceMapping::product('efe', '1001', 501, 9001, $now));
        $this->entityManager->persist(ExternalResourceMapping::product('efe', '1002', 502, 9001, $now));
        $this->entityManager->persist(ExternalResourceMapping::create(
            'efe',
            B2bResourceType::Category,
            '1001',
            'category',
            301,
            $now,
            9001,
        ));
        $this->entityManager->persist(B2bSyncError::create(
            $run,
            '1002',
            B2bErrorType::Conflict,
            false,
            'SKU already belongs to an unrelated product.',
            ['sku' => 'COLLISION'],
            $now->modify('+1 minute'),
        ));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $mappingRepository = $this->entityManager->getRepository(ExternalResourceMapping::class);
        self::assertInstanceOf(ExternalResourceMappingRepository::class, $mappingRepository);
        $productMappings = $mappingRepository->findByExternalIds('efe', B2bResourceType::Product, ['1001', 'missing']);
        self::assertCount(1, $productMappings);
        self::assertSame('1001', $productMappings[0]->externalId());
        self::assertNull($mappingRepository->findOneByExternalId('efe', B2bResourceType::Product, 'missing'));
        self::assertSame([501, 502], $mappingRepository->findProductIdsNotSeen('efe', 9002));

        $errorRepository = $this->entityManager->getRepository(B2bSyncError::class);
        self::assertInstanceOf(B2bSyncErrorRepository::class, $errorRepository);
        self::assertSame(1, $errorRepository->countForRun($run->id() ?? 0));
    }

    public function testUnknownCounterKeysAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        B2bSyncCounters::fromArray(['scanned' => 1, 'not_a_counter' => 2]);
    }
}
