<?php

namespace App\Shared;

/**
 * One bounded slice of a list, plus everything a pager needs to render itself.
 *
 * Used where a screen used to read a whole table. The page size is part of the constructor so
 * that a caller cannot forget it, which is how an admin list ends up with a `findBy([], ...)`
 * and no limit anywhere near it.
 */
final readonly class PagedResult
{
    /**
     * @param list<object> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $totalItems,
    ) {
    }

    public function totalPages(): int
    {
        return max(1, (int) ceil($this->totalItems / max(1, $this->perPage)));
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->totalPages();
    }

    /** @return list<int> */
    public function pageNumbers(): array
    {
        $total = $this->totalPages();
        if ($total <= 7) {
            return range(1, $total);
        }

        $window = [1, $total, $this->page - 1, $this->page, $this->page + 1];
        $numbers = array_values(array_unique(array_filter($window, static fn (int $n): bool => $n >= 1 && $n <= $total)));
        sort($numbers);

        return $numbers;
    }
}