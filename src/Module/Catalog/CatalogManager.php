<?php

namespace App\Module\Catalog;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Module\Catalog\Exception\CatalogConflict;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

final readonly class CatalogManager
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private CategoryRepositoryInterface $categories,
        private BrandRepositoryInterface $brands,
        private EntityManagerInterface $entityManager,
        private SluggerInterface $slugger,
    ) {
    }

    public function createProduct(
        string $sku,
        string $name,
        ?string $slug = null,
        CatalogSource $source = CatalogSource::Local,
        ?Brand $brand = null,
    ): Product {
        $product = new Product($sku, $name, $this->slug($name, $slug), $source, $brand);
        if (null !== $this->products->findOneBySku($product->sku())) {
            throw new CatalogConflict(sprintf('A product with SKU "%s" already exists.', $product->sku()));
        }
        if (null !== $this->products->findOneBySlug($product->slug())) {
            throw new CatalogConflict(sprintf('A product with slug "%s" already exists.', $product->slug()));
        }

        $this->products->save($product);
        $this->entityManager->flush();

        return $product;
    }

    public function updateProduct(Product $product, string $name, ?string $description): void
    {
        $product->rename($name);
        $product->describe($description);
        $this->saveProduct($product);
    }

    public function saveProduct(Product $product): void
    {
        $this->products->save($product);
        $this->entityManager->flush();
    }

    public function publishProduct(Product $product): void
    {
        $product->publish();
        $this->saveProduct($product);
    }

    public function unpublishProduct(Product $product): void
    {
        $product->unpublish();
        $this->saveProduct($product);
    }

    public function createCategory(
        string $name,
        ?string $slug = null,
        CatalogSource $source = CatalogSource::Local,
        ?Category $parent = null,
    ): Category {
        $category = new Category($name, $this->slug($name, $slug), $source);
        if (null !== $this->categories->findOneBySlug($category->slug())) {
            throw new CatalogConflict(sprintf('A category with slug "%s" already exists.', $category->slug()));
        }
        $category->changeParent($parent);

        $this->categories->save($category);
        $this->entityManager->flush();

        return $category;
    }

    public function updateCategory(Category $category, string $name): void
    {
        $category->rename($name);
        $this->categories->save($category);
        $this->entityManager->flush();
    }

    public function publishCategory(Category $category): void
    {
        $category->publish();
        $this->categories->save($category);
        $this->entityManager->flush();
    }

    public function unpublishCategory(Category $category): void
    {
        $category->unpublish();
        $this->categories->save($category);
        $this->entityManager->flush();
    }

    public function createBrand(
        string $name,
        ?string $slug = null,
        CatalogSource $source = CatalogSource::Local,
    ): Brand {
        $brand = new Brand($name, $this->slug($name, $slug), $source);
        if (null !== $this->brands->findOneBySlug($brand->slug())) {
            throw new CatalogConflict(sprintf('A brand with slug "%s" already exists.', $brand->slug()));
        }

        $this->brands->save($brand);
        $this->entityManager->flush();

        return $brand;
    }

    public function updateBrand(Brand $brand, string $name): void
    {
        $brand->rename($name);
        $this->brands->save($brand);
        $this->entityManager->flush();
    }

    public function publishBrand(Brand $brand): void
    {
        $brand->publish();
        $this->brands->save($brand);
        $this->entityManager->flush();
    }

    public function unpublishBrand(Brand $brand): void
    {
        $brand->unpublish();
        $this->brands->save($brand);
        $this->entityManager->flush();
    }

    private function slug(string $name, ?string $slug): string
    {
        return $this->slugger->slug(null === $slug ? $name : $slug)->lower()->toString();
    }
}
