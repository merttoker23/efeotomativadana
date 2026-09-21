<?php

declare(strict_types=1);

namespace App\Module\Admin\Pagination;

/** @template T */
final readonly class AdminPage
{
    /** @param list<T> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasPrevious(): bool { return $this->page > 1; }
    public function hasNext(): bool { return $this->page < $this->pages(); }
}
