<?php

namespace App\Entity\Commerce;

use App\Entity\Catalog\Product;
use App\Entity\Customer\CustomerUser;
use App\Repository\Commerce\CartRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CartRepository::class)]
#[ORM\Table(name: 'commerce_cart')]
#[ORM\UniqueConstraint(name: 'uniq_commerce_cart_customer', columns: ['customer_id'])]
#[ORM\UniqueConstraint(name: 'uniq_commerce_cart_guest_token', columns: ['guest_token'])]
class Cart
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CustomerUser::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?CustomerUser $customer;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $guestToken;

    /** @var Collection<int, CartItem> */
    #[ORM\OneToMany(mappedBy: 'cart', targetEntity: CartItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $items;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(?CustomerUser $customer = null, ?string $guestToken = null)
    {
        if ((null === $customer) === (null === $guestToken)) {
            throw new \InvalidArgumentException('A cart must belong to exactly one customer or guest token.');
        }

        if (null !== $guestToken && 64 !== strlen($guestToken)) {
            throw new \InvalidArgumentException('Guest cart token must contain 64 characters.');
        }

        $this->customer = $customer;
        $this->guestToken = $guestToken;
        $this->items = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function customer(): ?CustomerUser
    {
        return $this->customer;
    }

    public function guestToken(): ?string
    {
        return $this->guestToken;
    }

    /** @return list<CartItem> */
    public function items(): array
    {
        return array_values($this->items->toArray());
    }

    public function itemFor(Product $product): ?CartItem
    {
        foreach ($this->items as $item) {
            if ($item->product() === $product || (null !== $product->id() && $item->product()->id() === $product->id())) {
                return $item;
            }
        }

        return null;
    }

    public function itemById(int $id): ?CartItem
    {
        foreach ($this->items as $item) {
            if ($item->id() === $id) {
                return $item;
            }
        }

        return null;
    }

    public function add(Product $product, int $quantity): CartItem
    {
        $item = $this->itemFor($product);
        if (null !== $item) {
            $item->changeQuantity($item->quantity() + $quantity);
            $this->touch();

            return $item;
        }

        $item = new CartItem($this, $product, $quantity);
        $this->items->add($item);
        $this->touch();

        return $item;
    }

    public function remove(CartItem $item): void
    {
        if ($this->items->removeElement($item)) {
            $this->touch();
        }
    }

    public function changeQuantity(CartItem $item, int $quantity): void
    {
        if (!$this->items->contains($item)) {
            throw new \DomainException('Cart item does not belong to this cart.');
        }

        $item->changeQuantity($quantity);
        $this->touch();
    }

    public function claimBy(CustomerUser $customer): void
    {
        if (null !== $this->customer) {
            throw new \DomainException('Only a guest cart can be claimed.');
        }

        $this->customer = $customer;
        $this->guestToken = null;
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

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
