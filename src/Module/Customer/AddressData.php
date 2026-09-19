<?php

namespace App\Module\Customer;

use App\Entity\Customer\CustomerAddress;
use Symfony\Component\Validator\Constraints as Assert;

final class AddressData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    public string $label = '';
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public string $recipientName = '';
    #[Assert\NotBlank]
    #[Assert\Length(max: 30)]
    public string $phone = '';
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $addressLine1 = '';
    #[Assert\Length(max: 255)]
    public ?string $addressLine2 = null;
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $district = '';
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $city = '';
    #[Assert\Length(max: 20)]
    public ?string $postalCode = null;
    public bool $defaultAddress = false;

    public static function fromAddress(CustomerAddress $address): self
    {
        $data = new self();
        $data->label = $address->label();
        $data->recipientName = $address->recipientName();
        $data->phone = $address->phone();
        $data->addressLine1 = $address->addressLine1();
        $data->addressLine2 = $address->addressLine2();
        $data->district = $address->district();
        $data->city = $address->city();
        $data->postalCode = $address->postalCode();
        $data->defaultAddress = $address->isDefault();

        return $data;
    }
}
