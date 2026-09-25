<?php

namespace App\Module\Integration\B2b;

use App\Module\Catalog\ProductIdentifierType;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;

final readonly class NormalizedCatalogFeedItem
{
    private string $externalId;
    private string $sku;
    private string $name;
    private ?string $description;
    private ?string $brandExternalId;
    private ?string $brandName;
    private string $categoryExternalId;
    private string $categoryName;
    /** @var list<array{ProductIdentifierType, string}> */
    private array $identifiers;
    /** @var array<string, string> */
    private array $attributes;
    private Money $grossPrice;
    private TaxRate $taxRate;
    private int $stock;
    /** @var list<string> */
    private array $imageUrls;
    /** @var list<B2bItemError> */
    private array $imageErrors;

    /**
     * @param list<array{ProductIdentifierType, string}> $identifiers
     * @param array<string, string> $attributes
     * @param list<string> $imageUrls
     * @param list<B2bItemError> $imageErrors
     */
    public function __construct(
        string $externalId,
        string $sku,
        string $name,
        ?string $description,
        ?string $brandExternalId,
        ?string $brandName,
        string $categoryExternalId,
        string $categoryName,
        array $identifiers,
        array $attributes,
        Money $grossPrice,
        TaxRate $taxRate,
        int $stock,
        array $imageUrls,
        array $imageErrors = [],
    ) {
        $this->externalId = self::required($externalId, 'External ID', 191);
        $this->sku = self::required($sku, 'SKU', 64);
        $this->name = self::required($name, 'Product name', 255);
        $description = null === $description ? null : trim($description);
        $this->description = '' === $description ? null : $description;
        $this->brandExternalId = self::nullable($brandExternalId, 'Brand external ID', 191);
        $this->brandName = self::nullable($brandName, 'Brand name', 255);
        $this->categoryExternalId = self::required($categoryExternalId, 'Category external ID', 191);
        $this->categoryName = self::required($categoryName, 'Category name', 255);
        $this->identifiers = self::normalizeIdentifiers($identifiers);
        $this->attributes = self::normalizeAttributes($attributes);
        $this->grossPrice = $grossPrice;
        $this->taxRate = $taxRate;
        if ($stock < 0) {
            throw new \InvalidArgumentException('Stock cannot be negative.');
        }
        $this->stock = $stock;
        $this->imageUrls = self::normalizeUrls($imageUrls);
        $this->imageErrors = $imageErrors;
    }

    public function externalId(): string { return $this->externalId; }
    public function sku(): string { return $this->sku; }
    public function name(): string { return $this->name; }
    public function description(): ?string { return $this->description; }
    public function brandExternalId(): ?string { return $this->brandExternalId; }
    public function brandName(): ?string { return $this->brandName; }
    public function categoryExternalId(): string { return $this->categoryExternalId; }
    public function categoryName(): string { return $this->categoryName; }
    /** @return list<array{ProductIdentifierType, string}> */
    public function identifiers(): array { return $this->identifiers; }
    /** @return array<string, string> */
    public function attributes(): array { return $this->attributes; }
    public function grossPrice(): Money { return $this->grossPrice; }
    public function taxRate(): TaxRate { return $this->taxRate; }
    public function stock(): int { return $this->stock; }
    /** @return list<string> */
    public function imageUrls(): array { return $this->imageUrls; }
    /** @return list<B2bItemError> */
    public function imageErrors(): array { return $this->imageErrors; }

    /**
     * @param list<array{ProductIdentifierType, string}> $identifiers
     * @return list<array{ProductIdentifierType, string}>
     */
    private static function normalizeIdentifiers(array $identifiers): array
    {
        $normalized = [];
        $seen = [];
        foreach ($identifiers as [$type, $code]) {
            $code = mb_strtoupper(self::required($code, 'Product identifier', 120));
            $key = $type->value."\0".$code;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = [$type, $code];
        }

        return $normalized;
    }

    /**
     * @param array<string, string> $attributes
     * @return array<string, string>
     */
    private static function normalizeAttributes(array $attributes): array
    {
        $normalized = [];
        foreach ($attributes as $key => $value) {
            $key = mb_strtolower(trim($key));
            if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key)) {
                throw new \InvalidArgumentException('Normalized attribute key is invalid.');
            }
            $normalized[$key] = self::required($value, 'Attribute value', 500);
        }
        ksort($normalized);

        return $normalized;
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    private static function normalizeUrls(array $urls): array
    {
        $normalized = [];
        foreach ($urls as $url) {
            $url = self::required($url, 'Image URL', 2048);
            if (isset($normalized[$url])) {
                continue;
            }
            $normalized[$url] = true;
        }

        return array_keys($normalized);
    }

    private static function required(string $value, string $field, int $maxLength): string
    {
        $value = trim($value);
        if ('' === $value || mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(sprintf('%s must contain between 1 and %d characters.', $field, $maxLength));
        }

        return $value;
    }

    private static function nullable(?string $value, string $field, int $maxLength): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : self::required($value, $field, $maxLength);
    }
}
