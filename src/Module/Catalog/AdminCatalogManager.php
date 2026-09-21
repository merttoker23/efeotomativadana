<?php

declare(strict_types=1);

namespace App\Module\Catalog;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Module\Admin\ConcurrentAdminEdit;
use App\Module\Catalog\Exception\CatalogConflict;
use App\Module\Inventory\InventoryManager;
use App\Module\Inventory\ProductInventoryRepositoryInterface;
use App\Module\Pricing\PricingManager;
use App\Module\Pricing\ProductPriceRepositoryInterface;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminCatalogManager
{
    public function __construct(
        private CatalogManager $catalog,
        private ProductRepositoryInterface $products,
        private CategoryRepositoryInterface $categories,
        private BrandRepositoryInterface $brands,
        private ProductPriceRepositoryInterface $prices,
        private ProductInventoryRepositoryInterface $inventory,
        private PricingManager $pricing,
        private InventoryManager $inventoryManager,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function saveProduct(?Product $product, AdminProductData $data, ?int $expectedInventoryVersion = null): Product
    {
        $basePrice = Money::ofMinor($data->baseMinorAmount, $data->currency);
        $salePrice = null === $data->saleMinorAmount ? null : Money::ofMinor($data->saleMinorAmount, $data->currency);
        $taxCategory = TaxCategory::of($data->taxCategory);
        $taxRate = TaxRate::fromBasisPoints($data->taxRateBasisPoints);
        $identifiers = $this->identifierRows($data);
        $images = $this->imageRows($data->imagePaths);

        return $this->entityManager->wrapInTransaction(function () use ($product, $data, $expectedInventoryVersion, $basePrice, $salePrice, $taxCategory, $taxRate, $identifiers, $images): Product {
            if (null === $product) {
                $product = $this->catalog->createProduct($data->sku, $data->name, $data->slug, CatalogSource::Local, $data->brand);
            } else {
                $this->entityManager->refresh($product, LockMode::PESSIMISTIC_WRITE);
                $this->prices->findOneByProductForUpdate($product);
                $currentInventory = $this->inventory->findOneByProductForUpdate($product);
                if (null !== $currentInventory && $currentInventory->version() !== $expectedInventoryVersion) {
                    throw new ConcurrentAdminEdit('Stock changed while this form was open. Reload and try again.');
                }

                $this->assertProductIdentityAvailable($product, $data->sku, $data->slug);
                $this->assertPublishedSlugStable($product->publicationStatus(), $product->slug(), $data->slug, 'product');
                $product->changeSku($data->sku);
                $product->rename($data->name);
                $product->changeSlug($data->slug);
                $product->changeBrand($data->brand);
            }
            $product->describe($data->description);
            $data->published ? $product->publish() : $product->unpublish();
            foreach ($product->categories() as $category) {
                $product->removeCategory($category);
            }
            foreach ($data->categories as $category) {
                $product->addCategory($category);
            }
            $this->reconcileIdentifiers($product, $identifiers);
            foreach ($product->images() as $image) {
                $product->removeImage($image);
            }
            foreach ($images as $position => [$path, $alt]) {
                $product->addImage($path, $alt, $position);
            }

            $this->catalog->saveProduct($product);
            $this->pricing->upsert($product, $basePrice, $taxCategory, $taxRate, $salePrice, $data->saleStartsAt, $data->saleEndsAt);
            $this->inventoryManager->upsert($product, $data->quantity, $data->availableForSale);

            return $product;
        });
    }

    public function saveCategory(?Category $category, AdminCatalogData $data): Category
    {
        if (null === $category) {
            $category = $this->catalog->createCategory($data->name, $data->slug, CatalogSource::Local, $data->parent);
        } else {
            $this->assertPublishedSlugStable($category->publicationStatus(), $category->slug(), $data->slug, 'category');
            $existing = $this->categories->findOneBySlug($data->slug);
            if (null !== $existing && $existing !== $category) {
                throw new CatalogConflict(sprintf('A category with slug "%s" already exists.', $data->slug));
            }
            $category->rename($data->name);
            $category->changeSlug($data->slug);
            $category->changeParent($data->parent);
        }
        $data->published ? $category->publish() : $category->unpublish();
        $this->categories->save($category);
        $this->entityManager->flush();
        return $category;
    }

    public function saveBrand(?Brand $brand, AdminCatalogData $data): Brand
    {
        if (null === $brand) {
            $brand = $this->catalog->createBrand($data->name, $data->slug);
        } else {
            $this->assertPublishedSlugStable($brand->publicationStatus(), $brand->slug(), $data->slug, 'brand');
            $existing = $this->brands->findOneBySlug($data->slug);
            if (null !== $existing && $existing !== $brand) {
                throw new CatalogConflict(sprintf('A brand with slug "%s" already exists.', $data->slug));
            }
            $brand->rename($data->name);
            $brand->changeSlug($data->slug);
        }
        $data->published ? $brand->publish() : $brand->unpublish();
        $this->brands->save($brand);
        $this->entityManager->flush();
        return $brand;
    }

    public function delete(object $entity): void
    {
        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }

    private function assertProductIdentityAvailable(Product $product, string $sku, string $slug): void
    {
        $bySku = $this->products->findOneBySku($sku);
        if (null !== $bySku && $bySku !== $product) {
            throw new CatalogConflict(sprintf('A product with SKU "%s" already exists.', $sku));
        }
        $bySlug = $this->products->findOneBySlug($slug);
        if (null !== $bySlug && $bySlug !== $product) {
            throw new CatalogConflict(sprintf('A product with slug "%s" already exists.', $slug));
        }
    }

    private function assertPublishedSlugStable(PublicationStatus $status, string $currentSlug, string $requestedSlug, string $resource): void
    {
        if (PublicationStatus::Published === $status && $currentSlug !== mb_strtolower(trim($requestedSlug))) {
            throw new CatalogConflict(sprintf('The published %s slug cannot be changed because its public URL must remain stable.', $resource));
        }
    }

    /** @param list<array{ProductIdentifierType, string}> $desiredRows */
    private function reconcileIdentifiers(Product $product, array $desiredRows): void
    {
        $existingByKey = [];
        foreach ($product->identifiers() as $identifier) {
            $existingByKey[$this->identifierKey($identifier->type(), $identifier->code())] = $identifier;
        }

        $desiredByKey = [];
        foreach ($desiredRows as [$type, $code]) {
            $key = $this->identifierKey($type, $code);
            if (isset($desiredByKey[$key])) {
                throw new \DomainException(sprintf('Identifier "%s" was submitted more than once for this product.', $code));
            }
            $desiredByKey[$key] = [$type, $code];
        }

        foreach ($existingByKey as $key => $identifier) {
            if (!isset($desiredByKey[$key])) {
                $product->removeIdentifier($identifier);
            }
        }
        foreach ($desiredByKey as $key => [$type, $code]) {
            if (!isset($existingByKey[$key])) {
                $product->addIdentifier($type, $code);
            }
        }
    }

    private function identifierKey(ProductIdentifierType $type, string $code): string
    {
        return $type->value."\0".mb_strtoupper(trim($code));
    }

    /** @return list<array{ProductIdentifierType, string}> */
    private function identifierRows(AdminProductData $data): array
    {
        $rows = [];
        if (null !== $data->manufacturerCode && '' !== trim($data->manufacturerCode)) {
            $rows[] = [ProductIdentifierType::Manufacturer, trim($data->manufacturerCode)];
        }
        foreach ([[ProductIdentifierType::Oem, $data->oemCodes], [ProductIdentifierType::Reference, $data->referenceCodes]] as [$type, $value]) {
            foreach ($this->lines($value) as $code) {
                $rows[] = [$type, $code];
            }
        }
        return $rows;
    }

    /** @return list<array{string, ?string}> */
    private function imageRows(?string $value): array
    {
        $images = [];
        foreach ($this->lines($value) as $line) {
            [$path, $alt] = array_pad(array_map('trim', explode('|', $line, 2)), 2, null);
            $images[] = [$path, '' === $alt ? null : $alt];
        }
        return $images;
    }

    /** @return list<string> */
    private function lines(?string $value): array
    {
        if (null === $value) {
            return [];
        }
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: []), static fn (string $line): bool => '' !== $line));
    }
}
