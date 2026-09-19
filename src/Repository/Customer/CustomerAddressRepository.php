<?php

namespace App\Repository\Customer;

use App\Entity\Customer\CustomerAddress;
use App\Entity\Customer\CustomerUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CustomerAddress> */
final class CustomerAddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerAddress::class);
    }

    /** @return list<CustomerAddress> */
    public function findForCustomer(CustomerUser $customer): array
    {
        return $this->findBy(['customer' => $customer], ['defaultAddress' => 'DESC', 'id' => 'ASC']);
    }

    public function clearDefaultFor(CustomerUser $customer, ?CustomerAddress $except = null): void
    {
        foreach ($this->findForCustomer($customer) as $address) {
            if ($address !== $except && $address->isDefault()) {
                $address->unsetDefault();
            }
        }
    }
}
