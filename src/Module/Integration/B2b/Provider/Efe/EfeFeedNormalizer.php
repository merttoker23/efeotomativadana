<?php

namespace App\Module\Integration\B2b\Provider\Efe;

use App\Module\Catalog\ProductIdentifierType;
use App\Module\Integration\B2b\B2bErrorType;
use App\Module\Integration\B2b\B2bItemError;
use App\Module\Integration\B2b\Exception\B2bPermanentProviderException;
use App\Module\Integration\B2b\NormalizedCatalogFeedItem;
use App\Module\Pricing\TaxRate;

final readonly class EfeFeedNormalizer
{
    /** @var array<string, true> */
    private array $allowedHosts;

    /** @param list<string> $allowedHosts */
    public function __construct(
        private EfePriceNormalizer $priceNormalizer,
        array $allowedHosts,
    ) {
        $hosts = [];
        foreach ($allowedHosts as $host) {
            $host = mb_strtolower(trim($host));
            if ('' !== $host) {
                $hosts[$host] = true;
            }
        }
        $this->allowedHosts = $hosts;
    }

    /** @param array<string, mixed> $record */
    public function normalize(array $record): NormalizedCatalogFeedItem
    {
        try {
            $externalId = $this->requiredString($record['id'] ?? null, 'id', 191);
            $sku = $this->requiredString($record['stokkodu'] ?? null, 'stokkodu', 64);
            $name = $this->requiredString($record['cinsi'] ?? null, 'cinsi', 255);
            $description = $this->optionalString($record['aciklama'] ?? null, 'aciklama');
            [$brandExternalId, $brandName] = $this->brand($record);
            [$categoryExternalId, $categoryName] = $this->category($record);
            $this->currency($record);
            $vatPercentage = $this->integer($record['kdvorani'] ?? null, 'kdvorani');
            TaxRate::fromPercentage($vatPercentage);
            $grossPrice = $this->priceNormalizer->grossFromNet(
                $this->requiredString($record['listefiyati'] ?? null, 'listefiyati', 32),
                $this->requiredString($record['iskonto'] ?? null, 'iskonto', 16),
                $vatPercentage,
            );
            $stock = $this->stock($record['mevcut_stok'] ?? null);
            [$imageUrls, $imageErrors] = $this->images($record['urunresimleri'] ?? [], $externalId);

            return new NormalizedCatalogFeedItem(
                externalId: $externalId,
                sku: $sku,
                name: $name,
                description: $description,
                brandExternalId: $brandExternalId,
                brandName: $brandName,
                categoryExternalId: $categoryExternalId,
                categoryName: $categoryName,
                identifiers: $this->identifiers($record, $externalId),
                attributes: $this->attributes($record),
                grossPrice: $grossPrice,
                taxRate: TaxRate::fromPercentage($vatPercentage),
                stock: $stock,
                imageUrls: $imageUrls,
                imageErrors: $imageErrors,
            );
        } catch (B2bPermanentProviderException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new B2bPermanentProviderException('Efe feed item is invalid.', 0, $exception);
        }
    }

    /**
     * @param array<string, mixed> $record
     * @return array{?string, ?string}
     */
    private function brand(array $record): array
    {
        $name = $this->sentinelString($record['uretici_adi'] ?? null, 'uretici_adi', 255);
        if (null === $name) {
            return [null, null];
        }
        $id = $this->sentinelString($record['ureticiid'] ?? null, 'ureticiid', 100);

        return [null === $id ? 'manufacturer-name:'.hash('sha256', mb_strtoupper($name)) : 'manufacturer:'.$id, $name];
    }

    /**
     * @param array<string, mixed> $record
     * @return array{string, string}
     */
    private function category(array $record): array
    {
        $name = $this->sentinelString($record['stok_grubu'] ?? null, 'stok_grubu', 255);
        if (null === $name) {
            $name = 'Diğer Ürünler';

            return ['stok-gru:__default__', $name];
        }
        $normalized = mb_strtoupper((string) preg_replace('/\s+/u', ' ', $name));

        return ['stok-gru:'.hash('sha256', $normalized), $name];
    }

    /** @param array<string, mixed> $record */
    private function currency(array $record): void
    {
        foreach (['parabirimi', 'parabirim'] as $field) {
            if ('TL' !== $this->requiredString($record[$field] ?? null, $field, 3)) {
                throw new \InvalidArgumentException('Efe currency fields must resolve to TL.');
            }
        }
    }

    /**
     * @param array<string, mixed> $record
     * @return list<array{ProductIdentifierType, string}>
     */
    private function identifiers(array $record, string $externalId): array
    {
        $rows = [];
        $manufacturer = $this->sentinelString($record['ureticiid'] ?? null, 'ureticiid', 100);
        if (null !== $manufacturer) {
            $rows[] = [ProductIdentifierType::Manufacturer, $manufacturer];
        }
        $oem = $this->sentinelString($record['oemnumaralari'] ?? null, 'oemnumaralari', 2000);
        if (null !== $oem) {
            $parts = preg_split('/\s{2,}|[,;|]+/u', $oem) ?: [];
            foreach ($parts as $part) {
                $part = trim($part);
                if ('' !== $part) {
                    if (mb_strlen($part) > 120) {
                        throw new \InvalidArgumentException(sprintf('OEM identifier for external item %s exceeds 120 characters.', $externalId));
                    }
                    $rows[] = [ProductIdentifierType::Oem, $part];
                }
            }
        }
        $barcode = $this->sentinelString($record['barkod'] ?? null, 'barkod', 120);
        if (null !== $barcode) {
            $rows[] = [ProductIdentifierType::Reference, $barcode];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, string>
     */
    private function attributes(array $record): array
    {
        $attributes = [];
        $this->attribute($attributes, 'efe-box-quantity', $record['kutuiciadet'] ?? null, '/^(0|[1-9][0-9]*)$/', 500, true);
        $this->attribute($attributes, 'efe-unit', $record['birim'] ?? null);
        $this->attribute($attributes, 'efe-desi', $record['desi'] ?? null, '/^(0|[1-9][0-9]*)(?:\.[0-9]+)?$/', 500, true);
        $this->attribute($attributes, 'efe-vehicle-brand', $record['arac_markasi'] ?? null, null, 500);
        $this->attribute($attributes, 'efe-model-year', $record['modelyili'] ?? null, null, 20);
        $this->attribute($attributes, 'efe-stock-type-id', $record['stokturuid'] ?? null, '/^-?[0-9]+$/');
        $this->attribute($attributes, 'efe-brand-code', $record['marka'] ?? null, null, 500);
        $this->attribute($attributes, 'efe-model-code', $record['model'] ?? null, null, 500);
        $this->attribute($attributes, 'efe-vehicle-brand-code', $record['aracmarka'] ?? null, null, 500);

        return $attributes;
    }

    /** @param array<string, string> $attributes */
    private function attribute(array &$attributes, string $key, mixed $value, ?string $pattern = null, int $maxLength = 500, bool $allowZero = false): void
    {
        if (!is_scalar($value) && null !== $value) {
            return;
        }
        $value = trim((string) $value);
        if ('' === $value || (!$allowZero && '0' === $value) || '-1' === $value || mb_strlen($value) > $maxLength) {
            return;
        }
        if (null !== $pattern && 1 !== preg_match($pattern, $value)) {
            return;
        }
        $attributes[$key] = $value;
    }

    /**
     * @return array{list<string>, list<B2bItemError>}
     */
    private function images(mixed $value, string $externalId): array
    {
        if (!is_array($value)) {
            return [[], [new B2bItemError(B2bErrorType::Image, 'Image list has an invalid type.', $externalId, ['position' => 0])]];
        }
        $urls = [];
        $errors = [];
        foreach (array_values($value) as $position => $candidate) {
            if (!is_string($candidate) || !$this->validImageUrl($candidate)) {
                $errors[] = new B2bItemError(B2bErrorType::Image, 'Image URL is invalid or its host is not allowed.', $externalId, ['position' => $position]);
                continue;
            }
            $urls[$candidate] = true;
        }

        return [array_keys($urls), $errors];
    }

    private function validImageUrl(string $url): bool
    {
        $url = trim($url);
        if ('' === $url || mb_strlen($url) > 2048) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && 'https' === strtolower((string) ($parts['scheme'] ?? ''))
            && isset($parts['host'], $this->allowedHosts[mb_strtolower((string) $parts['host'])])
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    private function stock(mixed $value): int
    {
        $value = $this->requiredString($value, 'mevcut_stok', 32);
        if (1 !== preg_match('/^(0|[1-9][0-9]*)$/', $value)) {
            throw new \InvalidArgumentException('Stock must be a non-negative integer.');
        }
        if (strlen($value) > strlen((string) PHP_INT_MAX) || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            throw new \OverflowException('Stock exceeds the integer range.');
        }

        return (int) $value;
    }

    private function integer(mixed $value, string $field): int
    {
        $value = $this->requiredString($value, $field, 3);
        if (1 !== preg_match('/^(0|[1-9][0-9]*)$/', $value)) {
            throw new \InvalidArgumentException(sprintf('%s must be an integer.', $field));
        }

        return (int) $value;
    }

    private function requiredString(mixed $value, string $field, int $maxLength): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a string.', $field));
        }
        $value = trim($value);
        if ('' === $value || mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(sprintf('%s must contain between 1 and %d characters.', $field, $maxLength));
        }

        return $value;
    }

    private function optionalString(mixed $value, string $field): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a string.', $field));
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private function sentinelString(mixed $value, string $field, int $maxLength): ?string
    {
        $value = $this->optionalString($value, $field);
        if (null === $value || '0' === $value || '-1' === $value) {
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(sprintf('%s exceeds %d characters.', $field, $maxLength));
        }

        return $value;
    }
}
