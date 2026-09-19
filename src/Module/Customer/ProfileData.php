<?php

namespace App\Module\Customer;

use App\Entity\Customer\CustomerUser;
use Symfony\Component\Validator\Constraints as Assert;

final class ProfileData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $firstName;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $lastName;

    #[Assert\Length(max: 30)]
    public ?string $phone;

    public function __construct(CustomerUser $customer)
    {
        $this->firstName = $customer->firstName();
        $this->lastName = $customer->lastName();
        $this->phone = $customer->phone();
    }
}
