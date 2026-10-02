<?php

namespace App\Module\Cart;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\WishlistItem;
use App\Entity\Customer\CustomerUser;

interface WishlistRepositoryInterface
{
    public function findOne(CustomerUser $customer, Product $product): ?WishlistItem;

    public function findOwnedById(CustomerUser $customer, int $id): ?WishlistItem;

    /** @return list<WishlistItem> */
    public function findForCustomer(CustomerUser $customer, int $page = 1, int $perPage = 25): array;

    public function countForCustomer(CustomerUser $customer): int;

    public function save(WishlistItem $item): void;

    public function remove(WishlistItem $item): void;
}
