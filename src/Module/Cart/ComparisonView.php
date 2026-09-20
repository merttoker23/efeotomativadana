<?php

namespace App\Module\Cart;

final readonly class ComparisonView
{
    /**
     * @param list<SavedProductView>  $products
     * @param list<ComparisonRowView> $rows
     */
    public function __construct(
        public array $products,
        public array $rows,
    ) {
    }
}
