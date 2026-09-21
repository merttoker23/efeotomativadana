<?php

declare(strict_types=1);

namespace App\Module\Customer;

use App\Entity\Customer\CustomerUser;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminCustomerManager
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function setActive(CustomerUser $customer, bool $active): void
    {
        $active ? $customer->activate() : $customer->deactivate();
        $this->entityManager->flush();
    }
}
