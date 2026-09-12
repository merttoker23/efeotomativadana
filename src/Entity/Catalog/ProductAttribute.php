<?php

namespace App\Entity\Catalog;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'catalog_product_attribute')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_product_attribute', columns: ['product_id', 'attribute_key'])]
#[ORM\Index(name: 'idx_catalog_attribute_lookup', columns: ['attribute_key', 'attribute_value'])]
class ProductAttribute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'attributes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(name: 'attribute_key', length: 100)]
    private string $key;

    #[ORM\Column(name: 'attribute_value', length: 500)]
    private string $value;

    public function __construct(Product $product, string $key, string $value)
    {
        $this->product = $product;
        $this->key = $key;
        $this->changeValue($value);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function product(): Product
    {
        return $this->product;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function changeValue(string $value): void
    {
        $value = trim($value);
        if ('' === $value || mb_strlen($value) > 500) {
            throw new \InvalidArgumentException('Product attribute value must contain between 1 and 500 characters.');
        }

        $this->value = $value;
    }
}
