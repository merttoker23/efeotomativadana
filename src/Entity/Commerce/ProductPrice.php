<?php

namespace App\Entity\Commerce;

use App\Entity\Catalog\Product;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Repository\Commerce\ProductPriceRepository;
use App\Shared\Money\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductPriceRepository::class)]
#[ORM\Table(name: 'commerce_product_price')]
#[ORM\UniqueConstraint(name: 'uniq_commerce_price_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_commerce_price_sale_bounds', columns: ['sale_starts_at', 'sale_ends_at'])]
class ProductPrice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(name: 'base_minor_amount', type: Types::BIGINT)]
    private int $baseMinorAmount;

    #[ORM\Column(name: 'sale_minor_amount', type: Types::BIGINT, nullable: true)]
    private ?int $saleMinorAmount = null;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'tax_category', length: 50)]
    private string $taxCategoryKey;

    #[ORM\Column(name: 'tax_rate_basis_points', type: Types::INTEGER)]
    private int $taxRateBasisPoints;

    #[ORM\Column(name: 'sale_starts_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $saleStartsAt = null;

    #[ORM\Column(name: 'sale_ends_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $saleEndsAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Product $product,
        Money $basePrice,
        TaxCategory $taxCategory,
        TaxRate $taxRate,
    ) {
        $this->product = $product;
        $this->setPricePolicy($basePrice, $taxCategory, $taxRate);
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function product(): Product
    {
        return $this->product;
    }

    public function basePrice(): Money
    {
        return Money::ofMinor($this->baseMinorAmount, $this->currency);
    }

    public function salePrice(): ?Money
    {
        return null === $this->saleMinorAmount
            ? null
            : Money::ofMinor($this->saleMinorAmount, $this->currency);
    }

    public function taxCategory(): TaxCategory
    {
        return TaxCategory::of($this->taxCategoryKey);
    }

    public function taxRate(): TaxRate
    {
        return TaxRate::fromBasisPoints($this->taxRateBasisPoints);
    }

    public function saleStartsAt(): ?\DateTimeImmutable
    {
        return $this->saleStartsAt;
    }

    public function saleEndsAt(): ?\DateTimeImmutable
    {
        return $this->saleEndsAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function scheduleSale(
        Money $salePrice,
        ?\DateTimeImmutable $startsAt = null,
        ?\DateTimeImmutable $endsAt = null,
    ): void {
        self::assertValidSale($salePrice, $this->basePrice());
        if (null !== $startsAt && null !== $endsAt && $startsAt >= $endsAt) {
            throw new \InvalidArgumentException('Sale start must be before sale end.');
        }

        $this->saleMinorAmount = $salePrice->minorAmount();
        // Doctrine DATETIME columns omit offsets; store sale instants in the runtime's UTC convention.
        $utc = new \DateTimeZone('UTC');
        $this->saleStartsAt = $startsAt?->setTimezone($utc);
        $this->saleEndsAt = $endsAt?->setTimezone($utc);
        $this->touch();
    }

    public function clearSale(): void
    {
        $this->saleMinorAmount = null;
        $this->saleStartsAt = null;
        $this->saleEndsAt = null;
        $this->touch();
    }

    public function reconfigure(Money $basePrice, TaxCategory $taxCategory, TaxRate $taxRate): void
    {
        $salePrice = $this->salePrice();
        if (null !== $salePrice) {
            self::assertValidSale($salePrice, $basePrice);
        }

        $this->setPricePolicy($basePrice, $taxCategory, $taxRate);
        $this->touch();
    }

    public function isSaleActiveAt(\DateTimeImmutable $now): bool
    {
        return null !== $this->saleMinorAmount
            && (null === $this->saleStartsAt || $this->saleStartsAt <= $now)
            && (null === $this->saleEndsAt || $now < $this->saleEndsAt);
    }

    public function sellPriceAt(\DateTimeImmutable $now): Money
    {
        if (!$this->isSaleActiveAt($now)) {
            return $this->basePrice();
        }

        if (null === $this->saleMinorAmount) {
            throw new \LogicException('An active sale must have a sale price.');
        }

        return Money::ofMinor($this->saleMinorAmount, $this->currency);
    }

    private function setPricePolicy(Money $basePrice, TaxCategory $taxCategory, TaxRate $taxRate): void
    {
        if (mb_strlen($taxCategory->key()) > 50) {
            throw new \InvalidArgumentException('Tax category key cannot exceed 50 characters.');
        }

        $this->baseMinorAmount = $basePrice->minorAmount();
        $this->currency = $basePrice->currency();
        $this->taxCategoryKey = $taxCategory->key();
        $this->taxRateBasisPoints = $taxRate->basisPoints();
    }

    private static function assertValidSale(Money $salePrice, Money $basePrice): void
    {
        if ($salePrice->currency() !== $basePrice->currency()) {
            throw new \InvalidArgumentException('Sale and base prices must use the same currency.');
        }

        if ($salePrice->minorAmount() >= $basePrice->minorAmount()) {
            throw new \InvalidArgumentException('Sale price must be strictly below the base price.');
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
