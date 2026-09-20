<?php

namespace App\Entity\Commerce;

use App\Entity\Catalog\Product;
use App\Entity\Customer\CustomerUser;
use App\Repository\Commerce\WishlistItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WishlistItemRepository::class)]
#[ORM\Table(name: 'commerce_wishlist_item')]
#[ORM\UniqueConstraint(name: 'uniq_commerce_wishlist_owner_product', columns: ['customer_id', 'product_id'])]
class WishlistItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CustomerUser::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CustomerUser $customer;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(CustomerUser $customer, Product $product)
    {
        $this->customer = $customer;
        $this->product = $product;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function customer(): CustomerUser
    {
        return $this->customer;
    }

    public function product(): Product
    {
        return $this->product;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
