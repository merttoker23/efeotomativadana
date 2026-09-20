<?php

namespace App\Module\Cart;

final readonly class ComparisonRowView
{
    /** @param array<int, string> $values */
    public function __construct(
        public string $key,
        public string $label,
        public array $values,
    ) {
    }
}
