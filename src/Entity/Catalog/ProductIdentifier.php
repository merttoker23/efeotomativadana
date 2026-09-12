<?php

namespace App\Entity\Catalog;

use App\Module\Catalog\ProductIdentifierType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'catalog_product_identifier')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_product_identifier', columns: ['product_id', 'identifier_type', 'code'])]
#[ORM\Index(name: 'idx_catalog_identifier_lookup', columns: ['identifier_type', 'code'])]
class ProductIdentifier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'identifiers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(name: 'identifier_type', length: 20, enumType: ProductIdentifierType::class)]
    private ProductIdentifierType $type;

    #[ORM\Column(length: 120)]
    private string $code;

    public function __construct(Product $product, ProductIdentifierType $type, string $code)
    {
        $this->product = $product;
        $this->type = $type;
        $this->code = $code;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function product(): Product
    {
        return $this->product;
    }

    public function type(): ProductIdentifierType
    {
        return $this->type;
    }

    public function code(): string
    {
        return $this->code;
    }
}
