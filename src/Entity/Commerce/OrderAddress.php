<?php

declare(strict_types=1);

namespace App\Entity\Commerce;

use App\Module\Order\OrderAddressRole;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'commerce_order_address')]
#[ORM\UniqueConstraint(name: 'uniq_commerce_order_address_role', columns: ['order_id', 'role'])]
class OrderAddress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CustomerOrder::class, inversedBy: 'addresses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    // @phpstan-ignore property.onlyWritten (Doctrine owns the child-to-parent association.)
    private CustomerOrder $order;

    #[ORM\Column(length: 20, enumType: OrderAddressRole::class)]
    private OrderAddressRole $role;

    #[ORM\Column(length: 120)]
    private string $recipientName;

    #[ORM\Column(length: 30)]
    private string $phone;

    #[ORM\Column(length: 255)]
    private string $addressLine1;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $addressLine2;

    #[ORM\Column(length: 100)]
    private string $district;

    #[ORM\Column(length: 100)]
    private string $city;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $postalCode;

    #[ORM\Column(length: 2)]
    private string $countryCode;

    public function __construct(CustomerOrder $order, OrderAddressRole $role, string $recipientName, string $phone, string $addressLine1, ?string $addressLine2, string $district, string $city, ?string $postalCode, string $countryCode)
    {
        foreach ([$recipientName, $phone, $addressLine1, $district, $city] as $required) {
            if ('' === trim($required)) {
                throw new \InvalidArgumentException('Required order address fields cannot be empty.');
            }
        }
        $countryCode = strtoupper(trim($countryCode));
        if (2 !== strlen($countryCode)) {
            throw new \InvalidArgumentException('Order address country code must contain two characters.');
        }

        $this->order = $order;
        $this->role = $role;
        $this->recipientName = trim($recipientName);
        $this->phone = trim($phone);
        $this->addressLine1 = trim($addressLine1);
        $this->addressLine2 = null === $addressLine2 || '' === trim($addressLine2) ? null : trim($addressLine2);
        $this->district = trim($district);
        $this->city = trim($city);
        $this->postalCode = null === $postalCode || '' === trim($postalCode) ? null : trim($postalCode);
        $this->countryCode = $countryCode;
    }

    public function id(): ?int { return $this->id; }
    public function role(): OrderAddressRole { return $this->role; }
    public function recipientName(): string { return $this->recipientName; }
    public function phone(): string { return $this->phone; }
    public function addressLine1(): string { return $this->addressLine1; }
    public function addressLine2(): ?string { return $this->addressLine2; }
    public function district(): string { return $this->district; }
    public function city(): string { return $this->city; }
    public function postalCode(): ?string { return $this->postalCode; }
    public function countryCode(): string { return $this->countryCode; }
}
