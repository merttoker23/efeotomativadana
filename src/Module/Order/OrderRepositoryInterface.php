<?php

declare(strict_types=1);

namespace App\Module\Order;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Customer\CustomerUser;

interface OrderRepositoryInterface
{
    public function save(CustomerOrder $order): void;

    public function findOneByNumberForCustomer(string $orderNumber, CustomerUser $customer): ?CustomerOrder;

    public function findOneByNumberForUpdate(string $orderNumber): ?CustomerOrder;
}
