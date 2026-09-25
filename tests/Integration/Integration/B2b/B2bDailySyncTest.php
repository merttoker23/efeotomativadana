<?php

namespace App\Tests\Integration\Integration\B2b;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Integration\B2bSyncError;
use App\Entity\Integration\B2bSyncRun;
use App\Entity\Integration\ExternalResourceMapping;
use App\Module\Catalog\CatalogManager;
use App\Module\Integration\B2b\B2bBatchProcessor;
use App\Module\Integration\B2b\B2bCatalogWriter;
use App\Module\Integration\B2b\B2bDailyReconciler;
use App\Module\Integration\B2b\B2bFeedRecord;
use App\Module\Integration\B2b\B2bProviderRegistry;
use App\Module\Integration\B2b\B2bProductIdentity;
use App\Module\Integration\B2b\B2bProviderStatus;
use App\Module\Integration\B2b\B2bRunProcessor;
use App\Module\Integration\B2b\B2bSnapshot;
use App\Module\Integration\B2b\B2bSnapshotRequest;
use App\Module\Integration\B2b\B2bSyncCheckpoint;
use App\Module\Integration\B2b\B2bSyncCounters;
use App\Module\Integration\B2b\B2bSyncMode;
use App\Module\Integration\B2b\B2bSyncRunManager;
use App\Module\Integration\B2b\B2bSyncService;
use App\Module\Integration\B2b\B2bItemError;
use App\Module\Integration\B2b\B2bErrorType;
use App\Module\Integration\B2b\Exception\B2bLockUnavailableException;
use App\Module\Integration\B2b\ProductMediaStorageInterface;
use App\Module\Integration\B2b\Provider\Efe\EfeFeedNormalizer;
use App\Module\Integration\B2b\Provider\Efe\EfePriceNormalizer;
use App\Module\Integration\B2b\StoredProductImage;
use App\Module\Pricing\PercentageDiscountCalculator;
use App\Module\Pricing\PricingManager;
use App\Module\Pricing\ProductPriceRepositoryInterface;
use App\Module\Catalog\ProductRepositoryInterface;
use App\Module\Pricing\TaxCalculator;
use App\Module\Inventory\InventoryManager;
use App\Module\Inventory\ProductInventoryRepositoryInterface;
use App\Repository\Integration\B2bSyncErrorRepository;
use App\Repository\Integration\B2bSyncRunRepository;
use App\Repository\Integration\ExternalResourceMappingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;

final class B2bDailySyncTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private B2bCatalogWriter $writer;
    private B2bSyncRunManager $runs;
    private B2bRunProcessor $processor;
    private InMemoryProductMediaStorage $media;
    private FakeDailyProvider $provider;
    private string $directory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->directory = sys_get_temp_dir().'/efe-b2b-daily-'.bin2hex(random_bytes(5));
        mkdir($this->directory, 0755, true);
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $runRepository = $entityManager->getRepository(B2bSyncRun::class);
        $errorRepository = $entityManager->getRepository(B2bSyncError::class);
        $mappingRepository = $entityManager->getRepository(\App\Entity\Integration\ExternalResourceMapping::class);
        self::assertInstanceOf(B2bSyncRunRepository::class, $runRepository);
        self::assertInstanceOf(B2bSyncErrorRepository::class, $errorRepository);
        self::assertInstanceOf(ExternalResourceMappingRepository::class, $mappingRepository);
        $this->media = new InMemoryProductMediaStorage();
        $catalog = self::getContainer()->get(CatalogManager::class);
        self::assertInstanceOf(CatalogManager::class, $catalog);
        $this->writer = new B2bCatalogWriter(
            resources: self::getContainer()->get(\App\Module\Integration\B2b\B2bResourceResolver::class),
            catalog: $catalog,
            media: $this->media,
            pricing: self::getContainer()->get(PricingManager::class),
            inventory: self::getContainer()->get(InventoryManager::class),
            prices: self::getContainer()->get(ProductPriceRepositoryInterface::class),
            inventoryRepository: self::getContainer()->get(ProductInventoryRepositoryInterface::class),
            entityManager: $entityManager,
            clock: new MockClock('2026-07-25T12:00:00+00:00'),
        );
        $this->runs = new B2bSyncRunManager($runRepository, $errorRepository, $entityManager, self::getContainer()->get('doctrine'), new MockClock('2026-07-25T12:00:00+00:00'), 1000);
        $this->provider = new FakeDailyProvider($this->directory);
        $registry = new B2bProviderRegistry([$this->provider]);
        $resources = self::getContainer()->get(\App\Module\Integration\B2b\B2bResourceResolver::class);
        self::assertInstanceOf(\App\Module\Integration\B2b\B2bResourceResolver::class, $resources);
        $batch = new B2bBatchProcessor($this->writer, $this->runs, $resources, $entityManager, new MockClock('2026-07-25T12:00:00+00:00'));
        $reconciler = new B2bDailyReconciler(
            $mappingRepository,
            $entityManager,
            self::getContainer()->get(ProductInventoryRepositoryInterface::class),
            self::getContainer()->get(InventoryManager::class),
        );
        $lock = new \App\Module\Integration\B2b\B2bSyncLock($this->connection);
        $this->processor = new B2bRunProcessor(
            runs: $runRepository,
            runManager: $this->runs,
            providers: $registry,
            batchProcessor: $batch,
            reconciler: $reconciler,
            lock: $lock,
            cleaner: new \App\Module\Integration\B2b\B2bSnapshotCleaner($this->directory, new MockClock('2026-07-25T12:00:00+00:00')),
            entityManager: $entityManager,
            snapshotDirectory: $this->directory,
            batchSize: 1,
            logger: self::getContainer()->get(\Psr\Log\LoggerInterface::class),
            clock: new MockClock('2026-07-25T12:00:00+00:00'),
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
                    @unlink($file->getPathname());
                }
            }
            @rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function testMappedDailyChangesOnlyPriceAndStock(): void
    {
        $item = $this->item(0);
        $created = $this->writer->importFull($item, 1);
        self::assertTrue($created->isSuccess());
        $product = $created->product();
        self::assertInstanceOf(Product::class, $product);
        $productId = $product->id();
        $originalName = $product->name();
        $originalSlug = $product->slug();
        $originalCategory = $product->categories()[0]->name();
        $originalBrand = $product->brand()?->name();
        $originalImageCount = count($product->images());
        $originalImages = array_map(static fn ($image): string => $image->path(), $product->images());
        $originalIdentifiers = array_map(
            static fn ($identifier): string => $identifier->type()->value.':'.$identifier->code(),
            $product->identifiers(),
        );
        sort($originalIdentifiers);
        $originalAttributes = [];
        foreach ($product->attributes() as $attribute) {
            $originalAttributes[$attribute->key()] = $attribute->value();
        }
        ksort($originalAttributes);
        $mediaCount = count($this->media->stored);
        $record = $this->fixtureRecord(0);
        $record['cinsi'] = 'Should not overwrite mapped content';
        $record['aciklama'] = 'Should not overwrite mapped description';
        $record['stok_grubu'] = 'NEW GROUP';
        $record['ureticiid'] = 'DAILY-MANUFACTURER-ID';
        $record['uretici_adi'] = 'OTHER BRAND';
        $record['oemnumaralari'] = 'DAILY-MUTABLE-OEM';
        $record['barkod'] = 'DAILY-MUTABLE-REFERENCE';
        $record['marka'] = 'DAILY-BRAND-CODE';
        $record['kutuiciadet'] = '9';
        $record['desi'] = '2.5';
        $record['listefiyati'] = '900.00';
        $record['mevcut_stok'] = '7';
        $record['urunresimleri'] = ['https://b2b.efeotoyedekparca.com.tr/urunler/new-daily.jpg'];
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [B2bFeedRecord::success($this->normalizer()->normalize($record))];

        $this->processor->process($runId);
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Product::class, $productId);
        self::assertInstanceOf(Product::class, $reloaded);
        self::assertSame($originalName, $reloaded->name());
        self::assertSame($originalSlug, $reloaded->slug());
        self::assertSame($originalCategory, $reloaded->categories()[0]->name());
        self::assertSame($originalBrand, $reloaded->brand()?->name());
        self::assertSame($originalImageCount, count($reloaded->images()));
        self::assertSame($originalImages, array_map(static fn ($image): string => $image->path(), $reloaded->images()));
        $reloadedIdentifiers = array_map(
            static fn ($identifier): string => $identifier->type()->value.':'.$identifier->code(),
            $reloaded->identifiers(),
        );
        sort($reloadedIdentifiers);
        self::assertSame($originalIdentifiers, $reloadedIdentifiers);
        $reloadedAttributes = [];
        foreach ($reloaded->attributes() as $attribute) {
            $reloadedAttributes[$attribute->key()] = $attribute->value();
        }
        ksort($reloadedAttributes);
        self::assertSame($originalAttributes, $reloadedAttributes);
        self::assertSame($mediaCount, count($this->media->stored));
        self::assertSame(108_000, $this->price($reloaded)->basePrice()->minorAmount());
        self::assertSame(7, $this->inventory($reloaded)->quantity());
    }

    public function testDailyAllowsMutableOemIdentifierChangesAndUpdatesPriceAndStock(): void
    {
        $created = $this->writer->importFull($this->item(0), 1);
        self::assertTrue($created->isSuccess());
        $product = $created->product();
        self::assertInstanceOf(Product::class, $product);
        $productId = $product->id();
        self::assertNotNull($productId);
        $originalName = $product->name();
        $originalDescription = $product->description();
        $originalCategory = $product->categories()[0]->name();
        $originalIdentifiers = array_map(
            static fn ($identifier): string => $identifier->type()->value.':'.$identifier->code(),
            $product->identifiers(),
        );
        sort($originalIdentifiers);
        $originalImages = array_map(static fn ($image): string => $image->path(), $product->images());

        $record = $this->fixtureRecord(0);
        $record['oemnumaralari'] = 'CHANGED-OEM-1 CHANGED-OEM-2';
        $record['listefiyati'] = '900.00';
        $record['mevcut_stok'] = '7';
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [B2bFeedRecord::success($this->normalizer()->normalize($record))];

        $this->processor->process($runId);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Product::class, $productId);
        self::assertInstanceOf(Product::class, $reloaded);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM integration_external_mapping WHERE resource_type = 'product' AND external_id = '1001'"));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM integration_b2b_sync_error WHERE run_id = '.(int) $runId));
        self::assertSame($originalName, $reloaded->name());
        self::assertSame($originalDescription, $reloaded->description());
        self::assertSame($originalCategory, $reloaded->categories()[0]->name());
        $reloadedIdentifiers = array_map(
            static fn ($identifier): string => $identifier->type()->value.':'.$identifier->code(),
            $reloaded->identifiers(),
        );
        sort($reloadedIdentifiers);
        self::assertSame($originalIdentifiers, $reloadedIdentifiers);
        self::assertSame($originalImages, array_map(static fn ($image): string => $image->path(), $reloaded->images()));
        self::assertSame(108_000, $this->price($reloaded)->basePrice()->minorAmount());
        self::assertSame(7, $this->inventory($reloaded)->quantity());
    }

    public function testDailyAllowsReferenceAndManufacturerIdentifierChanges(): void
    {
        $created = $this->writer->importFull($this->item(0), 1);
        self::assertTrue($created->isSuccess());
        $product = $created->product();
        self::assertInstanceOf(Product::class, $product);
        $productId = $product->id();
        self::assertNotNull($productId);
        $originalIdentifiers = array_map(
            static fn ($identifier): string => $identifier->type()->value.':'.$identifier->code(),
            $product->identifiers(),
        );
        sort($originalIdentifiers);

        $record = $this->fixtureRecord(0);
        $record['ureticiid'] = 'NEW-MANUFACTURER-ID';
        $record['uretici_adi'] = 'NEW MANUFACTURER';
        $record['barkod'] = 'NEW-REFERENCE-CODE';
        $record['listefiyati'] = '901.00';
        $record['mevcut_stok'] = '8';
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [B2bFeedRecord::success($this->normalizer()->normalize($record))];

        $this->processor->process($runId);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Product::class, $productId);
        self::assertInstanceOf(Product::class, $reloaded);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM integration_b2b_sync_error WHERE run_id = '.(int) $runId));
        $reloadedIdentifiers = array_map(
            static fn ($identifier): string => $identifier->type()->value.':'.$identifier->code(),
            $reloaded->identifiers(),
        );
        sort($reloadedIdentifiers);
        self::assertSame($originalIdentifiers, $reloadedIdentifiers);
        self::assertSame(108_120, $this->price($reloaded)->basePrice()->minorAmount());
        self::assertSame(8, $this->inventory($reloaded)->quantity());
    }

    public function testMappedProductWithSameExternalIdAndSkuIsAcceptedAcrossRuns(): void
    {
        $created = $this->writer->importFull($this->item(0), 1);
        self::assertTrue($created->isSuccess());
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [B2bFeedRecord::success($this->item(0))];

        $this->processor->process($runId);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM integration_b2b_sync_error WHERE run_id = '.(int) $runId));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
    }

    public function testMappedProductWithChangedSkuIsRejectedWithoutUpdatingIt(): void
    {
        $created = $this->writer->importFull($this->item(0), 1);
        self::assertTrue($created->isSuccess());
        $product = $created->product();
        self::assertInstanceOf(Product::class, $product);
        $productId = $product->id();
        self::assertNotNull($productId);
        $record = $this->fixtureRecord(0);
        $record['stokkodu'] = 'CHANGED-SKU';
        $record['listefiyati'] = '999.00';
        $record['mevcut_stok'] = '99';
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [B2bFeedRecord::success($this->normalizer()->normalize($record))];

        $this->expectException(\App\Module\Integration\B2b\Exception\B2bRetryableProviderException::class);
        try {
            $this->processor->process($runId);
        } finally {
            $this->entityManager->clear();
            $reloaded = $this->entityManager->find(Product::class, $productId);
            self::assertInstanceOf(Product::class, $reloaded);
            self::assertSame('GVA 9120688', $reloaded->sku());
            self::assertSame(100_664, $this->price($reloaded)->basePrice()->minorAmount());
            self::assertSame(1, $this->inventory($reloaded)->quantity());
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
            self::assertSame('conflict', $this->connection->fetchOne("SELECT error_type FROM integration_b2b_sync_error WHERE run_id = ".(int) $runId." ORDER BY id DESC LIMIT 1"));
        }
    }

    public function testMalformedRowWithoutExternalIdCannotZeroAProductAfterACompletedDailyBaseline(): void
    {
        $created = $this->writer->importFull($this->item(0), 999);
        self::assertTrue($created->isSuccess());
        $product = $created->product();
        self::assertInstanceOf(Product::class, $product);
        $productId = $product->id();
        self::assertNotNull($productId);

        $baselineRun = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $baselineRunId = $baselineRun->id();
        self::assertNotNull($baselineRunId);
        $this->provider->records = [B2bFeedRecord::success($this->item(0))];
        $this->processor->process($baselineRunId);
        $this->entityManager->clear();
        $baselineProduct = $this->entityManager->find(Product::class, $productId);
        self::assertInstanceOf(Product::class, $baselineProduct);
        $originalPrice = $this->price($baselineProduct)->basePrice()->minorAmount();
        $originalStock = $this->inventory($baselineProduct)->quantity();

        $malformedRun = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $malformedRunId = $malformedRun->id();
        self::assertNotNull($malformedRunId);
        $this->provider->records = [B2bFeedRecord::failure(new B2bItemError(B2bErrorType::InvalidItem, 'Malformed row.', null))];

        $this->expectException(\App\Module\Integration\B2b\Exception\B2bRetryableProviderException::class);
        try {
            $this->processor->process($malformedRunId);
        } finally {
            $this->entityManager->clear();
            $reloaded = $this->entityManager->find(Product::class, $productId);
            self::assertInstanceOf(Product::class, $reloaded);
            self::assertSame($originalPrice, $this->price($reloaded)->basePrice()->minorAmount());
            self::assertSame($originalStock, $this->inventory($reloaded)->quantity());
        }
    }

    public function testChangedSkuConflictCannotZeroAProductAfterACompletedDailyBaseline(): void
    {
        $created = $this->writer->importFull($this->item(0), 999);
        self::assertTrue($created->isSuccess());
        $product = $created->product();
        self::assertInstanceOf(Product::class, $product);
        $productId = $product->id();
        self::assertNotNull($productId);

        $baselineRun = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $baselineRunId = $baselineRun->id();
        self::assertNotNull($baselineRunId);
        $this->provider->records = [B2bFeedRecord::success($this->item(0))];
        $this->processor->process($baselineRunId);
        $this->entityManager->clear();
        $baselineProduct = $this->entityManager->find(Product::class, $productId);
        self::assertInstanceOf(Product::class, $baselineProduct);
        $originalPrice = $this->price($baselineProduct)->basePrice()->minorAmount();
        $originalStock = $this->inventory($baselineProduct)->quantity();

        $changedSkuRecord = $this->fixtureRecord(0);
        $changedSkuRecord['stokkodu'] = 'UNSAFE-SKU-CHANGE';
        $changedSkuRun = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $changedSkuRunId = $changedSkuRun->id();
        self::assertNotNull($changedSkuRunId);
        $this->provider->records = [B2bFeedRecord::success($this->normalizer()->normalize($changedSkuRecord))];

        $this->expectException(\App\Module\Integration\B2b\Exception\B2bRetryableProviderException::class);
        try {
            $this->processor->process($changedSkuRunId);
        } finally {
            $this->entityManager->clear();
            $reloaded = $this->entityManager->find(Product::class, $productId);
            self::assertInstanceOf(Product::class, $reloaded);
            self::assertSame($originalPrice, $this->price($reloaded)->basePrice()->minorAmount());
            self::assertSame($originalStock, $this->inventory($reloaded)->quantity());
            $run = $this->entityManager->find(B2bSyncRun::class, $changedSkuRunId);
            self::assertInstanceOf(B2bSyncRun::class, $run);
            self::assertSame(0, $run->checkpoint());
        }
    }

    public function testLegacyProductFingerprintIsAcceptedAndTransitionedToStableIdentity(): void
    {
        $item = $this->item(0);
        $created = $this->writer->importFull($item, 1);
        self::assertTrue($created->isSuccess());
        $product = $created->product();
        self::assertInstanceOf(Product::class, $product);
        $mappingRepository = self::getContainer()->get(ExternalResourceMappingRepository::class);
        self::assertInstanceOf(ExternalResourceMappingRepository::class, $mappingRepository);
        $legacyFingerprint = hash('sha256', serialize([
            $item->externalId(),
            $item->sku(),
            array_map(
                static fn (array $identifier): array => [$identifier[0]->value, $identifier[1]],
                $item->identifiers(),
            ),
        ]));
        $mapping = $mappingRepository->findOneByExternalId('efe', \App\Module\Integration\B2b\B2bResourceType::Product, '1001');
        self::assertInstanceOf(ExternalResourceMapping::class, $mapping);
        $mapping->seenIn(1, new \DateTimeImmutable('2026-07-25T12:01:00+00:00'), $legacyFingerprint, 1);
        $this->entityManager->flush();

        $firstChangedRecord = $this->fixtureRecord(0);
        $firstChangedRecord['oemnumaralari'] = 'LEGACY-COMPAT-OEM-A';
        $firstChangedRecord['barkod'] = 'LEGACY-COMPAT-REFERENCE-A';
        $firstRun = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $firstRunId = $firstRun->id();
        self::assertNotNull($firstRunId);
        $this->provider->records = [B2bFeedRecord::success($this->normalizer()->normalize($firstChangedRecord))];
        $this->processor->process($firstRunId);

        $this->entityManager->clear();
        $mapping = $mappingRepository->findOneByExternalId('efe', \App\Module\Integration\B2b\B2bResourceType::Product, '1001');
        self::assertInstanceOf(ExternalResourceMapping::class, $mapping);
        $transitionedFingerprint = $mapping->contentSha256();
        self::assertSame(2, $mapping->identityVersion());
        self::assertNotNull($transitionedFingerprint);
        self::assertNotSame($legacyFingerprint, $transitionedFingerprint);
        self::assertSame(
            B2bProductIdentity::fingerprint($this->normalizer()->normalize($firstChangedRecord)),
            $transitionedFingerprint,
        );

        $secondChangedRecord = $this->fixtureRecord(0);
        $secondChangedRecord['oemnumaralari'] = 'LEGACY-COMPAT-OEM-B';
        $secondChangedRecord['barkod'] = 'LEGACY-COMPAT-REFERENCE-B';
        $secondRun = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $secondRunId = $secondRun->id();
        self::assertNotNull($secondRunId);
        $this->provider->records = [B2bFeedRecord::success($this->normalizer()->normalize($secondChangedRecord))];
        $this->processor->process($secondRunId);

        $this->entityManager->clear();
        $mapping = $mappingRepository->findOneByExternalId('efe', \App\Module\Integration\B2b\B2bResourceType::Product, '1001');
        self::assertInstanceOf(ExternalResourceMapping::class, $mapping);
        self::assertSame($transitionedFingerprint, $mapping->contentSha256());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
    }

    public function testLegacyLowercaseSkuMappingTransitionsToStableIdentityOnCanonicalSkuMatch(): void
    {
        $legacyRecord = $this->fixtureRecord(0);
        $legacyRecord['stokkodu'] = 'abc-123';
        $legacyRecord['oemnumaralari'] = '117209354  7711130071  8200034396';
        $legacyItem = $this->normalizer()->normalize($legacyRecord);
        $created = $this->writer->importFull($legacyItem, 1);
        self::assertTrue($created->isSuccess());
        $product = $created->product();
        self::assertInstanceOf(Product::class, $product);
        self::assertSame('ABC-123', $product->sku());
        $productId = $product->id();
        self::assertNotNull($productId);
        $mappingRepository = self::getContainer()->get(ExternalResourceMappingRepository::class);
        self::assertInstanceOf(ExternalResourceMappingRepository::class, $mappingRepository);
        $legacyFingerprint = B2bProductIdentity::legacyFingerprint($legacyItem);
        $mapping = $mappingRepository->findOneByExternalId('efe', \App\Module\Integration\B2b\B2bResourceType::Product, '1001');
        self::assertInstanceOf(ExternalResourceMapping::class, $mapping);
        $mapping->seenIn(1, new \DateTimeImmutable('2026-07-25T11:00:00+00:00'), $legacyFingerprint, 1);
        $this->entityManager->flush();
        self::assertSame(1, $mapping->identityVersion());
        self::assertSame($legacyFingerprint, $mapping->contentSha256());

        $currentRecord = $this->fixtureRecord(0);
        $currentRecord['stokkodu'] = 'ABC-123';
        $currentRecord['oemnumaralari'] = '117209354  7711130071  8200034396';
        $currentRecord['listefiyati'] = '900.00';
        $currentRecord['mevcut_stok'] = '7';
        $currentItem = $this->normalizer()->normalize($currentRecord);
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [B2bFeedRecord::success($currentItem)];

        $this->processor->process($runId);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM integration_b2b_sync_error WHERE run_id = '.(int) $runId));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM integration_external_mapping WHERE resource_type = 'product' AND external_id = '1001'"));
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Product::class, $productId);
        self::assertInstanceOf(Product::class, $reloaded);
        self::assertSame(108_000, $this->price($reloaded)->basePrice()->minorAmount());
        self::assertSame(7, $this->inventory($reloaded)->quantity());
        $mapping = $mappingRepository->findOneByExternalId('efe', \App\Module\Integration\B2b\B2bResourceType::Product, '1001');
        self::assertInstanceOf(ExternalResourceMapping::class, $mapping);
        self::assertSame(2, $mapping->identityVersion());
        self::assertSame(B2bProductIdentity::fingerprint($currentItem), $mapping->contentSha256());
    }

    public function testLegacyMappingTransitionsEvenWhenEveryMutableIdentifierChangesSimultaneously(): void
    {
        $legacyRecord = $this->fixtureRecord(0);
        $legacyRecord['stokkodu'] = 'abc-123';
        $legacyItem = $this->normalizer()->normalize($legacyRecord);
        $created = $this->writer->importFull($legacyItem, 1);
        self::assertTrue($created->isSuccess());
        $product = $created->product();
        self::assertInstanceOf(Product::class, $product);
        $productId = $product->id();
        self::assertNotNull($productId);
        $originalIdentifiers = array_map(
            static fn ($identifier): string => $identifier->type()->value.':'.$identifier->code(),
            $product->identifiers(),
        );
        sort($originalIdentifiers);
        $mappingRepository = self::getContainer()->get(ExternalResourceMappingRepository::class);
        self::assertInstanceOf(ExternalResourceMappingRepository::class, $mappingRepository);
        $legacyFingerprint = B2bProductIdentity::legacyFingerprint($legacyItem);
        $mapping = $mappingRepository->findOneByExternalId('efe', \App\Module\Integration\B2b\B2bResourceType::Product, '1001');
        self::assertInstanceOf(ExternalResourceMapping::class, $mapping);
        $mapping->seenIn(1, new \DateTimeImmutable('2026-07-25T11:00:00+00:00'), $legacyFingerprint, 1);
        $this->entityManager->flush();

        $currentRecord = $this->fixtureRecord(0);
        $currentRecord['stokkodu'] = 'ABC-123';
        $currentRecord['ureticiid'] = 'NEW-MANUFACTURER-ID';
        $currentRecord['uretici_adi'] = 'NEW MANUFACTURER';
        $currentRecord['oemnumaralari'] = 'NEW-OEM-1  NEW-OEM-2  NEW-OEM-3';
        $currentRecord['barkod'] = 'NEW-REFERENCE-CODE';
        $currentRecord['listefiyati'] = '900.00';
        $currentRecord['mevcut_stok'] = '7';
        $currentItem = $this->normalizer()->normalize($currentRecord);
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [B2bFeedRecord::success($currentItem)];

        $this->processor->process($runId);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM integration_b2b_sync_error WHERE run_id = '.(int) $runId));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Product::class, $productId);
        self::assertInstanceOf(Product::class, $reloaded);
        self::assertSame(108_000, $this->price($reloaded)->basePrice()->minorAmount());
        self::assertSame(7, $this->inventory($reloaded)->quantity());
        $reloadedIdentifiers = array_map(
            static fn ($identifier): string => $identifier->type()->value.':'.$identifier->code(),
            $reloaded->identifiers(),
        );
        sort($reloadedIdentifiers);
        self::assertSame($originalIdentifiers, $reloadedIdentifiers);
        $mapping = $mappingRepository->findOneByExternalId('efe', \App\Module\Integration\B2b\B2bResourceType::Product, '1001');
        self::assertInstanceOf(ExternalResourceMapping::class, $mapping);
        self::assertSame(2, $mapping->identityVersion());
        self::assertSame(B2bProductIdentity::fingerprint($currentItem), $mapping->contentSha256());
    }

    public function testLegacyMappingWithADifferentCanonicalSkuIsStillRejectedWithoutUpdatingTheProduct(): void
    {
        $legacyRecord = $this->fixtureRecord(0);
        $legacyRecord['stokkodu'] = 'abc-123';
        $legacyItem = $this->normalizer()->normalize($legacyRecord);
        $created = $this->writer->importFull($legacyItem, 1);
        self::assertTrue($created->isSuccess());
        $product = $created->product();
        self::assertInstanceOf(Product::class, $product);
        $productId = $product->id();
        self::assertNotNull($productId);
        $mappingRepository = self::getContainer()->get(ExternalResourceMappingRepository::class);
        self::assertInstanceOf(ExternalResourceMappingRepository::class, $mappingRepository);
        $legacyFingerprint = B2bProductIdentity::legacyFingerprint($legacyItem);
        $mapping = $mappingRepository->findOneByExternalId('efe', \App\Module\Integration\B2b\B2bResourceType::Product, '1001');
        self::assertInstanceOf(ExternalResourceMapping::class, $mapping);
        $mapping->seenIn(1, new \DateTimeImmutable('2026-07-25T11:00:00+00:00'), $legacyFingerprint, 1);
        $this->entityManager->flush();

        $changedRecord = $this->fixtureRecord(0);
        $changedRecord['stokkodu'] = 'ABC-999';
        $changedRecord['barkod'] = 'ABC-999';
        $changedRecord['listefiyati'] = '999.00';
        $changedRecord['mevcut_stok'] = '99';
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [B2bFeedRecord::success($this->normalizer()->normalize($changedRecord))];

        $this->expectException(\App\Module\Integration\B2b\Exception\B2bRetryableProviderException::class);
        try {
            $this->processor->process($runId);
        } finally {
            $this->entityManager->clear();
            $reloaded = $this->entityManager->find(Product::class, $productId);
            self::assertInstanceOf(Product::class, $reloaded);
            self::assertSame('ABC-123', $reloaded->sku());
            self::assertSame(100_664, $this->price($reloaded)->basePrice()->minorAmount());
            self::assertSame(1, $this->inventory($reloaded)->quantity());
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM integration_external_mapping WHERE resource_type = 'product' AND external_id = '1001'"));
            self::assertSame('conflict', $this->connection->fetchOne("SELECT error_type FROM integration_b2b_sync_error WHERE run_id = ".(int) $runId." ORDER BY id DESC LIMIT 1"));
            $mapping = $mappingRepository->findOneByExternalId('efe', \App\Module\Integration\B2b\B2bResourceType::Product, '1001');
            self::assertInstanceOf(ExternalResourceMapping::class, $mapping);
            self::assertSame(1, $mapping->identityVersion());
            self::assertSame($legacyFingerprint, $mapping->contentSha256());
        }
    }

    public function testUnexpectedStableFingerprintIsRejectedForAVersionedMapping(): void
    {
        $created = $this->writer->importFull($this->item(0), 999);
        self::assertTrue($created->isSuccess());
        $mappingRepository = self::getContainer()->get(ExternalResourceMappingRepository::class);
        self::assertInstanceOf(ExternalResourceMappingRepository::class, $mappingRepository);
        $mapping = $mappingRepository->findOneByExternalId('efe', \App\Module\Integration\B2b\B2bResourceType::Product, '1001');
        self::assertInstanceOf(ExternalResourceMapping::class, $mapping);
        $mapping->seenIn(999, new \DateTimeImmutable('2026-07-25T12:01:00+00:00'), hash('sha256', 'corrupt-stable-fingerprint'));
        $this->entityManager->flush();
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [B2bFeedRecord::success($this->item(0))];

        $this->expectException(\App\Module\Integration\B2b\Exception\B2bRetryableProviderException::class);
        try {
            $this->processor->process($runId);
        } finally {
            $this->entityManager->clear();
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        }
    }

    public function testMissingFingerprintOnVersion2MappingIsRejected(): void
    {
        $created = $this->writer->importFull($this->item(0), 999);
        self::assertTrue($created->isSuccess());
        $mappingRepository = self::getContainer()->get(ExternalResourceMappingRepository::class);
        self::assertInstanceOf(ExternalResourceMappingRepository::class, $mappingRepository);
        $mapping = $mappingRepository->findOneByExternalId('efe', \App\Module\Integration\B2b\B2bResourceType::Product, '1001');
        self::assertInstanceOf(ExternalResourceMapping::class, $mapping);
        self::assertSame(2, $mapping->identityVersion());
        $this->connection->executeStatement("UPDATE integration_external_mapping SET content_sha256 = NULL WHERE id = ".(int) $mapping->id());
        $this->entityManager->clear();
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [B2bFeedRecord::success($this->item(0))];

        $this->expectException(\App\Module\Integration\B2b\Exception\B2bRetryableProviderException::class);
        try {
            $this->processor->process($runId);
        } finally {
            $this->entityManager->clear();
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        }
    }

    public function testDailyAutomaticallyFullyCreatesAProductWithCatalogData(): void
    {
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [B2bFeedRecord::success($this->item(0))];

        $this->processor->process($runId);

        $this->entityManager->clear();
        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['sku' => 'GVA 9120688']);
        self::assertInstanceOf(Product::class, $product);
        self::assertSame('STOP LAMBASI SOL VW JETTA 201', $product->name());
        self::assertSame('STOP', $product->categories()[0]->name());
        self::assertSame('GVA', $product->brand()?->name());
        self::assertCount(5, $product->identifiers());
        self::assertCount(1, $product->images());
        self::assertSame(100_664, $this->price($product)->basePrice()->minorAmount());
        self::assertSame(1, $this->inventory($product)->quantity());
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM integration_external_mapping WHERE resource_type = 'product' AND external_id = '1001'"));
        self::assertSame('completed', $this->connection->fetchOne("SELECT state FROM integration_b2b_sync_run WHERE id = ".(int) $runId));
    }

    public function testDailyAutomaticallyCreatesNewProductAndNextRunIsMappedOnly(): void
    {
        $item = $this->item(1);
        $firstRun = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $firstRunId = $firstRun->id();
        self::assertNotNull($firstRunId);
        $this->provider->records = [B2bFeedRecord::success($item)];
        $this->processor->process($firstRunId);
        $productCountAfterCreate = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product');
        $mediaCountAfterCreate = count($this->media->stored);
        self::assertSame(1, $productCountAfterCreate);
        self::assertSame(0, $mediaCountAfterCreate);

        $secondItemRecord = $this->fixtureRecord(1);
        $secondItemRecord['listefiyati'] = '200.00';
        $secondItemRecord['mevcut_stok'] = '4';
        $secondRun = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $secondRunId = $secondRun->id();
        self::assertNotNull($secondRunId);
        $this->provider->records = [B2bFeedRecord::success($this->normalizer()->normalize($secondItemRecord))];
        $this->processor->process($secondRunId);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        self::assertSame($mediaCountAfterCreate, count($this->media->stored));
        $productRepository = $this->entityManager->getRepository(Product::class);
        self::assertInstanceOf(ProductRepositoryInterface::class, $productRepository);
        $product = $productRepository->findOneBySku('FIL-1002');
        self::assertInstanceOf(Product::class, $product);
        self::assertSame(22_000, $this->price($product)->basePrice()->minorAmount());
        self::assertSame(4, $this->inventory($product)->quantity());
    }

    public function testSuccessfulDailySnapshotZerosUnseenStock(): void
    {
        $first = $this->writer->importFull($this->item(0), 1);
        $secondRecord = $this->fixtureRecord(1);
        $secondRecord['mevcut_stok'] = '9';
        $second = $this->writer->importFull($this->normalizer()->normalize($secondRecord), 1);
        self::assertTrue($second->isSuccess());
        $secondProduct = $second->product();
        self::assertInstanceOf(Product::class, $secondProduct);
        $secondId = $secondProduct->id();
        $baseline = B2bSyncRun::queue('efe', B2bSyncMode::Full, new \DateTimeImmutable('2026-07-25T13:00:00+00:00'));
        $baseline->markRunning(new \DateTimeImmutable('2026-07-25T13:01:00+00:00'));
        $baseline->recordSnapshot('/tmp/baseline.json', 2, str_repeat('c', 64), new \DateTimeImmutable('2026-07-25T13:01:00+00:00'));
        $baseline->recordBatch(B2bSyncCounters::empty()->recordScanned(2), 2, new \DateTimeImmutable('2026-07-25T13:02:00+00:00'));
        $baseline->complete(new \DateTimeImmutable('2026-07-25T13:03:00+00:00'));
        $this->entityManager->persist($baseline);
        $this->entityManager->flush();
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [B2bFeedRecord::success($this->item(0))];
        $this->processor->process($runId);
        $this->entityManager->clear();
        $unseen = $this->entityManager->find(Product::class, $secondId);
        self::assertInstanceOf(Product::class, $unseen);
        self::assertSame(0, $this->inventory($unseen)->quantity());
    }

    public function testMalformedMappedPriceDoesNotOverwriteLastValidValues(): void
    {
        $created = $this->writer->importFull($this->item(0), 1);
        self::assertTrue($created->isSuccess());
        $product = $created->product();
        self::assertInstanceOf(Product::class, $product);
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [B2bFeedRecord::failure(new B2bItemError(B2bErrorType::InvalidPrice, 'Invalid price.', '1001'))];

        $this->processor->process($runId);
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Product::class, $product->id());
        self::assertInstanceOf(Product::class, $reloaded);
        self::assertSame(100_664, $this->price($reloaded)->basePrice()->minorAmount());
        self::assertSame(1, $this->inventory($reloaded)->quantity());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT JSON_UNQUOTE(JSON_EXTRACT(counters, \'$.price_failed\')) FROM integration_b2b_sync_run WHERE id = '.(int) $runId));
    }

    public function testRetryResumesAfterCheckpointWithoutDuplicatingAlreadyImportedRows(): void
    {
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [B2bFeedRecord::success($this->item(0))];
        $this->provider->failure = new \RuntimeException('stop after first batch');
        try {
            $this->processor->process($runId);
            self::fail('The first attempt must fail.');
        } catch (\RuntimeException) {
            $this->entityManager->clear();
            $reloadedRun = $this->entityManager->find(B2bSyncRun::class, $runId);
            self::assertInstanceOf(B2bSyncRun::class, $reloadedRun);
            self::assertSame(1, $reloadedRun->checkpoint());
            $run = $reloadedRun;
        }
        $this->provider->records = [
            B2bFeedRecord::success($this->item(0)),
            B2bFeedRecord::success($this->item(1)),
        ];
        $this->provider->failure = null;
        $this->runs->retry($run, 'retry after partial stream');
        $this->processor->process($runId);

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM integration_external_mapping WHERE resource_type = 'product' AND external_id = '1001'"));
    }

    public function testFinalPartialBatchKeepsItsStreamStartPositionAcrossResume(): void
    {
        $thirdRecord = $this->fixtureRecord(1);
        $thirdRecord['id'] = '1003';
        $thirdRecord['stokkodu'] = 'FIL-1003';
        $thirdRecord['barkod'] = 'FIL-1003';
        $records = [
            B2bFeedRecord::success($this->item(0)),
            B2bFeedRecord::success($this->item(1)),
            B2bFeedRecord::success($this->normalizer()->normalize($thirdRecord)),
        ];
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = $records;
        $this->provider->declaredCount = 3;
        $this->provider->failure = new \RuntimeException('stop before final partial batch');
        $this->provider->failureBeforePosition = 2;
        $processor = $this->processorWithBatchSize(2);

        try {
            $processor->process($runId);
            self::fail('The first attempt must stop before the final partial batch.');
        } catch (\RuntimeException) {
            $this->entityManager->clear();
            $run = $this->entityManager->find(B2bSyncRun::class, $runId);
            self::assertInstanceOf(B2bSyncRun::class, $run);
            self::assertSame(2, $run->checkpoint());
        }

        $this->provider->failure = null;
        $this->provider->failureBeforePosition = null;
        $this->runs->retry($run, 'resume final partial batch');
        $processor->process($runId);

        $this->entityManager->clear();
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM integration_b2b_sync_observation WHERE run_id = '.(int) $runId));
        self::assertSame('completed', $this->connection->fetchOne("SELECT state FROM integration_b2b_sync_run WHERE id = ".(int) $runId));
    }

    public function testDuplicateExternalIdAfterCheckpointResumeIsRejectedDurably(): void
    {
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [B2bFeedRecord::success($this->item(0))];
        $this->provider->declaredCount = 2;
        $this->provider->failure = new \RuntimeException('stop after first committed batch');

        try {
            $this->processor->process($runId);
            self::fail('The first attempt must stop at the checkpoint.');
        } catch (\RuntimeException) {
            $this->entityManager->clear();
            $run = $this->entityManager->find(B2bSyncRun::class, $runId);
            self::assertInstanceOf(B2bSyncRun::class, $run);
            self::assertSame(1, $run->checkpoint());
        }

        $duplicateRecord = $this->fixtureRecord(0);
        $duplicateRecord['listefiyati'] = '999.00';
        $duplicateRecord['mevcut_stok'] = '99';
        $this->provider->records = [
            B2bFeedRecord::success($this->item(0)),
            B2bFeedRecord::success($this->normalizer()->normalize($duplicateRecord)),
        ];
        $this->provider->failure = null;
        $this->runs->retry($run, 'resume with duplicate external ID');

        $this->expectException(\App\Module\Integration\B2b\Exception\B2bRetryableProviderException::class);
        try {
            $this->processor->process($runId);
        } finally {
            $this->entityManager->clear();
            $product = $this->entityManager->getRepository(Product::class)->findOneBy(['sku' => 'GVA 9120688']);
            self::assertInstanceOf(Product::class, $product);
            self::assertSame(100_664, $this->price($product)->basePrice()->minorAmount());
            self::assertSame(1, $this->inventory($product)->quantity());
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
            self::assertSame('conflict', $this->connection->fetchOne("SELECT error_type FROM integration_b2b_sync_error WHERE run_id = ".(int) $runId." ORDER BY id DESC LIMIT 1"));
            $reloadedRun = $this->entityManager->find(B2bSyncRun::class, $runId);
            self::assertInstanceOf(B2bSyncRun::class, $reloadedRun);
            self::assertSame(1, $reloadedRun->checkpoint());
        }
    }

    public function testActiveLockDoesNotSilentlyAcknowledgeTheRun(): void
    {
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $blockingLock = (new \App\Module\Integration\B2b\B2bSyncLock($this->connection))->acquire('efe');
        self::assertInstanceOf(\Symfony\Component\Lock\LockInterface::class, $blockingLock);

        try {
            $this->processor->process($runId);
            self::fail('Lock contention must remain retryable.');
        } catch (B2bLockUnavailableException) {
            self::assertSame(0, $run->checkpoint());
        } finally {
            $blockingLock->release();
        }
    }

    public function testShortProviderStreamCannotReachDailyReconciliation(): void
    {
        $unseenRecord = $this->fixtureRecord(1);
        $unseenRecord['mevcut_stok'] = '9';
        $unseen = $this->writer->importFull($this->normalizer()->normalize($unseenRecord), 999);
        self::assertTrue($unseen->isSuccess());
        $unseenId = $unseen->product()?->id();
        self::assertNotNull($unseenId);
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [B2bFeedRecord::success($this->item(0))];
        $this->provider->declaredCount = 2;

        $this->expectException(\App\Module\Integration\B2b\Exception\B2bRetryableProviderException::class);
        try {
            $this->processor->process($runId);
        } finally {
            $this->entityManager->clear();
            $stillUnseen = $this->entityManager->find(Product::class, $unseenId);
            self::assertInstanceOf(Product::class, $stillUnseen);
            self::assertSame(9, $this->inventory($stillUnseen)->quantity());
        }
    }

    public function testEmptyDailySnapshotCannotZeroExistingCatalog(): void
    {
        $existing = $this->writer->importFull($this->item(0), 999);
        self::assertTrue($existing->isSuccess());
        $existingId = $existing->product()?->id();
        self::assertNotNull($existingId);
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [];
        $this->provider->declaredCount = 0;

        $this->expectException(\App\Module\Integration\B2b\Exception\B2bRetryableProviderException::class);
        try {
            $this->processor->process($runId);
        } finally {
            $this->entityManager->clear();
            $stillExisting = $this->entityManager->find(Product::class, $existingId);
            self::assertInstanceOf(Product::class, $stillExisting);
            self::assertSame(1, $this->inventory($stillExisting)->quantity());
        }
    }

    public function testDatabaseFailureDuringMappedDailyBlocksReconciliation(): void
    {
        $unseenRecord = $this->fixtureRecord(1);
        $unseenRecord['mevcut_stok'] = '9';
        $unseen = $this->writer->importFull($this->normalizer()->normalize($unseenRecord), 999);
        self::assertTrue($unseen->isSuccess());
        $unseenId = $unseen->product()?->id();
        self::assertNotNull($unseenId);
        $realRepository = self::getContainer()->get(ProductInventoryRepositoryInterface::class);
        $throwingRepository = new class($realRepository) implements ProductInventoryRepositoryInterface {
            public function __construct(private readonly ProductInventoryRepositoryInterface $inner) {}
            public function findOneByProduct(Product $product): ?ProductInventory { return $this->inner->findOneByProduct($product); }
            public function findOneByProductForUpdate(Product $product): ?ProductInventory { return $this->inner->findOneByProductForUpdate($product); }
            public function save(ProductInventory $inventory): void { throw new \RuntimeException('simulated mapped DAILY database failure'); }
        };
        $writer = new B2bCatalogWriter(
            resources: self::getContainer()->get(\App\Module\Integration\B2b\B2bResourceResolver::class),
            catalog: self::getContainer()->get(CatalogManager::class),
            media: $this->media,
            pricing: self::getContainer()->get(PricingManager::class),
            inventory: new InventoryManager($throwingRepository, $this->entityManager),
            prices: self::getContainer()->get(ProductPriceRepositoryInterface::class),
            inventoryRepository: $throwingRepository,
            entityManager: $this->entityManager,
            clock: new MockClock('2026-07-25T12:00:00+00:00'),
        );
        $resources = self::getContainer()->get(\App\Module\Integration\B2b\B2bResourceResolver::class);
        $batch = new B2bBatchProcessor($writer, $this->runs, $resources, $this->entityManager, new MockClock('2026-07-25T12:00:00+00:00'));
        $reconciler = new B2bDailyReconciler(
            self::getContainer()->get(ExternalResourceMappingRepository::class),
            $this->entityManager,
            $throwingRepository,
            new InventoryManager($throwingRepository, $this->entityManager),
        );
        $processor = new B2bRunProcessor(
            runs: self::getContainer()->get(B2bSyncRunRepository::class),
            runManager: $this->runs,
            providers: new B2bProviderRegistry([$this->provider]),
            batchProcessor: $batch,
            reconciler: $reconciler,
            lock: new \App\Module\Integration\B2b\B2bSyncLock($this->connection),
            cleaner: new \App\Module\Integration\B2b\B2bSnapshotCleaner($this->directory, new MockClock('2026-07-25T12:00:00+00:00')),
            entityManager: $this->entityManager,
            snapshotDirectory: $this->directory,
            batchSize: 1,
            logger: self::getContainer()->get(\Psr\Log\LoggerInterface::class),
            clock: new MockClock('2026-07-25T12:00:00+00:00'),
        );
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [B2bFeedRecord::success($this->item(0))];

        $this->expectException(\App\Module\Integration\B2b\Exception\B2bRetryableProviderException::class);
        try {
            $processor->process($runId);
        } finally {
            $this->entityManager->clear();
            $stillUnseen = $this->entityManager->find(Product::class, $unseenId);
            self::assertInstanceOf(Product::class, $stillUnseen);
            self::assertSame(9, $this->inventory($stillUnseen)->quantity());
        }
    }

    public function testPartialDailyFailureLeavesUnseenStockUntouchedAndKeepsCheckpoint(): void
    {
        $this->writer->importFull($this->item(0), 1);
        $secondRecord = $this->fixtureRecord(1);
        $secondRecord['mevcut_stok'] = '9';
        $second = $this->writer->importFull($this->normalizer()->normalize($secondRecord), 1);
        $secondProduct = $second->product();
        self::assertInstanceOf(Product::class, $secondProduct);
        $secondId = $secondProduct->id();
        $run = $this->runs->createOrGetActive('efe', B2bSyncMode::Daily)->run;
        $runId = $run->id();
        self::assertNotNull($runId);
        $this->provider->records = [B2bFeedRecord::success($this->item(0))];
        $this->provider->failure = new \RuntimeException('partial feed');

        try {
            $this->processor->process($runId);
            self::fail('A partial provider stream must fail the run.');
        } catch (\RuntimeException) {
            $this->entityManager->clear();
            $unseen = $this->entityManager->find(Product::class, $secondId);
            self::assertInstanceOf(Product::class, $unseen);
            self::assertSame(9, $this->inventory($unseen)->quantity());
            $reloadedRun = $this->entityManager->find(B2bSyncRun::class, $runId);
            self::assertInstanceOf(B2bSyncRun::class, $reloadedRun);
            self::assertSame(1, $reloadedRun->checkpoint());
        }
    }

    private function processorWithBatchSize(int $batchSize): B2bRunProcessor
    {
        $runRepository = $this->entityManager->getRepository(B2bSyncRun::class);
        self::assertInstanceOf(B2bSyncRunRepository::class, $runRepository);
        $resources = self::getContainer()->get(\App\Module\Integration\B2b\B2bResourceResolver::class);
        self::assertInstanceOf(\App\Module\Integration\B2b\B2bResourceResolver::class, $resources);
        $mappingRepository = self::getContainer()->get(ExternalResourceMappingRepository::class);
        self::assertInstanceOf(ExternalResourceMappingRepository::class, $mappingRepository);
        $batch = new B2bBatchProcessor(
            $this->writer,
            $this->runs,
            $resources,
            $this->entityManager,
            new MockClock('2026-07-25T12:00:00+00:00'),
        );
        $reconciler = new B2bDailyReconciler(
            $mappingRepository,
            $this->entityManager,
            self::getContainer()->get(ProductInventoryRepositoryInterface::class),
            self::getContainer()->get(InventoryManager::class),
        );

        return new B2bRunProcessor(
            runs: $runRepository,
            runManager: $this->runs,
            providers: new B2bProviderRegistry([$this->provider]),
            batchProcessor: $batch,
            reconciler: $reconciler,
            lock: new \App\Module\Integration\B2b\B2bSyncLock($this->connection),
            cleaner: new \App\Module\Integration\B2b\B2bSnapshotCleaner($this->directory, new MockClock('2026-07-25T12:00:00+00:00')),
            entityManager: $this->entityManager,
            snapshotDirectory: $this->directory,
            batchSize: $batchSize,
            logger: self::getContainer()->get(\Psr\Log\LoggerInterface::class),
            clock: new MockClock('2026-07-25T12:00:00+00:00'),
        );
    }

    private function item(int $index): \App\Module\Integration\B2b\NormalizedCatalogFeedItem
    {
        return $this->normalizer()->normalize($this->fixtureRecord($index));
    }

    private function normalizer(): EfeFeedNormalizer
    {
        return new EfeFeedNormalizer(new EfePriceNormalizer(new TaxCalculator(), new PercentageDiscountCalculator()), ['b2b.efeotoyedekparca.com.tr']);
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

    private function price(Product $product): \App\Entity\Commerce\ProductPrice
    {
        $repository = $this->entityManager->getRepository(\App\Entity\Commerce\ProductPrice::class);
        self::assertInstanceOf(ProductPriceRepositoryInterface::class, $repository);
        $price = $repository->findOneByProduct($product);
        self::assertInstanceOf(\App\Entity\Commerce\ProductPrice::class, $price);

        return $price;
    }

    private function inventory(Product $product): ProductInventory
    {
        $repository = $this->entityManager->getRepository(ProductInventory::class);
        self::assertInstanceOf(ProductInventoryRepositoryInterface::class, $repository);
        $inventory = $repository->findOneByProduct($product);
        self::assertInstanceOf(ProductInventory::class, $inventory);

        return $inventory;
    }
}

final class FakeDailyProvider implements \App\Module\Integration\B2b\B2bFeedProviderInterface
{
    /** @var list<B2bFeedRecord> */
    public array $records = [];
    public ?\Throwable $failure = null;
    public ?int $failureBeforePosition = null;
    public ?int $declaredCount = null;

    public function __construct(private string $directory)
    {
    }

    public function providerKey(): string { return 'efe'; }
    public function status(): B2bProviderStatus { return B2bProviderStatus::fromEndpoint('efe', 'https://example.com/feed'); }
    public function prepareSnapshot(B2bSnapshotRequest $request): B2bSnapshot
    {
        $path = $this->directory.'/'.$request->runId.'.json';
        $declaredCount = $this->declaredCount ?? count($this->records);
        file_put_contents($path, '{"ok":true,"count":'.$declaredCount.',"data":[]}');

        return new B2bSnapshot($path, filesize($path) ?: 1, $declaredCount, hash_file('sha256', $path) ?: str_repeat('0', 64));
    }
    public function streamItems(B2bSnapshot $snapshot, B2bSyncCheckpoint $checkpoint): iterable
    {
        foreach ($this->records as $index => $record) {
            if (null !== $this->failureBeforePosition && $index >= $this->failureBeforePosition) {
                throw $this->failure ?? new \RuntimeException('simulated provider failure');
            }
            if ($index >= $checkpoint->recordOffset) {
                yield $index => $record;
            }
        }
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
