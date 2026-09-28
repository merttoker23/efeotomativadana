<?php

declare(strict_types=1);

namespace App\Entity\Commerce;

use App\Shared\Money\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One order line inside a {@see ReturnRequest}.
 *
 * The SKU, name, unit price and tax rate are copied from the order item rather than referenced
 * through it. An order item's own fields are already immutable, so this is not about protecting
 * against the catalogue changing — it is about a return being a document in its own right, readable
 * on a screen that must not have to load the whole order to render one row.
 */
#[ORM\Entity]
#[ORM\Table(name: 'commerce_return_request_item')]
#[ORM\UniqueConstraint(name: 'uniq_return_item_request_line', columns: ['return_request_id', 'order_item_id'])]
final class ReturnRequestItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ReturnRequest::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ReturnRequest $returnRequest;

    #[ORM\ManyToOne(targetEntity: OrderItem::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OrderItem $orderItem;

    #[ORM\Column(length: 64)]
    private string $sku;

    #[ORM\Column(length: 255)]
    private string $productName;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column(length: 500)]
    private string $reason;

    #[ORM\Column(type: Types::BIGINT)]
    private int $unitGrossMinorAmount;

    #[ORM\Column]
    private int $taxRateBasisPoints;

    #[ORM\Column(length: 3)]
    private string $currency;

    public function __construct(ReturnRequest $returnRequest, OrderItem $orderItem, int $quantity, string $reason, Money $unitGross, int $taxRateBasisPoints)
    {
        if ($quantity < 1) {
            throw new \DomainException('A return line needs a quantity of at least one.');
        }
        $reason = trim($reason);
        if ('' === $reason || mb_strlen($reason) > 500) {
            throw new \DomainException('A return line requires a reason.');
        }
        if ($taxRateBasisPoints < 0 || $taxRateBasisPoints > 10_000) {
            throw new \DomainException('A return line tax rate must be between 0 and 10000 basis points.');
        }

        $this->returnRequest = $returnRequest;
        $this->orderItem = $orderItem;
        $this->sku = $orderItem->sku();
        $this->productName = $orderItem->productName();
        $this->quantity = $quantity;
        $this->reason = $reason;
        $this->unitGrossMinorAmount = $unitGross->minorAmount();
        $this->currency = $unitGross->currency();
        $this->taxRateBasisPoints = $taxRateBasisPoints;
    }

    public function id(): ?int { return $this->id; }
    public function returnRequest(): ReturnRequest { return $this->returnRequest; }
    public function orderItem(): OrderItem { return $this->orderItem; }
    public function orderItemId(): ?int { return $this->orderItem->id(); }
    public function sku(): string { return $this->sku; }
    public function productName(): string { return $this->productName; }
    public function quantity(): int { return $this->quantity; }
    public function reason(): string { return $this->reason; }
    public function unitGross(): Money { return Money::ofMinor($this->unitGrossMinorAmount, $this->currency); }
    public function taxRateBasisPoints(): int { return $this->taxRateBasisPoints; }
    public function lineGross(): Money { return $this->unitGross()->multiply($this->quantity); }
}
