<?php

namespace App\Entity\Catalog;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'catalog_product_image')]
#[ORM\Index(name: 'idx_catalog_product_image_sort', columns: ['product_id', 'sort_order'])]
class ProductImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'images')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(length: 500)]
    private string $path;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $altText;

    #[ORM\Column]
    private int $sortOrder;

    public function __construct(Product $product, string $path, ?string $altText = null, int $sortOrder = 0)
    {
        $path = trim($path);
        if ('' === $path || mb_strlen($path) > 500) {
            throw new \InvalidArgumentException('Product image path must contain between 1 and 500 characters.');
        }
        if ($sortOrder < 0) {
            throw new \InvalidArgumentException('Product image sort order cannot be negative.');
        }

        $altText = null === $altText ? null : trim($altText);
        if (null !== $altText && mb_strlen($altText) > 255) {
            throw new \InvalidArgumentException('Product image alt text cannot exceed 255 characters.');
        }

        $this->product = $product;
        $this->path = $path;
        $this->altText = '' === $altText ? null : $altText;
        $this->sortOrder = $sortOrder;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function product(): Product
    {
        return $this->product;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function altText(): ?string
    {
        return $this->altText;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }
}
