<?php

namespace App\Tests\Unit\Catalog;

use App\Module\Catalog\Query\CatalogPage;
use PHPUnit\Framework\TestCase;

final class CatalogPageTest extends TestCase
{
    public function testPaginationUsesABoundedWindowAroundTheCurrentPage(): void
    {
        $page = new CatalogPage([], totalItems: 1_200, page: 50, perPage: 12);

        self::assertSame([1, 48, 49, 50, 51, 52, 100], $page->pageNumbers());
        self::assertSame(49, $page->previousPage());
        self::assertSame(51, $page->nextPage());
    }

    public function testPaginationIncludesEveryPageWhenTheResultSetIsSmall(): void
    {
        $page = new CatalogPage([], totalItems: 60, page: 1, perPage: 12);

        self::assertSame([1, 2, 3, 4, 5], $page->pageNumbers());
        self::assertNull($page->previousPage());
        self::assertSame(2, $page->nextPage());
    }

    public function testPaginationHasNoNextPageAtTheEnd(): void
    {
        $page = new CatalogPage([], totalItems: 60, page: 5, perPage: 12);

        self::assertNull($page->nextPage());
    }
}
