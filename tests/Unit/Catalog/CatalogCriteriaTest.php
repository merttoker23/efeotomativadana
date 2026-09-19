<?php

namespace App\Tests\Unit\Catalog;

use App\Module\Catalog\Query\CatalogCriteria;
use App\Module\Catalog\Query\CatalogSort;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class CatalogCriteriaTest extends TestCase
{
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
