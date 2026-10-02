<?php

namespace App\Module\Integration\B2b;

use App\Entity\Catalog\Product;
use App\Entity\Integration\B2bSyncObservation;
use App\Entity\Integration\ExternalResourceMapping;

/**
 * @internal Batch-scoped identity and resource lookup state.
 */
final class B2bBatchResourceContext
{
    /** @var array<string, ExternalResourceMapping> */
    private array $productMappings = [];

    /** @var array<string, Product> */
    private array $mappedProducts = [];

    /** @var array<string, list<Product>> */
    private array $skuProducts = [];

    /** @var array<int, string> */
    private array $productOwners = [];

    /** @var array<string, string> */
    private array $skuOwners = [];

    /** @var array<string, string> */
    private array $observedSkuOwners = [];

    /** @var array<string, array{fingerprint: ?string, position: ?int}> */
    private array $claimedExternalIds = [];

    /** @var array<string, B2bSyncObservation> */
    private array $observations = [];

    /** @var array<string, ExternalResourceMapping> */
    private array $imageMappings = [];

    /** @var array<string, B2bResolvedCategory> */
    private array $categories = [];

    /** @var array<string, B2bResolvedBrand> */
    private array $brands = [];

    /** @var array<string, true> */
    private array $missingBrands = [];

    /**
     * @param list<ExternalResourceMapping> $productMappings
     * @param list<Product>                  $mappedProducts
     * @param list<Product>                  $skuProducts
     * @param array<int, string>             $skuProductOwners
     * @param list<ExternalResourceMapping>  $imageMappings
     * @param list<B2bSyncObservation>       $observations
     * @param list<B2bSyncObservation>       $skuObservations
     */
    public function __construct(array $productMappings, array $mappedProducts, array $skuProducts, array $skuProductOwners, array $imageMappings, array $observations, array $skuObservations)
    {
        foreach ($productMappings as $mapping) {
            $this->productMappings[$mapping->externalId()] = $mapping;
        }
        foreach ($mappedProducts as $product) {
            $id = $product->id();
            if (null !== $id) {
                foreach ($productMappings as $mapping) {
                    if ($mapping->resourceType() === B2bResourceType::Product && (int) $mapping->localResourceId() === $id) {
                        $this->mappedProducts[$mapping->externalId()] = $product;
                        break;
                    }
                }
            }
        }
        foreach ($skuProducts as $product) {
            $this->skuProducts[mb_strtoupper(trim($product->sku()))][] = $product;
        }
        foreach ($skuProductOwners as $localProductId => $ownerExternalId) {
            $this->productOwners[$localProductId] = $ownerExternalId;
        }
        foreach ($imageMappings as $mapping) {
            $this->imageMappings[$mapping->externalId()] = $mapping;
        }
        foreach ($observations as $observation) {
            $this->observations[$observation->externalId()] = $observation;
        }
        foreach ($skuObservations as $observation) {
            $this->rememberObservedSkuOwner($observation);
        }
    }

    public function productMapping(string $externalId): ?ExternalResourceMapping
    {
        return $this->productMappings[$externalId] ?? null;
    }

    public function mappedProduct(string $externalId): ?Product
    {
        return $this->mappedProducts[$externalId] ?? null;
    }

    /**
     * Every local product that already carries the given canonical SKU. More than one match
     * means the SKU cannot identify a single provider product, so no adoption is possible.
     *
     * @return list<Product>
     */
    public function skuMatches(string $sku): array
    {
        return $this->skuProducts[mb_strtoupper(trim($sku))] ?? [];
    }

    /**
     * The provider external ID that already owns a local product, if any.
     */
    public function productOwnerExternalId(int $productId): ?string
    {
        return $this->productOwners[$productId] ?? null;
    }

    public function claimExternalId(string $externalId, ?string $fingerprint, ?int $position): bool
    {
        if (isset($this->claimedExternalIds[$externalId])) {
            $claim = $this->claimedExternalIds[$externalId];

            return null !== $position
                && $claim['position'] === $position
                && $claim['fingerprint'] === $fingerprint;
        }

        $observation = $this->observations[$externalId] ?? null;
        $this->claimedExternalIds[$externalId] = [
            'fingerprint' => $fingerprint,
            'position' => $position,
        ];
        if (null === $observation) {
            return true;
        }

        return null !== $position
            && $observation->streamPosition() === $position
            && $observation->identitySha256() === $fingerprint;
    }

    public function hasClaimedExternalId(string $externalId, ?string $fingerprint): bool
    {
        return isset($this->claimedExternalIds[$externalId])
            && $this->claimedExternalIds[$externalId]['fingerprint'] === $fingerprint;
    }

    public function observation(string $externalId): ?B2bSyncObservation
    {
        return $this->observations[$externalId] ?? null;
    }

    public function registerObservation(B2bSyncObservation $observation): void
    {
        $this->observations[$observation->externalId()] = $observation;
        $this->rememberObservedSkuOwner($observation);
    }

    public function claimSku(string $sku, string $externalId): bool
    {
        $sku = mb_strtoupper(trim($sku));
        if (isset($this->observedSkuOwners[$sku]) && $this->observedSkuOwners[$sku] !== $externalId) {
            return false;
        }
        if (!isset($this->skuOwners[$sku])) {
            $this->skuOwners[$sku] = $externalId;

            return true;
        }

        return $this->skuOwners[$sku] === $externalId;
    }

    private function rememberObservedSkuOwner(B2bSyncObservation $observation): void
    {
        $sku = $observation->sku();
        if (null === $sku) {
            return;
        }
        $sku = mb_strtoupper(trim($sku));
        if (!isset($this->observedSkuOwners[$sku])) {
            $this->observedSkuOwners[$sku] = $observation->externalId();

            return;
        }
        if ($this->observedSkuOwners[$sku] !== $observation->externalId()) {
            $this->observedSkuOwners[$sku] = '';
        }
    }

    public function registerProduct(string $externalId, ExternalResourceMapping $mapping, Product $product): void
    {
        $this->productMappings[$externalId] = $mapping;
        $this->mappedProducts[$externalId] = $product;
        $id = $product->id();
        if (null !== $id) {
            $this->productOwners[$id] = $externalId;
        }
        $sku = mb_strtoupper(trim($product->sku()));
        if (!in_array($product, $this->skuProducts[$sku] ?? [], true)) {
            $this->skuProducts[$sku][] = $product;
        }
        $this->claimSku($product->sku(), $externalId);
    }

    public function imageMapping(string $externalId): ?ExternalResourceMapping
    {
        return $this->imageMappings[$externalId] ?? null;
    }

    public function registerImageMapping(ExternalResourceMapping $mapping): void
    {
        $this->imageMappings[$mapping->externalId()] = $mapping;
    }

    public function forgetImageMapping(string $externalId): void
    {
        unset($this->imageMappings[$externalId]);
    }

    public function category(string $externalId): ?B2bResolvedCategory
    {
        return $this->categories[$externalId] ?? null;
    }

    public function rememberCategory(string $externalId, B2bResolvedCategory $category): void
    {
        $this->categories[$externalId] = $category;
    }

    public function hasBrand(string $externalId): bool
    {
        return isset($this->brands[$externalId]) || isset($this->missingBrands[$externalId]);
    }

    public function brand(string $externalId): ?B2bResolvedBrand
    {
        return $this->brands[$externalId] ?? null;
    }

    public function rememberBrand(string $externalId, ?B2bResolvedBrand $brand): void
    {
        if (null === $brand) {
            $this->missingBrands[$externalId] = true;

            return;
        }
        $this->brands[$externalId] = $brand;
    }
}
