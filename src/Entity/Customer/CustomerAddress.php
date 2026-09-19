<?php

namespace App\Entity\Customer;

use App\Repository\Customer\CustomerAddressRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CustomerAddressRepository::class)]
#[ORM\Table(name: 'customer_address')]
#[ORM\Index(name: 'idx_customer_address_owner', columns: ['customer_id'])]
class CustomerAddress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CustomerUser::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CustomerUser $customer;

    #[ORM\Column(length: 80)]
    private string $label;

    #[ORM\Column(length: 120)]
    private string $recipientName;

    #[ORM\Column(length: 30)]
    private string $phone;

    #[ORM\Column(length: 255)]
    private string $addressLine1;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $addressLine2 = null;

    #[ORM\Column(length: 100)]
    private string $district;

    #[ORM\Column(length: 100)]
    private string $city;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(length: 2, options: ['default' => 'TR'])]
    private string $countryCode = 'TR';

    #[ORM\Column(options: ['default' => false])]
    private bool $defaultAddress = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(CustomerUser $customer)
    {
        $this->customer = $customer;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): ?int { return $this->id; }
    public function customer(): CustomerUser { return $this->customer; }
    public function label(): string { return $this->label; }
    public function recipientName(): string { return $this->recipientName; }
    public function phone(): string { return $this->phone; }
    public function addressLine1(): string { return $this->addressLine1; }
    public function addressLine2(): ?string { return $this->addressLine2; }
    public function district(): string { return $this->district; }
    public function city(): string { return $this->city; }
    public function postalCode(): ?string { return $this->postalCode; }
    public function countryCode(): string { return $this->countryCode; }
    public function isDefault(): bool { return $this->defaultAddress; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function update(
        string $label,
        string $recipientName,
        string $phone,
        string $addressLine1,
        ?string $addressLine2,
        string $district,
        string $city,
        ?string $postalCode,
        bool $defaultAddress,
    ): void {
        foreach ([$label, $recipientName, $phone, $addressLine1, $district, $city] as $required) {
            if ('' === trim($required)) {
                throw new \InvalidArgumentException('Required address fields cannot be empty.');
            }
        }

        $this->label = trim($label);
        $this->recipientName = trim($recipientName);
        $this->phone = trim($phone);
        $this->addressLine1 = trim($addressLine1);
        $this->addressLine2 = null === $addressLine2 || '' === trim($addressLine2) ? null : trim($addressLine2);
        $this->district = trim($district);
        $this->city = trim($city);
        $this->postalCode = null === $postalCode || '' === trim($postalCode) ? null : trim($postalCode);
        $this->defaultAddress = $defaultAddress;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function unsetDefault(): void
    {
        $this->defaultAddress = false;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
