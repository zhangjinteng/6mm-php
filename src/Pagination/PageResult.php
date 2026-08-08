<?php

declare(strict_types=1);

namespace SixMm\Shared\Pagination;

/** @template TItem of array<string, mixed> */
final class PageResult
{
    /**
     * @param array<int, TItem> $items
     */
    public function __construct(
        private array $items,
        private int $total,
        private int $page,
        private int $pageSize
    ) {
    }

    /** @return array<int, TItem> */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }

    /** @return array{lists: array<int, TItem>, count: int, page: int, limit: int} */
    public function toArray(): array
    {
        return [
            'lists' => $this->items,
            'count' => $this->total,
            'page' => $this->page,
            'limit' => $this->pageSize,
        ];
    }
}
