<?php

namespace App\Tests\Integration\Integration\B2b;

use App\Entity\Integration\B2bSyncRun;
use App\Module\Catalog\CatalogManager;
use App\Module\Integration\B2b\B2bBatchProcessor;
use App\Module\Integration\B2b\B2bCatalogWriter;
use App\Module\Integration\B2b\B2bDailyReconciler;
use App\Module\Integration\B2b\B2bFeedProviderInterface;
use App\Module\Integration\B2b\B2bFeedRecord;
use App\Module\Integration\B2b\B2bProviderRegistry;
use App\Module\Integration\B2b\B2bProviderStatus;
use App\Module\Integration\B2b\B2bRunProcessor;
use App\Module\Integration\B2b\B2bSnapshot;
use App\Module\Integration\B2b\B2bSnapshotCleaner;
use App\Module\Integration\B2b\B2bSnapshotRequest;
use App\Module\Integration\B2b\B2bSyncCheckpoint;
use App\Module\Integration\B2b\B2bSyncLock;
use App\Module\Integration\B2b\B2bSyncMode;
use App\Module\Integration\B2b\B2bSyncRunManager;
use App\Module\Integration\B2b\Exception\B2bPermanentProviderException;
use App\Module\Integration\B2b\Provider\Efe\EfeFeedNormalizer;
use App\Module\Integration\B2b\Provider\Efe\EfeFeedProvider;
use App\Module\Integration\B2b\Provider\Efe\EfePriceNormalizer;
use App\Module\Integration\B2b\Provider\Efe\EfeSnapshotDownloader;
use App\Module\Inventory\InventoryManager;
use App\Module\Inventory\ProductInventoryRepositoryInterface;
use App\Module\Pricing\PercentageDiscountCalculator;
use App\Module\Pricing\PricingManager;
use App\Module\Pricing\ProductPriceRepositoryInterface;
use App\Module\Pricing\TaxCalculator;
use App\Repository\Integration\B2bSyncErrorRepository;
use App\Repository\Integration\B2bSyncRunRepository;
use App\Repository\Integration\ExternalResourceMappingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * PHASE_12 FULL runs are long enough to be interrupted in production. The persisted checkpoint is
 * only useful when the provider stream can be matched against it, so these tests pin the record
 * position contract that resume depends on.
 */
final class B2bSyncResumeTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private B2bSyncRunManager $runs;
    private string $directory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->directory = sys_get_temp_dir().'/efe-b2b-resume-'.bin2hex(random_bytes(5));
        mkdir($this->directory, 0755, true);
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $runRepository = $entityManager->getRepository(B2bSyncRun::class);
        $errorRepository = $entityManager->getRepository(\App\Entity\Integration\B2bSyncError::class);
        self::assertInstanceOf(B2bSyncRunRepository::class, $runRepository);
        self::assertInstanceOf(B2bSyncErrorRepository::class, $errorRepository);
        $this->runs = new B2bSyncRunManager(
            $runRepository,
            $errorRepository,
            $entityManager,
            self::getContainer()->get('doctrine'),
            new MockClock('2026-07-25T12:00:00+00:00'),
            1000,
        );
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        if (is_dir($this->directory)) {
            foreach (new \FilesystemIterator($this->directory) as $file) {
                if ($file->isFile()) {
                    @chmod($file->getPathname(), 0644);
                    @unlink($file->getPathname());
                }
            }
            @rmdir($this->directory);
        }
        parent::tearDown();
    }

    /**
     * The reported production failure: EfeFeedProvider yielded auto-numbered iterable keys, so
     * every record after the checkpoint arrived as position 0 while the processor expected the
     * checkpoint. Each retry re-read the same snapshot and failed identically until the run was
     * marked Failed for good.
     */
    public function testInterruptedFullRunResumesFromItsCheckpointWithTheRealProvider(): void
    {
        $provider = new InterruptingEfeProvider($this->efeProvider());
        $processor = $this->processor($provider);
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Full)->run;
        $runId = $run->id();
        self::assertNotNull($runId);

        try {
            $processor->process($runId);
            self::fail('The first attempt must be interrupted after the first committed batch.');
        } catch (\RuntimeException) {
            $this->entityManager->clear();
            $run = $this->entityManager->find(B2bSyncRun::class, $runId);
            self::assertInstanceOf(B2bSyncRun::class, $run);
            self::assertSame(1, $run->checkpoint());
        }

        $provider->interrupt = false;
        $this->runs->retry($run, 'resume the interrupted full run');
        $processor->process($runId);

        self::assertSame('completed', $this->connection->fetchOne("SELECT state FROM integration_b2b_sync_run WHERE id = ".(int) $runId));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM integration_external_mapping WHERE resource_type = 'product'"));
    }

    /**
     * A provider that replays a record the run already handled must not reprocess it: the batch
     * writer rejects a second visit to the same external ID as a conflict, which would turn a
     * harmless provider hiccup into a failed catalog run.
     */
    public function testReplayedRecordPositionIsIgnoredWithoutReprocessingTheProduct(): void
    {
        $records = $this->records();
        $provider = new ScriptedPositionProvider($this->directory, [
            [0, $records[0]],
            [1, $records[1]],
            [1, $records[1]],
        ], 2);
        $processor = $this->processor($provider);
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Full)->run;
        $runId = $run->id();
        self::assertNotNull($runId);

        $processor->process($runId);

        self::assertSame('completed', $this->connection->fetchOne("SELECT state FROM integration_b2b_sync_run WHERE id = ".(int) $runId));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM integration_external_mapping WHERE resource_type = 'product'"));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM integration_b2b_sync_error WHERE run_id = ".(int) $runId." AND error_type = 'conflict'"));
    }

    /**
     * A position beyond the expected one means the snapshot has a hole. Continuing would import a
     * catalog that silently misses records, so the run must stop with a permanent, diagnosable
     * failure instead of retrying the identical immutable snapshot forever.
     */
    public function testSkippedRecordPositionStopsTheRunInsteadOfImportingAGap(): void
    {
        $records = $this->records();
        $provider = new ScriptedPositionProvider($this->directory, [
            [0, $records[0]],
            [2, $records[1]],
        ], 2);
        $processor = $this->processor($provider);
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Full)->run;
        $runId = $run->id();
        self::assertNotNull($runId);

        try {
            $processor->process($runId);
            self::fail('A skipped position must not be imported as if the snapshot were complete.');
        } catch (B2bPermanentProviderException) {
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
            self::assertSame('running', $this->connection->fetchOne("SELECT state FROM integration_b2b_sync_run WHERE id = ".(int) $runId));
        }
    }

    public function testNonNumericRecordPositionIsRefusedInsteadOfSilentlyCastToZero(): void
    {
        $records = $this->records();
        $provider = new ScriptedPositionProvider($this->directory, [
            ['not-a-position', $records[0]],
            [1, $records[1]],
        ], 2);
        $processor = $this->processor($provider);
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Full)->run;
        $runId = $run->id();
        self::assertNotNull($runId);

        $this->expectException(B2bPermanentProviderException::class);
        $processor->process($runId);
    }

    /** @return list<B2bFeedRecord> */
    private function records(): array
    {
        return [
            B2bFeedRecord::success($this->item(0)),
            B2bFeedRecord::success($this->item(1)),
        ];
    }

    private function item(int $index): \App\Module\Integration\B2b\NormalizedCatalogFeedItem
    {
        return $this->normalizer()->normalize($this->fixtureRecord($index));
    }

    /** @return array<string, mixed> */
    private function fixtureRecord(int $index): array
    {
        $json = file_get_contents(__DIR__.'/../../../Fixtures/Integration/Efe/efe-feed-sanitized.json');
        self::assertIsString($json);
        $fixture = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertIsArray($fixture['data'][$index]);

        return $fixture['data'][$index];
    }

    private function fixture(): string
    {
        $json = file_get_contents(__DIR__.'/../../../Fixtures/Integration/Efe/efe-feed-sanitized.json');
        self::assertIsString($json);

        return $json;
    }

    private function normalizer(): EfeFeedNormalizer
    {
        return new EfeFeedNormalizer(
            new EfePriceNormalizer(new TaxCalculator(), new PercentageDiscountCalculator()),
            ['b2b.efeotoyedekparca.com.tr'],
        );
    }

    private function efeProvider(): EfeFeedProvider
    {
        $url = 'https://b2b.efeotoyedekparca.com.tr/feed.json';
        $downloader = new EfeSnapshotDownloader(
            httpClient: new MockHttpClient(new MockResponse($this->fixture(), ['response_headers' => ['content-type: application/json']])),
            endpointUrl: $url,
            allowedHosts: ['b2b.efeotoyedekparca.com.tr'],
            snapshotDirectory: $this->directory,
            maxBytes: 1_000_000,
            maxConnectDuration: 20,
            readTimeout: 120,
            overallTimeout: 600,
            maxRedirects: 3,
            caBundle: null,
        );

        return new EfeFeedProvider($downloader, $this->normalizer(), $url);
    }

    private function processor(B2bFeedProviderInterface $provider): B2bRunProcessor
    {
        $entityManager = $this->entityManager;
        $mappingRepository = $entityManager->getRepository(\App\Entity\Integration\ExternalResourceMapping::class);
        self::assertInstanceOf(ExternalResourceMappingRepository::class, $mappingRepository);
        $writer = new B2bCatalogWriter(
            resources: self::getContainer()->get(\App\Module\Integration\B2b\B2bResourceResolver::class),
            catalog: self::getContainer()->get(CatalogManager::class),
            media: new InMemoryProductMediaStorage(),
            pricing: self::getContainer()->get(PricingManager::class),
            inventory: self::getContainer()->get(InventoryManager::class),
            prices: self::getContainer()->get(ProductPriceRepositoryInterface::class),
            inventoryRepository: self::getContainer()->get(ProductInventoryRepositoryInterface::class),
            entityManager: $entityManager,
            clock: new MockClock('2026-07-25T12:00:00+00:00'),
        );
        $resources = self::getContainer()->get(\App\Module\Integration\B2b\B2bResourceResolver::class);
        self::assertInstanceOf(\App\Module\Integration\B2b\B2bResourceResolver::class, $resources);

        return new B2bRunProcessor(
            runs: self::getContainer()->get(B2bSyncRunRepository::class),
            runManager: $this->runs,
            providers: new B2bProviderRegistry([$provider]),
            batchProcessor: new B2bBatchProcessor($writer, $this->runs, $resources, $entityManager, new MockClock('2026-07-25T12:00:00+00:00')),
            reconciler: new B2bDailyReconciler(
                $mappingRepository,
                $entityManager,
                self::getContainer()->get(ProductInventoryRepositoryInterface::class),
                self::getContainer()->get(InventoryManager::class),
            ),
            lock: new B2bSyncLock($this->connection),
            cleaner: new B2bSnapshotCleaner($this->directory, new MockClock('2026-07-25T12:00:00+00:00')),
            entityManager: $entityManager,
            snapshotDirectory: $this->directory,
            batchSize: 1,
            logger: self::getContainer()->get(\Psr\Log\LoggerInterface::class),
            clock: new MockClock('2026-07-25T12:00:00+00:00'),
        );
    }
}

/**
 * Interrupts the real Efe stream once, after the first record, to reproduce a worker restart in
 * the middle of a FULL run without inventing a provider.
 */
final class InterruptingEfeProvider implements B2bFeedProviderInterface
{
    public bool $interrupt = true;

    public function __construct(private readonly EfeFeedProvider $inner)
    {
    }

    public function providerKey(): string { return $this->inner->providerKey(); }
    public function status(): B2bProviderStatus { return $this->inner->status(); }
    public function prepareSnapshot(B2bSnapshotRequest $request): B2bSnapshot { return $this->inner->prepareSnapshot($request); }

    public function streamItems(B2bSnapshot $snapshot, B2bSyncCheckpoint $checkpoint): iterable
    {
        foreach ($this->inner->streamItems($snapshot, $checkpoint) as $position => $record) {
            yield $position => $record;
            if ($this->interrupt && 0 === $position) {
                throw new \RuntimeException('simulated worker interruption');
            }
        }
    }
}

/**
 * Yields an explicit position script so replay, gap and malformed position paths can be driven.
 * The script is a list of pairs rather than a keyed array, because a replayed position must be
 * emittable twice and PHP arrays cannot hold a duplicate key.
 */
final class ScriptedPositionProvider implements B2bFeedProviderInterface
{
    /** @param list<array{int|string, B2bFeedRecord}> $script */
    public function __construct(
        private readonly string $directory,
        private readonly array $script,
        private readonly ?int $declaredCount = null,
    ) {
    }

    public function providerKey(): string { return 'efe'; }
    public function status(): B2bProviderStatus { return B2bProviderStatus::fromEndpoint('efe', 'https://example.com/feed'); }

    public function prepareSnapshot(B2bSnapshotRequest $request): B2bSnapshot
    {
        $path = $this->directory.'/'.$request->runId.'.json';
        $declaredCount = $this->declaredCount ?? count($this->script);
        file_put_contents($path, '{"ok":true,"count":'.$declaredCount.',"data":[]}');

        return new B2bSnapshot($path, filesize($path) ?: 1, $declaredCount, hash_file('sha256', $path) ?: str_repeat('0', 64));
    }

    public function streamItems(B2bSnapshot $snapshot, B2bSyncCheckpoint $checkpoint): iterable
    {
        foreach ($this->script as [$position, $record]) {
            yield $position => $record;
        }
    }
}
