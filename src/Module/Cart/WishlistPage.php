<?php

namespace App\Module\Cart;

final readonly class WishlistPage
{
    /** @param list<SavedProductView> $items */
    public function __construct(public array $items, public int $page, public int $perPage, public int $total)
    {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages();
    }
}
