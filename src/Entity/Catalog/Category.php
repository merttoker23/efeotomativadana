<?php

namespace App\Entity\Catalog;

use App\Module\Catalog\CatalogSource;
use App\Module\Catalog\PublicationStatus;
use App\Repository\Catalog\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Table(name: 'catalog_category')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_category_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_catalog_category_publication', columns: ['publication_status'])]
class Category
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

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    private Collection $children;

    /** @var Collection<int, Product> */
    #[ORM\ManyToMany(targetEntity: Product::class, mappedBy: 'categories')]
    private Collection $products;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $slug, CatalogSource $source = CatalogSource::Local)
    {
        $this->name = self::required($name, 'Category name', 255);
        $this->slug = self::normalizeSlug($slug);
        $this->source = $source;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->children = new ArrayCollection();
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
        $this->name = self::required($name, 'Category name', 255);
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

    public function parent(): ?self
    {
        return $this->parent;
    }

    /** @return list<self> */
    public function children(): array
    {
        return array_values($this->children->toArray());
    }

    public function changeParent(?self $parent): void
    {
        if ($parent === $this) {
            throw new \DomainException('A category cannot be its own parent.');
        }

        for ($ancestor = $parent; null !== $ancestor; $ancestor = $ancestor->parent) {
            if ($ancestor === $this) {
                throw new \DomainException('A category cannot use one of its descendants as parent.');
            }
        }

        if ($this->parent === $parent) {
            return;
        }

        $this->parent?->children->removeElement($this);
        $this->parent = $parent;
        if (null !== $parent && !$parent->children->contains($this)) {
            $parent->children->add($this);
        }
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
        $slug = mb_strtolower(self::required($slug, 'Category slug', 255));
        if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new \InvalidArgumentException('Category slug must contain only lowercase ASCII letters, numbers and hyphens.');
        }

        return $slug;
    }
}
