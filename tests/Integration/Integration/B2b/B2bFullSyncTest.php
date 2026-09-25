<?php

namespace App\Tests\Integration\Integration\B2b;

use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Entity\Integration\ExternalResourceMapping;
use App\Module\Catalog\CatalogManager;
use App\Module\Catalog\CatalogSource;
use App\Module\Integration\B2b\B2bCatalogWriter;
use App\Module\Integration\B2b\B2bItemResult;
use App\Module\Integration\B2b\Exception\B2bPermanentProviderException;
use App\Module\Integration\B2b\Provider\Efe\EfeFeedNormalizer;
use App\Module\Integration\B2b\Provider\Efe\EfePriceNormalizer;
use App\Module\Pricing\PercentageDiscountCalculator;
use App\Module\Pricing\PricingManager;
use App\Module\Pricing\ProductPriceRepositoryInterface;
use App\Module\Pricing\TaxCalculator;
use App\Module\Inventory\InventoryManager;
use App\Module\Inventory\ProductInventoryRepositoryInterface;
use App\Repository\Catalog\CategoryRepositoryInterface;
use App\Repository\Integration\ExternalResourceMappingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class B2bFullSyncTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private B2bCatalogWriter $writer;
    private InMemoryProductMediaStorage $media;
    private CatalogManager $catalog;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->catalog = self::getContainer()->get(CatalogManager::class);
        self::assertInstanceOf(CatalogManager::class, $this->catalog);
        $this->media = new InMemoryProductMediaStorage();
        $this->writer = new B2bCatalogWriter(
            resources: self::getContainer()->get(\App\Module\Integration\B2b\B2bResourceResolver::class),
            catalog: $this->catalog,
            media: $this->media,
            pricing: self::getContainer()->get(PricingManager::class),
            inventory: self::getContainer()->get(InventoryManager::class),
            prices: self::getContainer()->get(ProductPriceRepositoryInterface::class),
            inventoryRepository: self::getContainer()->get(ProductInventoryRepositoryInterface::class),
            entityManager: $entityManager,
            clock: new MockClock('2026-07-25T12:00:00+00:00'),
        );
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testFullImportCreatesCompleteProductAndSecondRunIsIdempotent(): void
    {
        $item = $this->fixtureItem();

        $first = $this->writer->importFull($item, 100);
        self::assertTrue($first->isSuccess(), $first->error()?->message() ?? 'FULL import failed.');
        self::assertSame(1, $first->counters()->created());
        self::assertSame(5, $first->counters()->identifiersImported());
        self::assertCount(1, $first->product()?->images() ?? []);
        $product = $first->product();
        self::assertInstanceOf(Product::class, $product);
        $productId = $product->id();
        self::assertNotNull($productId);
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Product::class, $productId);
        self::assertInstanceOf(Product::class, $reloaded);
        self::assertSame(CatalogSource::External, $reloaded->source());
        self::assertSame('published', $reloaded->publicationStatus()->value);
        self::assertCount(1, $reloaded->categories());
        self::assertSame('STOP', $reloaded->categories()[0]->name());
        self::assertCount(5, $reloaded->identifiers());
        self::assertSame(['/uploads/products/'.hash('sha256', 'https://b2b.efeotoyedekparca.com.tr/urunler/fixture-1.jpg').'.png'], array_map(static fn ($image): string => $image->path(), $reloaded->images()));
        self::assertSame('1', $this->attribute($reloaded, 'efe-box-quantity'));

        $priceRepository = $this->entityManager->getRepository(ProductPrice::class);
        self::assertInstanceOf(ProductPriceRepositoryInterface::class, $priceRepository);
        $price = $priceRepository->findOneByProduct($reloaded);
        self::assertInstanceOf(ProductPrice::class, $price);
        self::assertSame(100_664, $price->basePrice()->minorAmount());
        self::assertSame(2_000, $price->taxRate()->basisPoints());
        $inventoryRepository = $this->entityManager->getRepository(ProductInventory::class);
        self::assertInstanceOf(ProductInventoryRepositoryInterface::class, $inventoryRepository);
        $inventory = $inventoryRepository->findOneByProduct($reloaded);
        self::assertInstanceOf(ProductInventory::class, $inventory);
        self::assertSame(1, $inventory->quantity());
        self::assertTrue($inventory->availableForSale());
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM integration_external_mapping WHERE provider_key = 'efe' AND resource_type = 'product' AND external_id = '1001'"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM integration_external_mapping WHERE provider_key = 'efe' AND resource_type = 'product_image'"));

        $second = $this->writer->importFull($this->fixtureItem(), 101);
        self::assertTrue($second->isSuccess(), $second->error()?->message() ?? 'FULL second import failed.');
        self::assertSame(0, $second->counters()->created());
        self::assertSame(1, $second->counters()->updated());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product_image'));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM integration_external_mapping WHERE resource_type = 'product'"));
    }

    public function testFullImportRejectsZeroPriceProducts(): void
    {
        $record = $this->fixtureRecord();
        $record['listefiyati'] = '0.00';

        $this->expectException(B2bPermanentProviderException::class);

        $this->normalizer()->normalize($record);
    }

    /**
     * Emptied catalog_product_image rows leave their integration_external_mapping rows behind,
     * because that table has no foreign key to the image table. A FULL re-run must repair the
     * stale mapping and import the image again instead of failing the product forever.
     */
    public function testStaleImageMappingIsRepairedByImportingTheImageAgain(): void
    {
        $first = $this->writer->importFull($this->fixtureItem(), 150);
        self::assertTrue($first->isSuccess(), $first->error()?->message() ?? 'FULL import failed.');
        $product = $first->product();
        self::assertInstanceOf(Product::class, $product);
        $productId = $product->id();
        self::assertNotNull($productId);
        self::assertCount(1, $product->images());
        $this->connection->executeStatement('DELETE FROM catalog_product_image');
        $this->entityManager->clear();
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM integration_external_mapping WHERE resource_type = 'product_image'"));

        $reloaded = $this->entityManager->find(Product::class, $productId);
        self::assertInstanceOf(Product::class, $reloaded);
        self::assertCount(0, $reloaded->images());
        $second = $this->writer->importFull($this->fixtureItem(), 151);

        self::assertTrue($second->isSuccess(), $second->error()?->message() ?? 'FULL repair failed.');
        self::assertSame([], $second->deferredErrors());
        self::assertSame(1, $second->counters()->imagesImported());
        self::assertSame(0, $second->counters()->imagesFailed());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product_image'));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM integration_external_mapping WHERE resource_type = 'product_image'"));
        $this->entityManager->clear();
        $withImage = $this->entityManager->find(Product::class, $productId);
        self::assertInstanceOf(Product::class, $withImage);
        self::assertCount(1, $withImage->images());
    }

    /**
     * An image mapping that still points at a live image owned by a different product is a real
     * integrity problem and must stay a recorded failure instead of stealing the other product.
     */
    public function testImageMappingOwnedByAnotherProductStaysAFailure(): void
    {
        $first = $this->writer->importFull($this->fixtureItem(), 500);
        self::assertTrue($first->isSuccess());
        $otherRecord = $this->fixtureRecord();
        $otherRecord['id'] = '1002';
        $otherRecord['stokkodu'] = 'OTHER-IMAGE-OWNER';
        $otherRecord['barkod'] = 'OTHER-IMAGE-OWNER';
        $other = $this->writer->importFull($this->normalizer()->normalize($otherRecord), 501);
        self::assertTrue($other->isSuccess());
        $otherImages = $other->product()?->images() ?? [];
        self::assertCount(1, $otherImages);
        $foreignImageId = $otherImages[0]->id();
        self::assertNotNull($foreignImageId);
        $this->connection->executeStatement(
            "UPDATE integration_external_mapping SET local_resource_id = ? WHERE resource_type = 'product_image'",
            [(string) $foreignImageId],
        );
        $this->entityManager->clear();

        $second = $this->writer->importFull($this->fixtureItem(), 502);

        self::assertTrue($second->isSuccess());
        self::assertSame(0, $second->counters()->imagesImported());
        self::assertSame(1, $second->counters()->imagesFailed());
        self::assertCount(1, $second->deferredErrors());
        self::assertSame('image', $second->deferredErrors()[0]->errorType()->value);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product_image'));
        $this->entityManager->clear();
        $stillOwned = $this->entityManager->find(\App\Entity\Catalog\ProductImage::class, $foreignImageId);
        self::assertInstanceOf(\App\Entity\Catalog\ProductImage::class, $stillOwned);
        self::assertSame('OTHER-IMAGE-OWNER', $stillOwned->product()->sku());
    }

    public function testTheSameRemoteImageCanBelongToDifferentProducts(): void
    {
        $first = $this->fixtureRecord();
        $second = $this->fixtureRecord();
        $second['id'] = '1002';
        $second['stokkodu'] = 'SHARED-IMAGE-1002';
        $second['barkod'] = 'SHARED-IMAGE-1002';
        $firstResult = $this->writer->importFull($this->normalizer()->normalize($first), 400);
        $secondResult = $this->writer->importFull($this->normalizer()->normalize($second), 401);

        self::assertTrue($firstResult->isSuccess());
        self::assertTrue($secondResult->isSuccess());
        self::assertCount(1, $firstResult->product()?->images() ?? []);
        self::assertCount(1, $secondResult->product()?->images() ?? []);
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM integration_external_mapping WHERE resource_type = 'product_image'"));
    }

    public function testUntrustedLocalSkuCollisionDoesNotMergeOrCreateMapping(): void
    {
        $local = $this->catalog->createProduct('GVA 9120688', 'Locally owned product');
        $localId = $local->id();
        self::assertNotNull($localId);

        $result = $this->writer->importFull($this->fixtureItem(), 200);

        self::assertFalse($result->isSuccess());
        self::assertSame('conflict', $result->error()?->errorType()->value);
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Product::class, $localId);
        self::assertInstanceOf(Product::class, $reloaded);
        self::assertSame(CatalogSource::Local, $reloaded->source());
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM integration_external_mapping WHERE resource_type = 'product' AND external_id = '1001'"));
    }

    public function testFullRefreshPreservesStableSlugPublicationAndLocalDescription(): void
    {
        $first = $this->writer->importFull($this->fixtureItem(), 300);
        self::assertTrue($first->isSuccess(), $first->error()?->message() ?? 'FULL import failed.');
        $product = $first->product();
        self::assertInstanceOf(Product::class, $product);
        $product->describe('Locally edited description');
        $this->entityManager->flush();
        $slug = $product->slug();

        $record = $this->fixtureRecord();
        $record['cinsi'] = 'Provider refreshed title';
        $record['aciklama'] = '';
        $result = $this->writer->importFull($this->normalizer()->normalize($record), 301);

        self::assertTrue($result->isSuccess(), $result->error()?->message() ?? 'FULL refresh failed.');
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Product::class, $product->id());
        self::assertInstanceOf(Product::class, $reloaded);
        self::assertSame($slug, $reloaded->slug());
        self::assertSame('published', $reloaded->publicationStatus()->value);
        self::assertSame('Locally edited description', $reloaded->description());
        self::assertSame('Provider refreshed title', $reloaded->name());
    }

    private function fixtureItem(): \App\Module\Integration\B2b\NormalizedCatalogFeedItem
    {
        return $this->normalizer()->normalize($this->fixtureRecord());
    }

    /** @return array<string, mixed> */
    private function fixtureRecord(): array
    {
        $json = file_get_contents(__DIR__.'/../../../Fixtures/Integration/Efe/efe-feed-sanitized.json');
        self::assertIsString($json);
        $fixture = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertIsArray($fixture['data'][0]);

        return $fixture['data'][0];
    }

    private function normalizer(): EfeFeedNormalizer
    {
        return new EfeFeedNormalizer(
            new EfePriceNormalizer(new TaxCalculator(), new PercentageDiscountCalculator()),
            ['b2b.efeotoyedekparca.com.tr'],
        );
    }

    private function attribute(Product $product, string $key): string
    {
        foreach ($product->attributes() as $attribute) {
            if ($attribute->key() === $key) {
                return $attribute->value();
            }
        }

        return '';
    }
}
