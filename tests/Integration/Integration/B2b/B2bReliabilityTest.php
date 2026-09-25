<?php

namespace App\Tests\Integration\Integration\B2b;

use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Integration\ExternalResourceMapping;
use App\Module\Catalog\CatalogManager;
use App\Module\Catalog\CatalogSource;
use App\Module\Integration\B2b\B2bBatchProcessor;
use App\Module\Integration\B2b\B2bCatalogWriter;
use App\Module\Integration\B2b\B2bErrorType;
use App\Module\Integration\B2b\B2bResourceType;
use App\Module\Integration\B2b\B2bFeedRecord;
use App\Module\Integration\B2b\B2bSyncMode;
use App\Module\Integration\B2b\B2bSyncRunManager;
use App\Module\Integration\B2b\ProductMediaStorageInterface;
use App\Module\Integration\B2b\Provider\Efe\EfeFeedNormalizer;
use App\Module\Integration\B2b\Provider\Efe\EfePriceNormalizer;
use App\Module\Integration\B2b\StoredProductImage;
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
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockInterface;

final class B2bReliabilityTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private B2bCatalogWriter $writer;
    private B2bSyncRunManager $runs;
    private B2bBatchProcessor $batches;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $runRepository = $entityManager->getRepository(\App\Entity\Integration\B2bSyncRun::class);
        $errorRepository = $entityManager->getRepository(\App\Entity\Integration\B2bSyncError::class);
        self::assertInstanceOf(B2bSyncRunRepository::class, $runRepository);
        self::assertInstanceOf(B2bSyncErrorRepository::class, $errorRepository);
        $this->writer = new B2bCatalogWriter(
            resources: self::getContainer()->get(\App\Module\Integration\B2b\B2bResourceResolver::class),
            catalog: self::getContainer()->get(CatalogManager::class),
            media: new ReliabilityProductMediaStorage(),
            pricing: self::getContainer()->get(PricingManager::class),
            inventory: self::getContainer()->get(InventoryManager::class),
            prices: self::getContainer()->get(ProductPriceRepositoryInterface::class),
            inventoryRepository: self::getContainer()->get(ProductInventoryRepositoryInterface::class),
            entityManager: $entityManager,
            clock: new MockClock('2026-07-25T12:00:00+00:00'),
        );
        $this->runs = new B2bSyncRunManager(
            $runRepository,
            $errorRepository,
            $entityManager,
            self::getContainer()->get('doctrine'),
            new MockClock('2026-07-25T12:00:00+00:00'),
            1000,
        );
        $this->batches = new B2bBatchProcessor(
            $this->writer,
            $this->runs,
            self::getContainer()->get(\App\Module\Integration\B2b\B2bResourceResolver::class),
            $entityManager,
            new MockClock('2026-07-25T12:00:00+00:00'),
        );
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testUnexpectedBatchFailureRollsBackAndRecoversEachRowIndependently(): void
    {
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->entityManager->persist(ExternalResourceMapping::product(
            'efe',
            '1001',
            999_999,
            $runId,
            new \DateTimeImmutable('2026-07-25T11:00:00+00:00'),
        ));
        $this->entityManager->flush();

        $result = $this->batches->process([
            B2bFeedRecord::success($this->item(1)),
            B2bFeedRecord::success($this->item(0)),
        ], B2bSyncMode::Daily, $run);

        self::assertSame(1, $result->counters->created());
        self::assertSame(1, $result->counters->skipped());
        self::assertCount(1, $result->errors);
        self::assertSame(B2bErrorType::Database, $result->errors[0]->errorType());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
    }

    public function testFeedSkuCannotBeClaimedByDifferentExternalIdsAcrossABatch(): void
    {
        $mappedRecord = $this->fixtureRecord(0);
        $mappedRecord['stokkodu'] = 'SHARED-FEED-SKU';
        $mappedRecord['urunresimleri'] = [];
        $mapped = $this->writer->importFull($this->normalizer()->normalize($mappedRecord), 1);
        self::assertTrue($mapped->isSuccess());
        $firstRecord = $mappedRecord;
        $firstRecord['stokkodu'] = 'SHARED-FEED-SKU';
        $firstRecord['urunresimleri'] = [];
        $secondRecord = $this->fixtureRecord(1);
        $secondRecord['id'] = '1002';
        $secondRecord['stokkodu'] = 'SHARED-FEED-SKU';
        $secondRecord['urunresimleri'] = [];
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Full)->run;

        $runId = $run->id();
        self::assertNotNull($runId);
        $this->batches->beginRun($runId);
        try {
            $this->batches->process([
                B2bFeedRecord::success($this->normalizer()->normalize($firstRecord)),
            ], B2bSyncMode::Full, $run);
            $result = $this->batches->process([
                B2bFeedRecord::success($this->normalizer()->normalize($secondRecord)),
            ], B2bSyncMode::Full, $run);
        } finally {
            $this->batches->endRun($runId);
        }

        self::assertSame(1, $result->counters->conflicts());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
    }

    public function testSkuClaimSurvivesAWorkerRestartAfterAConflict(): void
    {
        $firstRecord = $this->fixtureRecord(0);
        $firstRecord['id'] = '2001';
        $firstRecord['stokkodu'] = 'DURABLE-SKU-CLAIM';
        $firstRecord['urunresimleri'] = [];
        $firstItem = $this->normalizer()->normalize($firstRecord);
        $localCategory = new Category('Local category that must not be trusted', 'local-durable-sku-conflict', CatalogSource::Local);
        $this->entityManager->persist($localCategory);
        $this->entityManager->flush();
        $localCategoryId = $localCategory->id();
        self::assertNotNull($localCategoryId);
        $this->entityManager->persist(ExternalResourceMapping::create(
            'efe',
            B2bResourceType::Category,
            'efe:category:'.$firstItem->categoryExternalId(),
            'category',
            $localCategoryId,
            new \DateTimeImmutable('2026-07-25T11:00:00+00:00'),
            null,
        ));
        $this->entityManager->flush();
        $categoryMappingRepository = self::getContainer()->get(ExternalResourceMappingRepository::class);
        self::assertInstanceOf(ExternalResourceMappingRepository::class, $categoryMappingRepository);
        self::assertNotNull($categoryMappingRepository->findOneByExternalId('efe', B2bResourceType::Category, 'efe:category:'.$firstItem->categoryExternalId()));

        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Full)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->batches->beginRun($runId);
        try {
            $firstResult = $this->batches->process([
                B2bFeedRecord::success($firstItem),
            ], B2bSyncMode::Full, $run, null, 0);
            self::assertSame(1, $firstResult->counters->conflicts());
            self::assertStringContainsString('category', strtolower($firstResult->errors[0]->message()));
        } finally {
            $this->batches->endRun($runId);
        }

        $secondRecord = $this->fixtureRecord(1);
        $secondRecord['id'] = '2002';
        $secondRecord['stokkodu'] = 'DURABLE-SKU-CLAIM';
        $secondRecord['urunresimleri'] = [];
        $secondItem = $this->normalizer()->normalize($secondRecord);
        $this->batches->beginRun($runId);
        try {
            $secondResult = $this->batches->process([
                B2bFeedRecord::success($secondItem),
            ], B2bSyncMode::Full, $run, null, 1);
            self::assertSame(1, $secondResult->counters->conflicts());
            self::assertStringContainsString('DURABLE-SKU-CLAIM', $secondResult->errors[0]->message());
        } finally {
            $this->batches->endRun($runId);
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM integration_b2b_sync_observation WHERE run_id = '.(int) $runId));
    }

    public function testSamePositionReplayAfterACommitBeforeCheckpointIsIdempotent(): void
    {
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Full)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->batches->beginRun($runId);
        try {
            $first = $this->batches->process([
                B2bFeedRecord::success($this->item(0)),
            ], B2bSyncMode::Full, $run, null, 0);
            self::assertSame(1, $first->counters->created());
        } finally {
            $this->batches->endRun($runId);
        }

        $this->batches->beginRun($runId);
        try {
            $replay = $this->batches->process([
                B2bFeedRecord::success($this->item(0)),
            ], B2bSyncMode::Full, $run, null, 0);
            self::assertSame([], $replay->errors);
        } finally {
            $this->batches->endRun($runId);
        }

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM integration_external_mapping WHERE resource_type = 'product' AND external_id = '1001'"));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM integration_b2b_sync_observation WHERE run_id = '.(int) $runId));
    }

    public function testDuplicateExternalIdsInOneRunAreRejected(): void
    {
        $first = $this->item(0);
        $secondRecord = $this->fixtureRecord(1);
        $secondRecord['id'] = '1001';
        $secondRecord['stokkodu'] = 'DUPLICATE-ID-SECOND';
        $secondRecord['urunresimleri'] = [];
        $second = $this->normalizer()->normalize($secondRecord);
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Full)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->batches->beginRun($runId);
        try {
            $this->batches->process([B2bFeedRecord::success($first)], B2bSyncMode::Full, $run);
            $result = $this->batches->process([B2bFeedRecord::success($second)], B2bSyncMode::Full, $run);
        } finally {
            $this->batches->endRun($runId);
        }

        self::assertSame(1, $result->counters->conflicts());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
    }

    public function testDuplicateExternalIdWithTheSameIdentityIsRejected(): void
    {
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Full)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->batches->beginRun($runId);
        try {
            $this->batches->process([B2bFeedRecord::success($this->item(0))], B2bSyncMode::Full, $run);
            $result = $this->batches->process([B2bFeedRecord::success($this->item(0))], B2bSyncMode::Full, $run);
        } finally {
            $this->batches->endRun($runId);
        }

        self::assertSame(1, $result->counters->conflicts());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
    }

    public function testUnseenReconciliationIsAtomicWhenALaterStockWriteFails(): void
    {
        $first = $this->writer->importFull($this->item(0), 999);
        $second = $this->writer->importFull($this->item(1), 999);
        self::assertTrue($first->isSuccess());
        self::assertTrue($second->isSuccess());
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $realRepository = self::getContainer()->get(ProductInventoryRepositoryInterface::class);
        $throwingRepository = new class($realRepository) implements ProductInventoryRepositoryInterface {
            private int $saves = 0;
            public function __construct(private readonly ProductInventoryRepositoryInterface $inner) {}
            public function findOneByProduct(Product $product): ?\App\Entity\Commerce\ProductInventory { return $this->inner->findOneByProduct($product); }
            public function findOneByProductForUpdate(Product $product): ?\App\Entity\Commerce\ProductInventory { return $this->inner->findOneByProductForUpdate($product); }
            public function save(\App\Entity\Commerce\ProductInventory $inventory): void
            {
                if (++$this->saves === 2) {
                    throw new \RuntimeException('simulated reconciliation failure');
                }
                $this->inner->save($inventory);
            }
        };
        $reconciler = new \App\Module\Integration\B2b\B2bDailyReconciler(
            self::getContainer()->get(ExternalResourceMappingRepository::class),
            $this->entityManager,
            $throwingRepository,
            new InventoryManager($throwingRepository, $this->entityManager),
        );
        $before = $this->connection->fetchAllAssociative('SELECT product_id, quantity FROM commerce_product_inventory ORDER BY product_id');

        try {
            $reconciler->reconcile('efe', $runId);
            self::fail('A reconciliation failure must abort the operation.');
        } catch (\RuntimeException) {
            $after = $this->connection->fetchAllAssociative('SELECT product_id, quantity FROM commerce_product_inventory ORDER BY product_id');
            self::assertSame($before, $after);
        }
    }

    /**
     * PHASE_12 requires image failures to be retried and then recorded "without aborting the
     * whole catalog run". A single unreachable image must not kill a 92k record FULL sync.
     */
    public function testRetryableImageFailureIsRetriedThenRecordedWithoutAbortingTheBatch(): void
    {
        $record = $this->fixtureRecord(0);
        $item = $this->normalizer()->normalize($record);
        $media = new RetryableProductMediaStorage();
        $writer = new B2bCatalogWriter(
            resources: self::getContainer()->get(\App\Module\Integration\B2b\B2bResourceResolver::class),
            catalog: self::getContainer()->get(CatalogManager::class),
            media: $media,
            pricing: self::getContainer()->get(PricingManager::class),
            inventory: self::getContainer()->get(InventoryManager::class),
            prices: self::getContainer()->get(ProductPriceRepositoryInterface::class),
            inventoryRepository: self::getContainer()->get(ProductInventoryRepositoryInterface::class),
            entityManager: $this->entityManager,
            clock: new MockClock('2026-07-25T12:00:00+00:00'),
        );
        $resources = self::getContainer()->get(\App\Module\Integration\B2b\B2bResourceResolver::class);
        $batches = new B2bBatchProcessor($writer, $this->runs, $resources, $this->entityManager, new MockClock('2026-07-25T12:00:00+00:00'));
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Full)->run;

        $result = $batches->process([B2bFeedRecord::success($item)], B2bSyncMode::Full, $run);

        self::assertSame(RetryableProductMediaStorage::ATTEMPTS, $media->attempts, 'A transient image failure must be retried.');
        self::assertSame(1, $result->counters->created());
        self::assertSame(0, $result->counters->imagesImported());
        self::assertSame(1, $result->counters->imagesFailed());
        self::assertCount(1, $result->errors);
        self::assertSame('image', $result->errors[0]->errorType()->value);
        self::assertSame('1001', $result->errors[0]->externalId());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_product_price'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product_image'));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM integration_external_mapping WHERE resource_type = 'product_image'"));
    }

    public function testUnexpectedWriterFailureDoesNotCommitPartialProduct(): void
    {
        $realInventoryRepository = self::getContainer()->get(ProductInventoryRepositoryInterface::class);
        $throwingInventoryRepository = new class($realInventoryRepository) implements ProductInventoryRepositoryInterface {
            public function __construct(private readonly ProductInventoryRepositoryInterface $inner) {}
            public function findOneByProduct(Product $product): ?\App\Entity\Commerce\ProductInventory { return $this->inner->findOneByProduct($product); }
            public function findOneByProductForUpdate(Product $product): ?\App\Entity\Commerce\ProductInventory { return $this->inner->findOneByProductForUpdate($product); }
            public function save(\App\Entity\Commerce\ProductInventory $inventory): void { throw new \RuntimeException('simulated inventory persistence failure'); }
        };
        $writer = new B2bCatalogWriter(
            resources: self::getContainer()->get(\App\Module\Integration\B2b\B2bResourceResolver::class),
            catalog: self::getContainer()->get(CatalogManager::class),
            media: new ReliabilityProductMediaStorage(),
            pricing: self::getContainer()->get(PricingManager::class),
            inventory: new InventoryManager($throwingInventoryRepository, $this->entityManager),
            prices: self::getContainer()->get(ProductPriceRepositoryInterface::class),
            inventoryRepository: $throwingInventoryRepository,
            entityManager: $this->entityManager,
            clock: new MockClock('2026-07-25T12:00:00+00:00'),
        );
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Full)->run;
        $resources = self::getContainer()->get(\App\Module\Integration\B2b\B2bResourceResolver::class);
        $batches = new B2bBatchProcessor($writer, $this->runs, $resources, $this->entityManager, new MockClock('2026-07-25T12:00:00+00:00'));
        $result = $batches->process([
            B2bFeedRecord::success($this->item(0)),
        ], B2bSyncMode::Full, $run);

        self::assertFalse($result->counters->created() > 0);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
    }

    public function testBatchRefreshesTheProviderLockAfterEachRecord(): void
    {
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::exactly(2))->method('refresh');

        $result = $this->batches->process([
            B2bFeedRecord::success($this->item(0)),
            B2bFeedRecord::success($this->item(1)),
        ], B2bSyncMode::Daily, $run, $lock);

        self::assertSame(2, $result->counters->created());
    }

    public function testBatchUsesBoundedSetBasedProductMappingAndSkuLookups(): void
    {
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $records = [];
        foreach (range(0, 5) as $position) {
            $record = $this->fixtureRecord($position % 2);
            $record['id'] = (string) (2_001 + $position);
            $record['stokkodu'] = 'BATCH-'.(2_001 + $position);
            $record['urunresimleri'] = [];
            $records[] = B2bFeedRecord::success($this->normalizer()->normalize($record));
        }
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();

        $result = $this->batches->process($records, B2bSyncMode::Daily, $run);

        self::assertSame(6, $result->counters->created());
        $sql = array_column($queries->getData()['default'] ?? [], 'sql');
        $singleMappingLookups = count(array_filter(
            $sql,
            static fn (string $query): bool => 1 === preg_match('/\bexternal_id\s*=\s*\?/i', $query),
        ));
        $setSkuLookups = count(array_filter(
            $sql,
            static fn (string $query): bool => 1 === preg_match('/\bsku\s+IN\s*\(/i', $query),
        ));
        self::assertLessThanOrEqual(4, $singleMappingLookups, implode(";
", $sql));
        self::assertSame(2, $setSkuLookups, implode(";
", $sql));
    }

    private function item(int $index): \App\Module\Integration\B2b\NormalizedCatalogFeedItem
    {
        $record = $this->fixtureRecord($index);
        $record['urunresimleri'] = [];

        return $this->normalizer()->normalize($record);
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

    private function normalizer(): EfeFeedNormalizer
    {
        return new EfeFeedNormalizer(
            new EfePriceNormalizer(new TaxCalculator(), new PercentageDiscountCalculator()),
            ['b2b.efeotoyedekparca.com.tr'],
        );
    }
}

final class RetryableProductMediaStorage implements ProductMediaStorageInterface
{
    public const ATTEMPTS = 3;

    public int $attempts = 0;

    public function store(string $sourceUrl, string $altText): StoredProductImage
    {
        ++$this->attempts;
        throw new \App\Module\Integration\B2b\Exception\B2bRetryableProviderException('simulated image retry');
    }

    public function remove(StoredProductImage $image): void
    {
    }
}

final class ReliabilityProductMediaStorage implements ProductMediaStorageInterface
{
    public function store(string $sourceUrl, string $altText): StoredProductImage
    {
        throw new \LogicException('Reliability test records must not request images.');
    }

    public function remove(StoredProductImage $image): void
    {
        throw new \LogicException('Reliability test records must not remove images.');
    }
}
