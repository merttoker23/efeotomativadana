<?php

namespace App\Module\Cart;

use App\Entity\Customer\CustomerUser;
use App\Module\Catalog\PublicationStatus;
use App\Module\Inventory\InventoryQuery;
use App\Module\Pricing\PricingQuery;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CartMerger
{
    public function __construct(
        private CartRepositoryInterface $carts,
        private CartOwnerResolver $owner,
        private InventoryQuery $inventory,
        private PricingQuery $pricing,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function mergeGuestInto(CustomerUser $customer): bool
    {
        $token = $this->owner->guestToken();
        if (null === $token) {
            return false;
        }

        $guestCart = $this->carts->findOneByGuestToken($token);
        if (null === $guestCart) {
            $this->owner->forgetGuestToken();

            return false;
        }

        $customerCart = $this->carts->findOneByCustomer($customer);
        $adjusted = false;
        if (null === $customerCart) {
            $guestCart->claimBy($customer);
            foreach ($guestCart->items() as $item) {
                $maximum = $this->maximumSellableQuantity($item->product());
                if ($maximum < 1) {
                    $guestCart->remove($item);
                    $adjusted = true;
                } elseif ($item->quantity() > $maximum) {
                    $guestCart->changeQuantity($item, $maximum);
                    $adjusted = true;
                }
            }
        } else {
            foreach ($guestCart->items() as $guestItem) {
                $product = $guestItem->product();
                $maximum = $this->maximumSellableQuantity($product);
                $customerItem = $customerCart->itemFor($product);
                $existing = $customerItem?->quantity() ?? 0;
                $requested = $existing + $guestItem->quantity();
                $merged = min($requested, $maximum);
                if ($merged < $requested) {
                    $adjusted = true;
                }
                if (null === $customerItem) {
                    if ($merged > 0) {
                        $customerCart->add($product, $merged);
                    }
                } elseif ($merged < 1) {
                    $customerCart->remove($customerItem);
                } elseif ($merged !== $existing) {
                    $customerCart->changeQuantity($customerItem, $merged);
                }
            }

            $this->carts->remove($guestCart);
        }

        $this->owner->forgetGuestToken();
        $this->entityManager->flush();

        return $adjusted;
    }

    private function maximumSellableQuantity(\App\Entity\Catalog\Product $product): int
    {
        $inventory = $this->inventory->forProduct($product);
        if (
            PublicationStatus::Published !== $product->publicationStatus()
            || null === $this->pricing->forProduct($product)
            || !$inventory->sellable()
        ) {
            return 0;
        }

        return min(99, $inventory->quantity());
    }
}
