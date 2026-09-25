<?php

namespace App\Module\Integration\B2b;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Catalog\ProductImage;
use App\Entity\Integration\B2bSyncObservation;
use App\Entity\Integration\B2bSyncRun;
use App\Entity\Integration\ExternalResourceMapping;
use App\Module\Catalog\BrandRepositoryInterface;
use App\Module\Catalog\CatalogManager;
use App\Module\Catalog\CatalogSource;
use App\Module\Catalog\CategoryRepositoryInterface;
use App\Module\Catalog\ProductRepositoryInterface;
use App\Module\Integration\B2b\Exception\B2bProductIdentityConflictException;
use App\Module\Integration\B2b\Exception\B2bResourceConflictException;
use App\Repository\Integration\B2bSyncObservationRepository;
use App\Repository\Integration\ExternalResourceMappingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

final class B2bResourceResolver
{
    private ?B2bBatchResourceContext $batch = null;
    private string $providerKey = 'efe';
    private ?int $claimRunId = null;
    private ?int $batchRunId = null;
    private ?B2bSyncRun $observationRun = null;
    private ?int $observationRunId = null;
    /** @var array<string, string> */
    private array $runSkuOwners = [];
    /** @var array<string, array{fingerprint: ?string, position: ?int}> */
    private array $runExternalIds = [];
    /** @var array<string, array{fingerprint: ?string, position: ?int}> */
    private array $batchRunClaims = [];
    /** @var array<string, string> */
    private array $batchRunSkuOwners = [];

    public function __construct(
        private CatalogManager $catalog,
        private ProductRepositoryInterface $products,
        private CategoryRepositoryInterface $categories,
        private BrandRepositoryInterface $brands,
        private ExternalResourceMappingRepository $mappings,
        private B2bSyncObservationRepository $observations,
        private EntityManagerInterface $entityManager,
        private SluggerInterface $slugger,
    ) {
    }

    public function beginRun(int $runId): void
    {
        if ($runId < 1) {
            throw new \InvalidArgumentException('B2B run ID must be positive.');
        }
        if ($this->claimRunId !== $runId) {
            $this->claimRunId = $runId;
            $this->runSkuOwners = [];
            $this->runExternalIds = [];
        }
    }

    public function endRun(int $runId): void
    {
        if ($this->claimRunId === $runId) {
            $this->claimRunId = null;
            $this->runSkuOwners = [];
            $this->runExternalIds = [];
            $this->endBatch();
        }
    }

    /**
     * @param list<B2bFeedRecord> $records
     */
    public function beginBatch(int $runId, string $providerKey, array $records): void
    {
        if ($runId < 1) {
            throw new \InvalidArgumentException('B2B run ID must be positive.');
        }
        $providerKey = mb_strtolower(trim($providerKey));
        if ('' === $providerKey) {
            throw new \InvalidArgumentException('B2B provider key must not be empty.');
        }
        $this->providerKey = $providerKey;
        $this->batchRunId = $runId;
        $this->batchRunClaims = [];
        $this->batchRunSkuOwners = [];
        $this->observationRun = null;
        $this->observationRunId = null;
        $items = [];
        $externalIds = [];
        $skus = [];
        foreach ($records as $record) {
            if ($record->isSuccess() && null !== $record->item()) {
                $items[] = $record->item();
                $externalIds[] = $record->item()->externalId();
                $skus[] = $record->item()->sku();
            } else {
                $error = $record->error();
                if (null !== $error && null !== $error->externalId()) {
                    $externalIds[] = $error->externalId();
                }
            }
        }
        $productMappings = $this->mappings->findByExternalIds($this->providerKey, B2bResourceType::Product, $externalIds);
        $mappingByExternalId = [];
        $productIds = [];
        foreach ($productMappings as $mapping) {
            $mappingByExternalId[$mapping->externalId()] = $mapping;
            $productIds[] = (int) $mapping->localResourceId();
        }
        $mappedProducts = $this->products->findByIds($productIds);
        $unmappedSkus = [];
        foreach ($items as $item) {
            if (!isset($mappingByExternalId[$item->externalId()])) {
                $unmappedSkus[] = $item->sku();
            }
        }
        $skuProducts = $this->products->findBySkus($unmappedSkus);
        $imageIds = [];
        foreach ($items as $item) {
            foreach ($item->imageUrls() as $sourceUrl) {
                $imageIds[] = $this->imageExternalId($sourceUrl, $item->externalId());
            }
        }
        $imageMappings = $this->mappings->findByExternalIds($this->providerKey, B2bResourceType::ProductImage, $imageIds);
        $observations = $this->observations->findByRunAndExternalIds($runId, $this->providerKey, B2bResourceType::Product, $externalIds);
        $skuObservations = $this->observations->findSkuObservationsForRun($runId, $this->providerKey, $skus);
        $this->batch = new B2bBatchResourceContext($productMappings, $mappedProducts, $skuProducts, $imageMappings, $observations, $skuObservations);
    }

    public function endBatch(): void
    {
        $this->batch = null;
        $this->batchRunId = null;
        $this->batchRunClaims = [];
        $this->batchRunSkuOwners = [];
        $this->observationRun = null;
        $this->observationRunId = null;
    }

    public function rollbackBatchClaims(): void
    {
        foreach ($this->batchRunClaims as $externalId => $claim) {
            if (isset($this->runExternalIds[$externalId]) && $this->runExternalIds[$externalId] === $claim) {
                unset($this->runExternalIds[$externalId]);
            }
        }
        foreach ($this->batchRunSkuOwners as $sku => $owner) {
            if (($this->runSkuOwners[$sku] ?? null) === $owner) {
                unset($this->runSkuOwners[$sku]);
            }
        }
        $this->batchRunClaims = [];
        $this->batchRunSkuOwners = [];
    }

    public function claimRecord(B2bFeedRecord $record, int $runId, ?int $position): void
    {
        $externalId = null;
        $fingerprint = null;
        $sku = null;
        if ($record->isSuccess() && null !== $record->item()) {
            $externalId = $record->item()->externalId();
            $fingerprint = $this->itemFingerprint($record->item());
            $sku = B2bProductIdentity::canonicalSku($record->item()->sku());
        } else {
            $externalId = $record->error()?->externalId();
        }
        if (null === $externalId) {
            return;
        }
        $this->claimExternalIdValue($externalId, $fingerprint, $sku, $position, $runId);
    }

    public function existingProduct(NormalizedCatalogFeedItem $item): ?Product
    {
        $fingerprint = $this->itemFingerprint($item);
        if (null === $this->batch || !$this->batch->hasClaimedExternalId($item->externalId(), $fingerprint)) {
            $this->claimExternalIdValue($item->externalId(), $fingerprint, B2bProductIdentity::canonicalSku($item->sku()), null, $this->claimRunId ?? $this->batchRunId);
        }
        $mapping = $this->productMapping($item->externalId());
        if (null !== $mapping) {
            $product = $this->batch?->mappedProduct($item->externalId());
            if (!$product instanceof Product) {
                $product = $this->loadProduct($mapping);
            }
            if (CatalogSource::External !== $product->source()) {
                throw new B2bResourceConflictException('A product mapping points to an invalid local resource.');
            }
            $this->assertMappedProductIdentity($mapping, $product, $item);
            $this->claimSku($item);

            return $product;
        }
        if ((null !== $this->batch && $this->batch->hasOccupiedSku($item->sku())) || (null === $this->batch && null !== $this->products->findOneBySku($item->sku()))) {
            throw new B2bProductIdentityConflictException(sprintf('SKU "%s" belongs to an unrelated local product.', $item->sku()));
        }
        $this->claimSku($item);

        return null;
    }

    public function createProduct(NormalizedCatalogFeedItem $item, ?Brand $brand, \DateTimeImmutable $seenAt, int $runId): Product
    {
        $slug = $this->availableProductSlug($item);
        $product = $this->catalog->createProduct($item->sku(), $item->name(), $slug, CatalogSource::External, $brand);
        $mapping = ExternalResourceMapping::product($this->providerKey, $item->externalId(), $product->id() ?? 0, $runId, $seenAt, $this->itemFingerprint($item));
        $this->mappings->save($mapping);
        $this->entityManager->flush();
        $this->batch?->registerProduct($item->externalId(), $mapping, $product);

        return $product;
    }

    public function markProductSeen(NormalizedCatalogFeedItem $item, int $runId, \DateTimeImmutable $seenAt): void
    {
        $mapping = $this->productMapping($item->externalId());
        if (null === $mapping) {
            throw new B2bResourceConflictException('The product mapping disappeared during synchronization.');
        }
        $mapping->seenIn($runId, $seenAt, $this->itemFingerprint($item), B2bProductIdentity::FINGERPRINT_VERSION);
        $this->entityManager->flush();
    }

    public function markProductSeenIfMapped(string $externalId, int $runId, \DateTimeImmutable $seenAt): void
    {
        $mapping = $this->productMapping($externalId);
        if (null === $mapping) {
            return;
        }
        $mapping->seenIn($runId, $seenAt);
        $this->entityManager->flush();
    }

    public function resolveCategory(NormalizedCatalogFeedItem $item, \DateTimeImmutable $seenAt, int $runId): B2bResolvedCategory
    {
        $externalId = $item->categoryExternalId();
        $cached = $this->batch?->category($externalId);
        if (null !== $cached) {
            return new B2bResolvedCategory($cached->category, 0, 2);
        }
        $rootExternalId = $this->providerKey.':root:'.$this->providerKey.'-otomotiv';
        $cachedRoot = $this->batch?->category($rootExternalId);
        if (null === $cachedRoot) {
            $root = $this->category($rootExternalId, ucfirst($this->providerKey).' Otomotiv', null, $seenAt, $runId);
            $this->batch?->rememberCategory($rootExternalId, $root);
        } else {
            $root = new B2bResolvedCategory($cachedRoot->category, 0, 1);
        }
        $leaf = $this->category($this->providerKey.':category:'.$externalId, $item->categoryName(), $root->category, $seenAt, $runId);
        $resolved = new B2bResolvedCategory(
            $leaf->category,
            $root->createdCount + $leaf->createdCount,
            $root->reusedCount + $leaf->reusedCount,
        );
        $this->batch?->rememberCategory($externalId, $resolved);

        return $resolved;
    }

    public function resolveBrand(NormalizedCatalogFeedItem $item, \DateTimeImmutable $seenAt, int $runId): ?B2bResolvedBrand
    {
        if (null === $item->brandExternalId() || null === $item->brandName()) {
            return null;
        }
        $externalId = $item->brandExternalId();
        if (null !== $this->batch && $this->batch->hasBrand($externalId)) {
            $cached = $this->batch->brand($externalId);
            if (null === $cached) {
                return null;
            }

            return new B2bResolvedBrand($cached->brand);
        }
        $mapping = $this->mappings->findOneByExternalId($this->providerKey, B2bResourceType::Brand, $externalId);
        if (null !== $mapping) {
            $brand = $this->entityManager->find(Brand::class, (int) $mapping->localResourceId());
            if (!$brand instanceof Brand || CatalogSource::External !== $brand->source()) {
                throw new B2bResourceConflictException('A brand mapping points to an invalid local resource.');
            }
            $mapping->seenIn($runId, $seenAt);
            $this->entityManager->flush();
            $resolved = new B2bResolvedBrand($brand);
            $this->batch?->rememberBrand($externalId, $resolved);

            return $resolved;
        }
        $name = $item->brandName();
        $slug = $this->availableBrandSlug($name, $item->brandExternalId());
        $brand = $this->catalog->createBrand($name, $slug, CatalogSource::External);
        $this->catalog->publishBrand($brand);
        $this->mappings->save(ExternalResourceMapping::create($this->providerKey, B2bResourceType::Brand, $item->brandExternalId(), 'brand', $brand->id() ?? 0, $seenAt, $runId));
        $this->entityManager->flush();
        $resolved = new B2bResolvedBrand($brand, true);
        $this->batch?->rememberBrand($externalId, $resolved);

        return $resolved;
    }

    public function imageMapping(string $sourceUrl, string $productExternalId): ?ExternalResourceMapping
    {
        $externalId = $this->imageExternalId($sourceUrl, $productExternalId);

        if (null !== $this->batch) {
            return $this->batch->imageMapping($externalId);
        }

        return $this->mappings->findOneByExternalId($this->providerKey, B2bResourceType::ProductImage, $externalId);
    }

    public function mapImage(string $sourceUrl, ProductImage $image, int $runId, \DateTimeImmutable $seenAt, string $productExternalId): void
    {
        $externalId = $this->imageExternalId($sourceUrl, $productExternalId);
        $mapping = ExternalResourceMapping::create(
            $this->providerKey,
            B2bResourceType::ProductImage,
            $externalId,
            'product_image',
            $image->id() ?? 0,
            $seenAt,
            $runId,
        );
        $this->mappings->save($mapping);
        $this->entityManager->flush();
        $this->batch?->registerImageMapping($mapping);
    }

    /**
     * Drops a mapping whose local resource no longer exists. The mapping table stores local
     * resource IDs without a foreign key, so removing a local image outside the synchronization
     * would otherwise leave a mapping behind that can never be resolved again.
     */
    public function releaseImageMapping(ExternalResourceMapping $mapping): void
    {
        $externalId = $mapping->externalId();
        $this->entityManager->remove($mapping);
        $this->batch?->forgetImageMapping($externalId);
        $this->entityManager->flush();
    }

    private function assertMappedProductIdentity(ExternalResourceMapping $mapping, Product $product, NormalizedCatalogFeedItem $item): void
    {
        if (!B2bProductIdentity::sameSku($product->sku(), $item->sku())) {
            throw new B2bProductIdentityConflictException(sprintf('External product ID "%s" was previously mapped with a different SKU.', $item->externalId()));
        }

        $storedFingerprint = $mapping->contentSha256();
        $currentFingerprint = $this->itemFingerprint($item);
        if (null !== $storedFingerprint && hash_equals($storedFingerprint, $currentFingerprint)) {
            return;
        }
        // A pre-v2 mapping is proven to describe the same product by the trusted external
        // mapping key, the external product ownership the caller enforces and the canonical
        // SKU consistency checked above. Its stored fingerprint mixes the raw provider SKU
        // with mutable catalog identifiers, so a harmless case or whitespace normalization
        // alone must never turn it into a conflict. Accepting it here lets the next
        // successful encounter rewrite the fingerprint as the stable v2 identity.
        if (B2bProductIdentity::isLegacyIdentity($mapping->identityVersion())) {
            return;
        }

        throw new B2bProductIdentityConflictException(sprintf('External product ID "%s" has an invalid product identity fingerprint.', $item->externalId()));
    }

    private function claimExternalIdValue(string $externalId, ?string $fingerprint, ?string $sku, ?int $position, ?int $runId): void
    {
        if (null !== $this->batch) {
            if (!$this->batch->claimExternalId($externalId, $fingerprint, $position)) {
                throw new B2bResourceConflictException(sprintf('External product ID "%s" appears more than once in a batch or run.', $externalId));
            }
            if (null !== $runId) {
                $this->rememberRunClaim($externalId, $fingerprint, $position);
                if (null === $this->batch->observation($externalId)) {
                    $this->persistObservation($runId, $externalId, $sku, $position, $fingerprint);
                }
            }

            return;
        }
        if (null === $runId) {
            return;
        }

        $observations = $this->observations->findByRunAndExternalIds($runId, $this->providerKey, B2bResourceType::Product, [$externalId]);
        $observation = $observations[0] ?? null;
        if (!$this->claimRunExternalId($externalId, $fingerprint, $position, $observation)) {
            throw new B2bResourceConflictException(sprintf('External product ID "%s" appears more than once in a batch or run.', $externalId));
        }
        if (null === $observation) {
            $this->persistObservation($runId, $externalId, $sku, $position, $fingerprint);
        }
    }

    private function claimRunExternalId(string $externalId, ?string $fingerprint, ?int $position, ?B2bSyncObservation $observation): bool
    {
        $existing = $this->runExternalIds[$externalId] ?? null;
        if (null !== $existing) {
            return null !== $position
                && $existing['position'] === $position
                && $existing['fingerprint'] === $fingerprint;
        }
        if (null !== $observation) {
            $this->runExternalIds[$externalId] = [
                'fingerprint' => $fingerprint,
                'position' => $position,
            ];

            return null !== $position
                && $observation->streamPosition() === $position
                && $observation->identitySha256() === $fingerprint;
        }
        $this->rememberRunClaim($externalId, $fingerprint, $position);

        return true;
    }

    private function rememberRunClaim(string $externalId, ?string $fingerprint, ?int $position): void
    {
        if (isset($this->runExternalIds[$externalId])) {
            return;
        }
        $claim = [
            'fingerprint' => $fingerprint,
            'position' => $position,
        ];
        $this->runExternalIds[$externalId] = $claim;
        $this->batchRunClaims[$externalId] = $claim;
    }

    private function persistObservation(int $runId, string $externalId, ?string $sku, ?int $position, ?string $fingerprint): void
    {
        if (!$this->observationRun instanceof B2bSyncRun || $this->observationRunId !== $runId) {
            $run = $this->entityManager->getReference(B2bSyncRun::class, $runId);
            if (!$run instanceof B2bSyncRun) {
                throw new \RuntimeException('The B2B run disappeared while claiming a product record.');
            }
            $this->observationRun = $run;
            $this->observationRunId = $runId;
        }
        $observation = B2bSyncObservation::create(
            $this->observationRun,
            $externalId,
            B2bResourceType::Product,
            $position,
            $fingerprint,
            new \DateTimeImmutable(),
            $sku,
        );
        $this->observations->save($observation);
        $this->batch?->registerObservation($observation);
    }

    private function itemFingerprint(NormalizedCatalogFeedItem $item): string
    {
        return B2bProductIdentity::fingerprint($item);
    }

    private function claimSku(NormalizedCatalogFeedItem $item): void
    {
        $sku = mb_strtoupper(trim($item->sku()));
        if (null !== $this->batch && !$this->batch->claimSku($sku, $item->externalId())) {
            throw new B2bProductIdentityConflictException(sprintf('Feed SKU "%s" is already claimed by another external product.', $item->sku()));
        }
        if (null === $this->claimRunId) {
            return;
        }
        $owner = $this->runSkuOwners[$sku] ?? null;
        if (null !== $owner && $owner !== $item->externalId()) {
            throw new B2bProductIdentityConflictException(sprintf('Feed SKU "%s" is already claimed by another external product.', $item->sku()));
        }
        if (!isset($this->runSkuOwners[$sku])) {
            $this->runSkuOwners[$sku] = $item->externalId();
            $this->batchRunSkuOwners[$sku] = $item->externalId();
        }
    }

    private function productMapping(string $externalId): ?ExternalResourceMapping
    {
        if (null !== $this->batch) {
            return $this->batch->productMapping($externalId);
        }

        return $this->mappings->findOneByExternalId($this->providerKey, B2bResourceType::Product, $externalId);
    }

    private function category(string $externalId, string $name, ?Category $parent, \DateTimeImmutable $seenAt, int $runId): B2bResolvedCategory
    {
        $mapping = $this->mappings->findOneByExternalId($this->providerKey, B2bResourceType::Category, $externalId);
        if (null !== $mapping) {
            $category = $this->entityManager->find(Category::class, (int) $mapping->localResourceId());
            if (!$category instanceof Category || CatalogSource::External !== $category->source()) {
                throw new B2bResourceConflictException('A category mapping points to an invalid local resource.');
            }
            $mapping->seenIn($runId, $seenAt);
            $this->entityManager->flush();

            return new B2bResolvedCategory($category, 0, 1);
        }
        $slug = $this->availableCategorySlug($name, $externalId);
        $category = $this->catalog->createCategory($name, $slug, CatalogSource::External, $parent);
        $this->catalog->publishCategory($category);
        $this->mappings->save(ExternalResourceMapping::create($this->providerKey, B2bResourceType::Category, $externalId, 'category', $category->id() ?? 0, $seenAt, $runId));
        $this->entityManager->flush();

        return new B2bResolvedCategory($category, 1, 0);
    }

    private function loadProduct(ExternalResourceMapping $mapping): Product
    {
        $product = $this->entityManager->find(Product::class, (int) $mapping->localResourceId());
        if (!$product instanceof Product || CatalogSource::External !== $product->source()) {
            throw new B2bResourceConflictException('A product mapping points to an invalid local resource.');
        }

        return $product;
    }

    private function availableProductSlug(NormalizedCatalogFeedItem $item): string
    {
        $base = $this->slug($item->name());
        if (null === $this->products->findOneBySlug($base)) {
            return $base;
        }

        return $this->suffixedSlug($base, $item->externalId());
    }

    private function availableCategorySlug(string $name, string $externalId): string
    {
        $base = $this->slug($name);
        if (null === $this->categories->findOneBySlug($base)) {
            return $base;
        }

        return $this->suffixedSlug($base, $externalId);
    }

    private function availableBrandSlug(string $name, string $externalId): string
    {
        $base = $this->slug($name);
        if (null === $this->brands->findOneBySlug($base)) {
            return $base;
        }

        return $this->suffixedSlug($base, $externalId);
    }

    private function slug(string $value): string
    {
        $slug = $this->slugger->slug($value)->lower()->toString();

        return '' === $slug ? $this->providerKey.'-'.substr(hash('sha256', $value), 0, 12) : $slug;
    }

    private function suffixedSlug(string $base, string $externalId): string
    {
        $suffix = '-'.$this->providerKey.'-'.substr(hash('sha256', $externalId), 0, 8);
        $base = substr($base, 0, 255 - strlen($suffix));

        return $base.$suffix;
    }

    private function imageExternalId(string $sourceUrl, string $productExternalId): string
    {
        $identity = strlen($productExternalId).':'.$productExternalId.$sourceUrl;

        return strlen($identity) <= 191 ? $identity : 'sha256:'.hash('sha256', $identity);
    }
}
