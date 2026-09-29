<?php

declare(strict_types=1);

namespace App\Module\Seo\StructuredData;

use App\Module\Catalog\Query\CatalogProductDetail;
use App\Module\Seo\SeoText;
use App\Module\Seo\SeoUrlFactory;
use App\Shared\Money\DecimalAmount;
use App\Shared\Money\Money;

/**
 * The Product graph for a product page.
 *
 * Every figure in it is read from the local price and inventory records — the same ones the
 * page renders and the same ones the cart revalidates against. A structured-data price that
 * came from anywhere else, or from a cached snapshot, is a public promise about money that the
 * checkout will not keep.
 *
 * A product with no price row carries no `offers` at all rather than an offer at 0.00. "0.00"
 * says the part is free, and a shopper who believes it is a customer service problem; the
 * honest statement is that there is no price.
 */
final readonly class ProductData
{
    public function __construct(
        private SeoUrlFactory $urls,
        private SeoText $text,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function for(CatalogProductDetail $product, string $canonicalUrl): array
    {
        $graph = [
            '@type' => 'Product',
            'name' => $product->name,
            'sku' => $product->sku,
            'url' => $canonicalUrl,
            'itemCondition' => 'https://schema.org/NewCondition',
        ];

        $description = $this->text->summary($product->description, 512);
        if ('' !== $description) {
            $graph['description'] = $description;
        }

        if (null !== $product->brandName) {
            $graph['brand'] = ['@type' => 'Brand', 'name' => $product->brandName];
        }

        $images = $this->imageUrls($product);
        if ([] !== $images) {
            $graph['image'] = $images;
        }

        // A shopper looking for "the part that fits my car" searches by the manufacturer's own
        // code, which is the one identifier they are guaranteed to have written down.
        $manufacturer = $this->codeOfType($product, 'manufacturer');
        if (null !== $manufacturer) {
            $graph['mpn'] = $manufacturer;
        }

        $offers = $this->offer($product, $canonicalUrl);
        if (null !== $offers) {
            $graph['offers'] = $offers;
        }

        return $graph;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function offer(CatalogProductDetail $product, string $canonicalUrl): ?array
    {
        $price = $product->sellPrice;
        if (!$price instanceof Money || $price->isZero()) {
            return null;
        }

        return [
            '@type' => 'Offer',
            'url' => $canonicalUrl,
            'priceCurrency' => $price->currency(),
            'price' => DecimalAmount::forCurrency($price),
            'availability' => $this->availability($product),
            'itemCondition' => 'https://schema.org/NewCondition',
        ];
    }

    /**
     * Availability is stated from the trusted local quantity, and "not offered" is kept
     * distinct from "none": a part sitting in the warehouse that the store has decided not to
     * sell is a backorder, not an out-of-stock item, and conflating them tells a shopper their
     * part is unobtainable when it is merely unavailable today.
     */
    private function availability(CatalogProductDetail $product): string
    {
        if ($product->sellable) {
            return 'https://schema.org/InStock';
        }

        return $product->quantity > 0 ? 'https://schema.org/BackOrder' : 'https://schema.org/OutOfStock';
    }

    /** @return list<string> */
    private function imageUrls(CatalogProductDetail $product): array
    {
        $urls = [];
        foreach ($product->images as $image) {
            $url = $this->urls->media($image['path']);
            if (null !== $url && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function codeOfType(CatalogProductDetail $product, string $type): ?string
    {
        foreach ($product->identifiers as $identifier) {
            if ($identifier['type'] === $type) {
                return $identifier['code'];
            }
        }

        return null;
    }
}
