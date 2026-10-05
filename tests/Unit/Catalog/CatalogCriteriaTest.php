<?php

namespace App\Tests\Unit\Catalog;

use App\Module\Catalog\Query\CatalogCriteria;
use App\Module\Catalog\Query\CatalogSort;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class CatalogCriteriaTest extends TestCase
{
    public function testPriceInputsBecomeMinorUnitsAndSurvivePageAndSortLinks(): void
    {
        $criteria = CatalogCriteria::fromQuery(new InputBag([
            'q' => " lamba\t  accent ", 'min_price' => '2500.25', 'max_price' => '500',
            'category' => 'lamps', 'brand' => 'bosch', 'sort' => 'price-asc',
        ]));
        self::assertSame('lamba accent', $criteria->query);
        self::assertSame(['lamba', 'accent'], $criteria->searchTokens());
        self::assertSame(50_000, $criteria->minPriceMinor);
        self::assertSame(250_025, $criteria->maxPriceMinor);
        self::assertSame([
            'q' => 'lamba accent', 'category' => 'lamps', 'brand' => 'bosch',
            'min_price' => '500.00', 'max_price' => '2500.25', 'sort' => 'price-asc', 'page' => 2,
        ], $criteria->queryParameters(2));
    }

    public function testInvalidAndNegativePricesAreIgnoredWithoutOverflow(): void
    {
        foreach (['-1', 'NaN', '1e20', '99999999999999999999', '12.345', 'abc', ['500']] as $invalid) {
            $criteria = CatalogCriteria::fromQuery(new InputBag(['min_price' => $invalid, 'max_price' => $invalid]));
            self::assertNull($criteria->minPriceMinor);
            self::assertNull($criteria->maxPriceMinor);
        }
        $criteria = CatalogCriteria::fromQuery(new InputBag(['min_price' => '0', 'max_price' => '12,50']));
        self::assertSame(0, $criteria->minPriceMinor);
        self::assertSame(1250, $criteria->maxPriceMinor);
    }

    public function testUntrustedQueryParametersAreNormalizedBeforeTheyReachPersistence(): void
    {
        /** @var InputBag<string> $query */
        $query = new InputBag([
            'q' => '  04E 115 561 H  ',
            'sort' => 'product.name DESC; DROP TABLE catalog_product',
            'page' => '-7',
            'category' => ' Brake-Systems ',
            'brand' => ' BOSCH ',
            'availability' => 'unexpected',
        ]);
        $criteria = CatalogCriteria::fromQuery($query);

        self::assertSame('04E 115 561 H', $criteria->query);
        self::assertSame(CatalogSort::Newest, $criteria->sort);
        self::assertSame(1, $criteria->page);
        self::assertSame('brake-systems', $criteria->categorySlug);
        self::assertSame('bosch', $criteria->brandSlug);
        self::assertFalse($criteria->inStockOnly);
        self::assertSame([
            'q' => '04E 115 561 H',
            'category' => 'brake-systems',
            'brand' => 'bosch',
        ], $criteria->filterParameters());
        self::assertSame([
            'q' => '04E 115 561 H',
            'brand' => 'bosch',
        ], $criteria->filterParameters('category'));
        self::assertSame([
            'q' => '04E 115 561 H',
            'category' => 'brake-systems',
            'brand' => 'bosch',
            'sort' => 'newest',
            'page' => 3,
        ], $criteria->queryParameters(3));
    }

    public function testWhitelistedSortAndAvailabilityFilterArePreserved(): void
    {
        /** @var InputBag<string> $query */
        $query = new InputBag([
            'sort' => 'price-asc',
            'page' => '2',
            'availability' => 'in-stock',
        ]);
        $criteria = CatalogCriteria::fromQuery($query);

        self::assertSame(CatalogSort::PriceAscending, $criteria->sort);
        self::assertSame(2, $criteria->page);
        self::assertTrue($criteria->inStockOnly);
        self::assertSame(['availability' => 'in-stock'], $criteria->filterParameters());
    }
}
