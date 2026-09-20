<?php

namespace App\Module\Cart;

use App\Entity\Commerce\Cart;
use App\Entity\Customer\CustomerUser;

interface CartRepositoryInterface
{
    public function findOneByCustomer(CustomerUser $customer): ?Cart;

    public function findOneByCustomerForUpdate(CustomerUser $customer): ?Cart;

    public function findOneByGuestToken(string $token): ?Cart;

    public function summary(Cart $cart, \DateTimeImmutable $now): CartSummary;

    public function save(Cart $cart): void;

    public function remove(Cart $cart): void;
}
