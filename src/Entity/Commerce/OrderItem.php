<?php

declare(strict_types=1);

namespace App\Entity\Commerce;

use App\Entity\Catalog\Product;
use App\Shared\Money\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'commerce_order_item')]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CustomerOrder::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    // @phpstan-ignore property.onlyWritten (Doctrine owns the child-to-parent association.)
    private CustomerOrder $order;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Product $product;

    #[ORM\Column(length: 64)]
    private string $sku;

    #[ORM\Column(length: 255)]
    private string $productName;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column(type: Types::BIGINT)]
    private int $unitGrossMinorAmount;

    #[ORM\Column]
    private int $taxRateBasisPoints;

    #[ORM\Column(type: Types::BIGINT)]
    private int $lineNetMinorAmount;

    #[ORM\Column(type: Types::BIGINT)]
    private int $lineTaxMinorAmount;

    #[ORM\Column(type: Types::BIGINT)]
    private int $lineGrossMinorAmount;

    #[ORM\Column(length: 3)]
    private string $currency;

    public function __construct(CustomerOrder $order, ?Product $product, string $sku, string $productName, int $quantity, Money $unitGross, int $taxRateBasisPoints, Money $lineNet, Money $lineTax, Money $lineGross)
    {
        if ($quantity < 1 || $quantity > 99 || '' === trim($sku) || '' === trim($productName)) {
            throw new \InvalidArgumentException('Order item snapshot is invalid.');
        }
        if ($taxRateBasisPoints < 0 || $taxRateBasisPoints > 10_000) {
            throw new \InvalidArgumentException('Order item tax rate is invalid.');
        }
        $currency = $unitGross->currency();
        if ($lineNet->currency() !== $currency || $lineTax->currency() !== $currency || $lineGross->currency() !== $currency) {
            throw new \InvalidArgumentException('Order item monies must use one currency.');
        }
        if (!$unitGross->multiply($quantity)->equals($lineGross) || !$lineNet->add($lineTax)->equals($lineGross)) {
            throw new \InvalidArgumentException('Order item totals are inconsistent.');
        }

        $this->order = $order;
        $this->product = $product;
        $this->sku = trim($sku);
        $this->productName = trim($productName);
        $this->quantity = $quantity;
        $this->unitGrossMinorAmount = $unitGross->minorAmount();
        $this->taxRateBasisPoints = $taxRateBasisPoints;
        $this->lineNetMinorAmount = $lineNet->minorAmount();
        $this->lineTaxMinorAmount = $lineTax->minorAmount();
        $this->lineGrossMinorAmount = $lineGross->minorAmount();
        $this->currency = $currency;
    }

    public function id(): ?int { return $this->id; }
    public function product(): ?Product { return $this->product; }
    public function sku(): string { return $this->sku; }
    public function productName(): string { return $this->productName; }
    public function quantity(): int { return $this->quantity; }
    public function unitGross(): Money { return Money::ofMinor($this->unitGrossMinorAmount, $this->currency); }
    public function taxRateBasisPoints(): int { return $this->taxRateBasisPoints; }
    public function lineNet(): Money { return Money::ofMinor($this->lineNetMinorAmount, $this->currency); }
    public function taxAmount(): Money { return Money::ofMinor($this->lineTaxMinorAmount, $this->currency); }
    public function lineGross(): Money { return Money::ofMinor($this->lineGrossMinorAmount, $this->currency); }
}
