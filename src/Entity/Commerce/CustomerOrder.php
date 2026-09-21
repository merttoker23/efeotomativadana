<?php

declare(strict_types=1);

namespace App\Entity\Commerce;

use App\Entity\Catalog\Product;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderState;
use App\Repository\Commerce\CustomerOrderRepository;
use App\Shared\Money\Money;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CustomerOrderRepository::class)]
#[ORM\Table(name: 'commerce_customer_order')]
#[ORM\UniqueConstraint(name: 'uniq_commerce_order_number', columns: ['order_number'])]
#[ORM\Index(name: 'idx_commerce_order_customer_created', columns: ['customer_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class CustomerOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(length: 32)]
    private string $orderNumber;

    #[ORM\ManyToOne(targetEntity: CustomerUser::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private CustomerUser $customer;

    #[ORM\Column(length: 180)]
    private string $customerEmail;

    #[ORM\Column(length: 255)]
    private string $customerName;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $customerPhone;

    #[ORM\Column(length: 20, enumType: OrderState::class)]
    private OrderState $state = OrderState::Placed;

    #[ORM\Column(type: Types::BIGINT)]
    private int $subtotalMinorAmount;

    #[ORM\Column(type: Types::BIGINT)]
    private int $taxMinorAmount;

    #[ORM\Column(type: Types::BIGINT)]
    private int $shippingMinorAmount;

    #[ORM\Column(type: Types::BIGINT)]
    private int $grandTotalMinorAmount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 50)]
    private string $shippingOptionKey;

    #[ORM\Column(length: 120)]
    private string $shippingOptionLabel;

    #[ORM\Column(length: 50)]
    private string $paymentOptionKey;

    #[ORM\Column(length: 160)]
    private string $paymentOptionLabel;

    /** @var Collection<int, OrderItem> */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderItem::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $items;

    /** @var Collection<int, OrderAddress> */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderAddress::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $addresses;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    private bool $snapshotsSealed = false;

    public function __construct(
        string $orderNumber,
        CustomerUser $customer,
        Money $subtotal,
        Money $tax,
        Money $shipping,
        Money $grandTotal,
        string $shippingOptionKey,
        string $shippingOptionLabel,
        string $paymentOptionKey,
        string $paymentOptionLabel,
        \DateTimeImmutable $placedAt,
    ) {
        if (1 !== preg_match('/^EOA-\d{8}-[0-9A-F]{12}$/', $orderNumber)) {
            throw new \InvalidArgumentException('Order number has an invalid format.');
        }

        $currency = $subtotal->currency();
        foreach ([$tax, $shipping, $grandTotal] as $money) {
            if ($money->currency() !== $currency) {
                throw new \InvalidArgumentException('Order totals must use one currency.');
            }
        }
        if (!$subtotal->add($shipping)->equals($grandTotal) || $tax->minorAmount() > $subtotal->minorAmount()) {
            throw new \InvalidArgumentException('Order totals are inconsistent.');
        }
        foreach ([$shippingOptionKey, $shippingOptionLabel, $paymentOptionKey, $paymentOptionLabel] as $required) {
            if ('' === trim($required)) {
                throw new \InvalidArgumentException('Order method snapshots cannot be empty.');
            }
        }

        $this->orderNumber = $orderNumber;
        $this->customer = $customer;
        $this->customerEmail = $customer->getUserIdentifier();
        $this->customerName = $customer->fullName();
        $this->customerPhone = $customer->phone();
        $this->subtotalMinorAmount = $subtotal->minorAmount();
        $this->taxMinorAmount = $tax->minorAmount();
        $this->shippingMinorAmount = $shipping->minorAmount();
        $this->grandTotalMinorAmount = $grandTotal->minorAmount();
        $this->currency = $currency;
        $this->shippingOptionKey = trim($shippingOptionKey);
        $this->shippingOptionLabel = trim($shippingOptionLabel);
        $this->paymentOptionKey = trim($paymentOptionKey);
        $this->paymentOptionLabel = trim($paymentOptionLabel);
        $this->items = new ArrayCollection();
        $this->addresses = new ArrayCollection();
        $this->createdAt = $this->updatedAt = $placedAt;
    }

    public function id(): ?int { return $this->id; }
    public function version(): int { return $this->version; }
    public function orderNumber(): string { return $this->orderNumber; }
    public function customer(): CustomerUser { return $this->customer; }
    public function customerEmail(): string { return $this->customerEmail; }
    public function customerName(): string { return $this->customerName; }
    public function customerPhone(): ?string { return $this->customerPhone; }
    public function state(): OrderState { return $this->state; }
    public function subtotal(): Money { return Money::ofMinor($this->subtotalMinorAmount, $this->currency); }
    public function taxTotal(): Money { return Money::ofMinor($this->taxMinorAmount, $this->currency); }
    public function shippingTotal(): Money { return Money::ofMinor($this->shippingMinorAmount, $this->currency); }
    public function grandTotal(): Money { return Money::ofMinor($this->grandTotalMinorAmount, $this->currency); }
    public function shippingOptionKey(): string { return $this->shippingOptionKey; }
    public function shippingOptionLabel(): string { return $this->shippingOptionLabel; }
    public function paymentOptionKey(): string { return $this->paymentOptionKey; }
    public function paymentOptionLabel(): string { return $this->paymentOptionLabel; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return list<OrderItem> */
    public function items(): array { return array_values($this->items->toArray()); }

    public function addItem(
        ?Product $product,
        string $sku,
        string $productName,
        int $quantity,
        Money $unitGross,
        int $taxRateBasisPoints,
        Money $lineNet,
        Money $lineTax,
        Money $lineGross,
    ): void {
        $this->assertSnapshotsMutable();
        $this->items->add(new OrderItem($this, $product, $sku, $productName, $quantity, $unitGross, $taxRateBasisPoints, $lineNet, $lineTax, $lineGross));
    }

    public function addAddress(
        OrderAddressRole $role,
        string $recipientName,
        string $phone,
        string $addressLine1,
        ?string $addressLine2,
        string $district,
        string $city,
        ?string $postalCode,
        string $countryCode,
    ): void {
        $this->assertSnapshotsMutable();
        if (null !== $this->address($role)) {
            throw new \DomainException(sprintf('Order already contains a %s address.', $role->value));
        }
        $this->addresses->add(new OrderAddress($this, $role, $recipientName, $phone, $addressLine1, $addressLine2, $district, $city, $postalCode, $countryCode));
    }

    public function address(OrderAddressRole $role): ?OrderAddress
    {
        foreach ($this->addresses as $address) {
            if ($address->role() === $role) {
                return $address;
            }
        }

        return null;
    }

    public function sealSnapshots(): void
    {
        $this->assertSnapshotsMutable();
        $this->assertSnapshotIntegrity();
        $this->snapshotsSealed = true;
    }

    #[ORM\PrePersist]
    public function assertReadyForPersistence(): void
    {
        if (!$this->snapshotsSealed) {
            throw new \DomainException('Order snapshots must be sealed before persistence.');
        }
        $this->assertSnapshotIntegrity();
    }

    public function transitionTo(OrderState $next): void
    {
        if (!$this->snapshotsSealed && null === $this->id) {
            throw new \DomainException('Only a complete placed order can transition.');
        }
        $this->assertSnapshotIntegrity();
        $allowed = match ($this->state) {
            OrderState::Placed => [OrderState::Confirmed, OrderState::Cancelled],
            OrderState::Confirmed => [OrderState::Completed, OrderState::Cancelled],
            OrderState::Cancelled, OrderState::Completed => [],
        };
        if (!in_array($next, $allowed, true)) {
            throw new \DomainException(sprintf('Order cannot transition from %s to %s.', $this->state->value, $next->value));
        }

        $this->state = $next;
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function assertSnapshotIntegrity(): void
    {
        if ($this->items->isEmpty() || null === $this->address(OrderAddressRole::Shipping) || null === $this->address(OrderAddressRole::Billing)) {
            throw new \DomainException('A placed order requires items plus shipping and billing snapshots.');
        }

        $lineGrossTotal = Money::ofMinor(0, $this->currency);
        $lineTaxTotal = Money::ofMinor(0, $this->currency);
        foreach ($this->items as $item) {
            $lineGrossTotal = $lineGrossTotal->add($item->lineGross());
            $lineTaxTotal = $lineTaxTotal->add($item->taxAmount());
        }
        if (!$lineGrossTotal->equals($this->subtotal()) || !$lineTaxTotal->equals($this->taxTotal())) {
            throw new \DomainException('Order line snapshots do not match the order totals.');
        }
    }

    private function assertSnapshotsMutable(): void
    {
        if ($this->snapshotsSealed || null !== $this->id) {
            throw new \DomainException('Placed order snapshots are immutable.');
        }
    }
}
