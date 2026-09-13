<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductPrice;
use App\Module\Pricing\PricingManager;
use App\Module\Pricing\ProductPriceRepositoryInterface;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Repository\Commerce\ProductPriceRepository;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PricingManagerIntegrationTest extends KernelTestCase
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

    public function testUpsertCreatesThenUpdatesTheSamePersistedPriceRow(): void
    {
        $product = new Product('PRICE-MANAGER-001', 'Managed Price Product', 'managed-price-product');
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $repository = $this->entityManager->getRepository(ProductPrice::class);
        self::assertInstanceOf(ProductPriceRepository::class, $repository);
        self::assertInstanceOf(ProductPriceRepositoryInterface::class, $repository);
        $manager = new PricingManager($repository, $this->entityManager);

        $created = $manager->upsert(
            $product,
            Money::ofMinor(149_900, 'TRY'),
            TaxCategory::standard(),
            TaxRate::fromBasisPoints(2_000),
            Money::ofMinor(129_900, 'TRY'),
            new \DateTimeImmutable('2026-09-14 09:00:00'),
            new \DateTimeImmutable('2026-09-21 18:00:00'),
        );
        $createdId = $created->id();
        self::assertIsInt($createdId);

        $updated = $manager->upsert(
            $product,
            Money::ofMinor(179_900, 'TRY'),
            TaxCategory::of('reduced-part'),
            TaxRate::fromBasisPoints(1_000),
            Money::ofMinor(159_900, 'TRY'),
            new \DateTimeImmutable('2026-10-01 08:00:00'),
            new \DateTimeImmutable('2026-10-07 20:00:00'),
        );

        self::assertSame($createdId, $updated->id());
        $this->entityManager->clear();

        $reloadedProduct = $this->entityManager->find(Product::class, $product->id());
        self::assertInstanceOf(Product::class, $reloadedProduct);
        $reloaded = $repository->findOneByProduct($reloadedProduct);
        self::assertInstanceOf(ProductPrice::class, $reloaded);
        self::assertSame(1, $repository->count(['product' => $reloadedProduct]));
        self::assertSame(179_900, $reloaded->basePrice()->minorAmount());
        self::assertSame('TRY', $reloaded->basePrice()->currency());
        self::assertSame(159_900, $reloaded->salePrice()?->minorAmount());
        self::assertSame('reduced-part', $reloaded->taxCategory()->key());
        self::assertSame(1_000, $reloaded->taxRate()->basisPoints());
        self::assertSame('2026-10-01 08:00:00', $reloaded->saleStartsAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-07 20:00:00', $reloaded->saleEndsAt()?->format('Y-m-d H:i:s'));
    }

    public function testPersistedOffsetSaleBoundsKeepTheirInstantsAndActivationBoundaries(): void
    {
        $product = new Product('PRICE-MANAGER-ZONE', 'Timezone Price Product', 'timezone-price-product');
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $repository = $this->entityManager->getRepository(ProductPrice::class);
        self::assertInstanceOf(ProductPriceRepository::class, $repository);
        $manager = new PricingManager($repository, $this->entityManager);
        $startsAt = new \DateTimeImmutable('2026-10-01 10:00:00', new \DateTimeZone('Europe/Istanbul'));
        $endsAt = new \DateTimeImmutable('2026-10-02T10:00:00+03:00');

        $manager->upsert(
            $product,
            Money::ofMinor(12_000, 'TRY'),
            TaxCategory::standard(),
            TaxRate::fromBasisPoints(2_000),
            Money::ofMinor(9_000, 'TRY'),
            $startsAt,
            $endsAt,
        );
        $this->entityManager->clear();

        $reloadedProduct = $this->entityManager->find(Product::class, $product->id());
        self::assertInstanceOf(Product::class, $reloadedProduct);
        $reloaded = $repository->findOneByProduct($reloadedProduct);
        self::assertInstanceOf(ProductPrice::class, $reloaded);
        self::assertSame($startsAt->getTimestamp(), $reloaded->saleStartsAt()?->getTimestamp());
        self::assertSame($endsAt->getTimestamp(), $reloaded->saleEndsAt()?->getTimestamp());
        self::assertFalse($reloaded->isSaleActiveAt($startsAt->modify('-1 second')));
        self::assertTrue($reloaded->isSaleActiveAt($startsAt));
        self::assertSame(9_000, $reloaded->sellPriceAt($startsAt)->minorAmount());
        self::assertTrue($reloaded->isSaleActiveAt($endsAt->modify('-1 second')));
        self::assertFalse($reloaded->isSaleActiveAt($endsAt));
        self::assertSame(12_000, $reloaded->sellPriceAt($endsAt)->minorAmount());
    }
}
