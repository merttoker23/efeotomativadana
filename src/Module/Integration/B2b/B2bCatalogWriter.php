<?php

namespace App\Module\Integration\B2b;

use App\Entity\Catalog\Product;
use App\Entity\Catalog\ProductImage;
use App\Module\Catalog\CatalogManager;
use App\Module\Catalog\Exception\CatalogConflict;
use App\Module\Inventory\InventoryManager;
use App\Module\Inventory\ProductInventoryRepositoryInterface;
use App\Module\Pricing\PricingManager;
use App\Module\Pricing\ProductPriceRepositoryInterface;
use App\Module\Pricing\TaxCategory;
use App\Module\Integration\B2b\Exception\B2bPermanentProviderException;
use App\Module\Integration\B2b\Exception\B2bProductIdentityConflictException;
use App\Module\Integration\B2b\Exception\B2bResourceConflictException;
use App\Module\Integration\B2b\Exception\B2bRetryableProviderException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final class B2bCatalogWriter
{
    private const IMAGE_STORE_ATTEMPTS = 3;

    /** @var list<StoredProductImage> */
    private array $pendingMedia = [];
    private bool $mediaTransactionActive = false;

    public function __construct(
        private B2bResourceResolver $resources,
        private CatalogManager $catalog,
        private ProductMediaStorageInterface $media,
        private PricingManager $pricing,
        private InventoryManager $inventory,
        private ProductPriceRepositoryInterface $prices,
        private ProductInventoryRepositoryInterface $inventoryRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function beginMediaTransaction(): void
    {
        if ($this->mediaTransactionActive) {
            throw new \LogicException('A B2B media transaction is already active.');
        }
        $this->mediaTransactionActive = true;
    }

    public function commitMediaTransaction(): void
    {
        $this->pendingMedia = [];
        $this->mediaTransactionActive = false;
    }

    public function rollbackMediaTransaction(): void
    {
        foreach ($this->pendingMedia as $image) {
            $this->removeStoredImage($image);
        }
        $this->pendingMedia = [];
        $this->mediaTransactionActive = false;
    }

    public function hasPendingMedia(): bool
    {
        return $this->mediaTransactionActive;
    }

    public function importFull(NormalizedCatalogFeedItem $item, int $runId, ?Product $resolvedProduct = null, bool $productWasResolved = false): B2bItemResult
    {
        $seenAt = $this->clock->now();
        $counters = B2bSyncCounters::empty();
        $deferredErrors = [];
        try {
            $product = $productWasResolved ? $resolvedProduct : $this->resources->existingProduct($item);
            $category = $this->resources->resolveCategory($item, $seenAt, $runId);
            $counters = $counters
                ->recordCategoryCreated($category->createdCount)
                ->recordCategoryReused($category->reusedCount);
            $brand = $this->resources->resolveBrand($item, $seenAt, $runId);
            $brandEntity = $brand?->brand;
            if (null === $product) {
                $product = $this->resources->createProduct($item, $brandEntity, $seenAt, $runId);
                $this->catalog->publishProduct($product);
                $counters = $counters->recordCreated();
                if (null !== $item->description()) {
                    $product->describe($item->description());
                }
                $product->addCategory($category->category);
            } else {
                $this->lockProduct($product);
                $product->rename($item->name());
                if (null !== $item->description()) {
                    $product->describe($item->description());
                }
                if (null !== $brandEntity) {
                    $product->changeBrand($brandEntity);
                }
                $product->addCategory($category->category);
                $this->catalog->saveProduct($product);
                $counters = $counters->recordUpdated();
            }
            if (!in_array($category->category, $product->categories(), true)) {
                $product->addCategory($category->category);
            }
            $counters = $counters->recordIdentifiersImported($this->addIdentifiers($product, $item));
            foreach ($item->attributes() as $key => $value) {
                $product->setAttribute($key, $value);
            }
            $this->catalog->saveProduct($product);
            $this->resources->markProductSeen($item, $runId, $seenAt);
            [$counters, $deferredErrors] = $this->importFullImages($product, $item, $runId, $seenAt, $counters, $deferredErrors);
            $this->pricing->upsert($product, $item->grossPrice(), TaxCategory::standard(), $item->taxRate());
            $this->inventory->upsert($product, $item->stock(), $item->stock() > 0);
            $counters = $counters->recordPriceUpdated()->recordStockUpdated();

            return B2bItemResult::success($product, $counters, $deferredErrors);
        } catch (B2bResourceConflictException|B2bProductIdentityConflictException|CatalogConflict $exception) {
            return B2bItemResult::failure(new B2bItemError(B2bErrorType::Conflict, $exception->getMessage(), $item->externalId()));
        }
    }

    public function updateDaily(NormalizedCatalogFeedItem $item, int $runId): B2bItemResult
    {
        try {
            $seenAt = $this->clock->now();
            $product = $this->resources->existingProduct($item);
            if (null === $product) {
                $created = $this->importFull($item, $runId, null, true);

                if (!$created->isSuccess() || null === $created->product()) {
                    return $created;
                }

                return B2bItemResult::success($created->product(), $created->counters()->recordDailyNewProduct(), $created->deferredErrors());
            }
            $counters = B2bSyncCounters::empty();
            $this->lockProduct($product);
            $this->pricing->upsert($product, $item->grossPrice(), TaxCategory::standard(), $item->taxRate());
            $this->inventory->upsert($product, $item->stock(), $item->stock() > 0);
            $this->resources->markProductSeen($item, $runId, $seenAt);
            $counters = $counters->recordUpdated()->recordPriceUpdated()->recordStockUpdated();

            return B2bItemResult::success($product, $counters);
        } catch (B2bProductIdentityConflictException $exception) {
            return B2bItemResult::failure(new B2bItemError(B2bErrorType::Conflict, $exception->getMessage(), $item->externalId()));
        }
    }

    private function addIdentifiers(Product $product, NormalizedCatalogFeedItem $item): int
    {
        $existing = [];
        foreach ($product->identifiers() as $identifier) {
            $existing[$identifier->type()->value."\0".$identifier->code()] = true;
        }
        $imported = 0;
        foreach ($item->identifiers() as [$type, $code]) {
            $key = $type->value."\0".mb_strtoupper($code);
            if (!isset($existing[$key])) {
                $product->addIdentifier($type, $code);
                $existing[$key] = true;
                ++$imported;
            }
        }

        return $imported;
    }

    /**
     * @param list<B2bItemError> $deferredErrors
     * @return array{B2bSyncCounters, list<B2bItemError>}
     */
    private function importFullImages(Product $product, NormalizedCatalogFeedItem $item, int $runId, \DateTimeImmutable $seenAt, B2bSyncCounters $counters, array $deferredErrors): array
    {
        $sourceUrls = [];
        foreach ($item->imageUrls() as $position => $sourceUrl) {
            if (isset($sourceUrls[$sourceUrl])) {
                continue;
            }
            $sourceUrls[$sourceUrl] = true;
            $mapping = $this->resources->imageMapping($sourceUrl, $item->externalId());
            if (null !== $mapping) {
                $image = $this->entityManager->find(ProductImage::class, (int) $mapping->localResourceId());
                if ($image instanceof ProductImage) {
                    if ($image->product() === $product) {
                        continue;
                    }
                    $deferredErrors[] = new B2bItemError(B2bErrorType::Image, 'An existing image mapping points to an image owned by another product.', $item->externalId(), ['position' => $position]);
                    $counters = $counters->recordImagesFailed();
                    continue;
                }
                // The local image row is gone while its external mapping survived, because the
                // mapping table has no foreign key to the image table. That mapping is stale, not
                // a permanent failure: release it and import the image again so the product can
                // recover instead of erroring on every later run.
                $this->resources->releaseImageMapping($mapping);
            }
            $stored = null;
            $image = null;
            try {
                $stored = $this->storeImage($sourceUrl, $item->name());
                $image = $product->addImage($stored->path, $item->name(), $position);
                $this->catalog->saveProduct($product);
                $this->resources->mapImage($sourceUrl, $image, $runId, $seenAt, $item->externalId());
                if ($this->mediaTransactionActive) {
                    $this->pendingMedia[] = $stored;
                }
                $counters = $counters->recordImagesImported();
            } catch (B2bPermanentProviderException $exception) {
                $this->discardImage($product, $image, $stored);
                $deferredErrors[] = new B2bItemError(B2bErrorType::Image, 'The product image could not be stored.', $item->externalId(), ['position' => $position]);
                $counters = $counters->recordImagesFailed();
            } catch (\Throwable $exception) {
                $this->discardImage($product, $image, $stored);
                throw $exception;
            }
        }
        foreach ($item->imageErrors() as $error) {
            $deferredErrors[] = $error;
            $counters = $counters->recordImagesFailed();
        }

        return [$counters, $deferredErrors];
    }

    /**
     * Transient image transport failures are retried in place. Once the attempts are exhausted
     * the failure is downgraded to a permanent one so the caller records it against the item and
     * the catalog run keeps going, instead of aborting a 92k record synchronization.
     */
    private function storeImage(string $sourceUrl, string $altText): StoredProductImage
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $this->media->store($sourceUrl, $altText);
            } catch (B2bRetryableProviderException $exception) {
                if ($attempt >= self::IMAGE_STORE_ATTEMPTS) {
                    throw new B2bPermanentProviderException('The product image could not be stored after repeated attempts.', 0, $exception);
                }
            }
        }
    }

    private function discardImage(Product $product, ?ProductImage $image, ?StoredProductImage $stored): void
    {
        if ($image instanceof ProductImage) {
            try {
                $product->removeImage($image);
                $this->catalog->saveProduct($product);
            } catch (\Throwable) {
            }
        }
        if ($stored instanceof StoredProductImage) {
            $this->removeStoredImage($stored);
        }
    }

    private function removeStoredImage(StoredProductImage $image): void
    {
        try {
            $this->media->remove($image);
        } catch (\Throwable) {
        }
    }

    private function lockProduct(Product $product): void
    {
        $this->entityManager->refresh($product, LockMode::PESSIMISTIC_WRITE);
        $this->prices->findOneByProductForUpdate($product);
        $this->inventoryRepository->findOneByProductForUpdate($product);
    }

}
