<?php

namespace App\Entity\Catalog;

use App\Module\Catalog\CatalogSource;
use App\Module\Catalog\PublicationStatus;
use App\Repository\Catalog\BrandRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BrandRepository::class)]
#[ORM\Table(name: 'catalog_brand')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_brand_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_catalog_brand_publication', columns: ['publication_status'])]
class Brand
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $slug;

    #[ORM\Column(length: 20, enumType: CatalogSource::class)]
    private CatalogSource $source;

    #[ORM\Column(length: 20, enumType: PublicationStatus::class)]
    private PublicationStatus $publicationStatus = PublicationStatus::Draft;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, Product> */
    #[ORM\OneToMany(mappedBy: 'brand', targetEntity: Product::class)]
    private Collection $products;

    public function __construct(string $name, string $slug, CatalogSource $source = CatalogSource::Local)
    {
        $this->name = self::required($name, 'Brand name', 255);
        $this->slug = self::normalizeSlug($slug);
        $this->source = $source;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->products = new ArrayCollection();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = self::required($name, 'Brand name', 255);
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

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return list<Product> */
    public function products(): array
    {
        return array_values($this->products->toArray());
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

    private static function normalizeSlug(string $slug): string
    {
        $slug = mb_strtolower(self::required($slug, 'Brand slug', 255));
        if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new \InvalidArgumentException('Brand slug must contain only lowercase ASCII letters, numbers and hyphens.');
        }

        return $slug;
    }
}
