<?php

namespace App\Entity\Catalog;

use App\Module\Catalog\CatalogSource;
use App\Module\Catalog\ProductIdentifierType;
use App\Module\Catalog\PublicationStatus;
use App\Repository\Catalog\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'catalog_product')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_product_sku', columns: ['sku'])]
#[ORM\UniqueConstraint(name: 'uniq_catalog_product_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_catalog_product_publication', columns: ['publication_status'])]
#[ORM\Index(name: 'idx_catalog_product_publication_name', columns: ['publication_status', 'name'])]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $sku;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20, enumType: CatalogSource::class)]
    private CatalogSource $source;

    #[ORM\Column(length: 20, enumType: PublicationStatus::class)]
    private PublicationStatus $publicationStatus = PublicationStatus::Draft;

    #[ORM\ManyToOne(targetEntity: Brand::class, inversedBy: 'products')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Brand $brand;

    /** @var Collection<int, Category> */
    #[ORM\ManyToMany(targetEntity: Category::class, inversedBy: 'products')]
    #[ORM\JoinTable(name: 'catalog_product_category')]
    private Collection $categories;

    /** @var Collection<int, ProductImage> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductImage::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $images;

    /** @var Collection<int, ProductIdentifier> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductIdentifier::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['type' => 'ASC', 'code' => 'ASC'])]
    private Collection $identifiers;

    /** @var Collection<int, ProductAttribute> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductAttribute::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['key' => 'ASC'])]
    private Collection $attributes;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $sku,
        string $name,
        string $slug,
        CatalogSource $source = CatalogSource::Local,
        ?Brand $brand = null,
    ) {
        $this->sku = self::code($sku, 'SKU', 64);
        $this->name = self::required($name, 'Product name', 255);
        $this->slug = self::normalizeSlug($slug);
        $this->source = $source;
        $this->brand = $brand;
        $this->categories = new ArrayCollection();
        $this->images = new ArrayCollection();
        $this->identifiers = new ArrayCollection();
        $this->attributes = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function changeSku(string $sku): void
    {
        $this->sku = self::code($sku, 'SKU', 64);
        $this->touch();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = self::required($name, 'Product name', 255);
        $this->touch();
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function changeSlug(string $slug): void
    {
        $this->slug = self::normalizeSlug($slug);
        $this->touch();
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function describe(?string $description): void
    {
        $description = null === $description ? null : trim($description);
        $this->description = '' === $description ? null : $description;
        $this->touch();
    }

    public function source(): CatalogSource
    {
        return $this->source;
    }

    public function publicationStatus(): PublicationStatus
    {
        return $this->publicationStatus;
    }

    public function publish(): void
    {
        $this->publicationStatus = PublicationStatus::Published;
        $this->touch();
    }

    public function unpublish(): void
    {
        $this->publicationStatus = PublicationStatus::Draft;
        $this->touch();
    }

    public function brand(): ?Brand
    {
        return $this->brand;
    }

    public function changeBrand(?Brand $brand): void
    {
        $this->brand = $brand;
        $this->touch();
    }

    /** @return list<Category> */
    public function categories(): array
    {
        return array_values($this->categories->toArray());
    }

    public function addCategory(Category $category): void
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
            $this->touch();
        }
    }

    public function removeCategory(Category $category): void
    {
        if ($this->categories->removeElement($category)) {
            $this->touch();
        }
    }

    /** @return list<ProductImage> */
    public function images(): array
    {
        return array_values($this->images->toArray());
    }

    public function addImage(string $path, ?string $altText = null, int $sortOrder = 0): ProductImage
    {
        $image = new ProductImage($this, $path, $altText, $sortOrder);
        $this->images->add($image);
        $this->touch();

        return $image;
    }

    public function removeImage(ProductImage $image): void
    {
        if ($this->images->removeElement($image)) {
            $this->touch();
        }
    }

    /** @return list<ProductIdentifier> */
    public function identifiers(): array
    {
        return array_values($this->identifiers->toArray());
    }

    public function addIdentifier(ProductIdentifierType $type, string $code): ProductIdentifier
    {
        $code = self::code($code, 'Product identifier', 120);
        foreach ($this->identifiers as $identifier) {
            if ($identifier->type() === $type && $identifier->code() === $code) {
                throw new \DomainException(sprintf('Identifier "%s" already exists for this product.', $code));
            }
        }

        $identifier = new ProductIdentifier($this, $type, $code);
        $this->identifiers->add($identifier);
        $this->touch();

        return $identifier;
    }

    public function removeIdentifier(ProductIdentifier $identifier): void
    {
        if ($this->identifiers->removeElement($identifier)) {
            $this->touch();
        }
    }

    /** @return list<ProductAttribute> */
    public function attributes(): array
    {
        return array_values($this->attributes->toArray());
    }

    public function setAttribute(string $key, string $value): ProductAttribute
    {
        $key = self::key($key);
        foreach ($this->attributes as $attribute) {
            if ($attribute->key() === $key) {
                $attribute->changeValue($value);
                $this->touch();

                return $attribute;
            }
        }

        $attribute = new ProductAttribute($this, $key, $value);
        $this->attributes->add($attribute);
        $this->touch();

        return $attribute;
    }

    public function removeAttribute(ProductAttribute $attribute): void
    {
        if ($this->attributes->removeElement($attribute)) {
            $this->touch();
        }
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private static function required(string $value, string $field, int $maxLength): string
    {
        $value = trim($value);
        if ('' === $value || mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(sprintf('%s must contain between 1 and %d characters.', $field, $maxLength));
        }

        return $value;
    }

    private static function code(string $code, string $field, int $maxLength): string
    {
        return mb_strtoupper(self::required($code, $field, $maxLength));
    }

    private static function key(string $key): string
    {
        $key = mb_strtolower(self::required($key, 'Attribute key', 100));
        if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key)) {
            throw new \InvalidArgumentException('Attribute key must contain only lowercase ASCII letters, numbers and hyphens.');
        }

        return $key;
    }

    private static function normalizeSlug(string $slug): string
    {
        $slug = mb_strtolower(self::required($slug, 'Product slug', 255));
        if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new \InvalidArgumentException('Product slug must contain only lowercase ASCII letters, numbers and hyphens.');
        }

        return $slug;
    }
}
