<?php

namespace App\Entity\Commerce;

use App\Entity\Catalog\Product;
use App\Module\Inventory\Exception\InsufficientStock;
use App\Repository\Commerce\ProductInventoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductInventoryRepository::class)]
#[ORM\Table(name: 'commerce_product_inventory')]
#[ORM\UniqueConstraint(name: 'uniq_commerce_inventory_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_commerce_inventory_availability_quantity', columns: ['available_for_sale', 'quantity'])]
class ProductInventory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    // @phpstan-ignore property.onlyWritten, property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\OneToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(type: Types::INTEGER)]
    private int $quantity;

    #[ORM\Column(name: 'available_for_sale', type: Types::BOOLEAN)]
    private bool $availableForSale;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Product $product, mixed $quantity = 0, mixed $availableForSale = true)
    {
        $quantity = self::validQuantity($quantity);
        $availableForSale = self::validAvailability($availableForSale);

        $this->product = $product;
        $this->quantity = $quantity;
        $this->availableForSale = $availableForSale;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function product(): Product
    {
        return $this->product;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function availableForSale(): bool
    {
        return $this->availableForSale;
    }

    public function isSellable(): bool
    {
        return $this->availableForSale && $this->quantity > 0;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function replace(mixed $quantity, mixed $availableForSale): void
    {
        $quantity = self::validQuantity($quantity);
        $availableForSale = self::validAvailability($availableForSale);

        $this->quantity = $quantity;
        $this->availableForSale = $availableForSale;
        $this->touch();
    }

    public function adjust(mixed $delta): void
    {
        if (!is_int($delta)) {
            throw new \InvalidArgumentException('Inventory adjustment delta must be an integer.');
        }

        if ($delta > 0 && $this->quantity > PHP_INT_MAX - $delta) {
            throw new \OverflowException('Inventory adjustment exceeds the integer range.');
        }

        if ($delta < -$this->quantity) {
            throw new InsufficientStock('Inventory adjustment would make quantity negative.');
        }

        $this->quantity += $delta;
        $this->touch();
    }

    private static function validQuantity(mixed $quantity): int
    {
        if (!is_int($quantity) || $quantity < 0) {
            throw new \InvalidArgumentException('Inventory quantity must be a non-negative integer.');
        }

        return $quantity;
    }

    private static function validAvailability(mixed $availableForSale): bool
    {
        if (!is_bool($availableForSale)) {
            throw new \InvalidArgumentException('Inventory availability must be a boolean.');
        }

        return $availableForSale;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
