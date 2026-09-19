<?php

namespace App\Module\Catalog\Query;

final readonly class CatalogPage
{
    /**
     * @param list<CatalogProductView> $items
     */
    public function __construct(
        public array $items,
        public int $totalItems,
        public int $page,
        public int $perPage,
    ) {
    }

    public function totalPages(): int
    {
        return max(1, (int) ceil($this->totalItems / $this->perPage));
    }

    public function firstItem(): int
    {
        return 0 === $this->totalItems ? 0 : (($this->page - 1) * $this->perPage) + 1;
    }

    public function lastItem(): int
    {
        return min($this->totalItems, $this->page * $this->perPage);
    }

    /** @return list<int> */
    public function pageNumbers(): array
    {
        $totalPages = $this->totalPages();
        if ($totalPages <= 7) {
            return range(1, $totalPages);
        }

        $numbers = [1, $totalPages];
        for ($number = max(2, $this->page - 2); $number <= min($totalPages - 1, $this->page + 2); ++$number) {
            $numbers[] = $number;
        }

        sort($numbers);

        return array_values(array_unique($numbers));
    }

    public function previousPage(): ?int
    {
        return $this->page > 1 ? $this->page - 1 : null;
    }

    public function nextPage(): ?int
    {
        return $this->page < $this->totalPages() ? $this->page + 1 : null;
    }
}
