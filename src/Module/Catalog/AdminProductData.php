<?php

declare(strict_types=1);

namespace App\Module\Catalog;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use Symfony\Component\Validator\Constraints as Assert;

final class AdminProductData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    public string $sku = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $name = '';

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'The slug must use lowercase letters, numbers and hyphens.')]
    #[Assert\Length(max: 255)]
    public string $slug = '';

    #[Assert\Length(max: 10000)]
    public ?string $description = null;

    public bool $published = false;
    public ?Brand $brand = null;

    /** @var list<Category> */
    public array $categories = [];

    #[Assert\Length(max: 120)]
    public ?string $manufacturerCode = null;

    #[Assert\Length(max: 3000)]
    public ?string $oemCodes = null;

    #[Assert\Length(max: 3000)]
    public ?string $referenceCodes = null;

    #[Assert\Length(max: 10000)]
    public ?string $imagePaths = null;

    #[Assert\PositiveOrZero]
    public int $baseMinorAmount = 0;

    #[Assert\Currency]
    public string $currency = 'TRY';

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/')]
    public string $taxCategory = 'replacement-part';

    #[Assert\Range(min: 0, max: 10000)]
    public int $taxRateBasisPoints = 2000;

    #[Assert\PositiveOrZero]
    public ?int $saleMinorAmount = null;

    public ?\DateTimeImmutable $saleStartsAt = null;
    public ?\DateTimeImmutable $saleEndsAt = null;

    #[Assert\PositiveOrZero]
    public int $quantity = 0;

    public bool $availableForSale = true;

    public static function fromProduct(Product $product, ?ProductPrice $price, ?ProductInventory $inventory): self
    {
        $data = new self();
        $data->sku = $product->sku();
        $data->name = $product->name();
        $data->slug = $product->slug();
        $data->description = $product->description();
        $data->published = PublicationStatus::Published === $product->publicationStatus();
        $data->brand = $product->brand();
        $data->categories = $product->categories();
        foreach ($product->identifiers() as $identifier) {
            match ($identifier->type()) {
                ProductIdentifierType::Manufacturer => $data->manufacturerCode = $identifier->code(),
                ProductIdentifierType::Oem => $data->oemCodes = self::appendLine($data->oemCodes, $identifier->code()),
                ProductIdentifierType::Reference => $data->referenceCodes = self::appendLine($data->referenceCodes, $identifier->code()),
            };
        }
        $imageLines = [];
        foreach ($product->images() as $image) {
            $imageLines[] = $image->path().(null === $image->altText() ? '' : '|'.$image->altText());
        }
        $data->imagePaths = [] === $imageLines ? null : implode("\n", $imageLines);
        if (null !== $price) {
            $data->baseMinorAmount = $price->basePrice()->minorAmount();
            $data->currency = $price->basePrice()->currency();
            $data->taxCategory = $price->taxCategory()->key();
            $data->taxRateBasisPoints = $price->taxRate()->basisPoints();
            $data->saleMinorAmount = $price->salePrice()?->minorAmount();
            $data->saleStartsAt = $price->saleStartsAt();
            $data->saleEndsAt = $price->saleEndsAt();
        }
        if (null !== $inventory) {
            $data->quantity = $inventory->quantity();
            $data->availableForSale = $inventory->availableForSale();
        }

        return $data;
    }

    private static function appendLine(?string $current, string $line): string
    {
        return null === $current ? $line : $current."\n".$line;
    }
}
