<?php

declare(strict_types=1);

namespace App\Module\Returns;

/**
 * A page of one customer's returns, for the account area.
 *
 * Separate from {@see \App\Module\Admin\Pagination\AdminPage} rather than reusing it: the admin
 * page is an operator's list with an operator's vocabulary, and a customer must never be able to
 * reach an admin-shaped object even indirectly. Same shape, different boundary.
 *
 * @template T
 */
final readonly class ReturnPage
{
    /**
     * @param list<T> $items
     */
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
