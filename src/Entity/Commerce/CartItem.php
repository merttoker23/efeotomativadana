<?php

namespace App\Entity\Commerce;

use App\Entity\Catalog\Product;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'commerce_cart_item')]
#[ORM\UniqueConstraint(name: 'uniq_commerce_cart_item_product', columns: ['cart_id', 'product_id'])]
class CartItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Cart::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Cart $cart;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column]
    private int $quantity;

    public function __construct(Cart $cart, Product $product, int $quantity)
    {
        $this->cart = $cart;
        $this->product = $product;
        $this->changeQuantity($quantity);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function cart(): Cart
    {
        return $this->cart;
    }

    public function product(): Product
    {
        return $this->product;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function changeQuantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > 99) {
            throw new \InvalidArgumentException('Cart quantity must be between 1 and 99.');
        }

        $this->quantity = $quantity;
    }
}
