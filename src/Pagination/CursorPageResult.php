<?php

declare(strict_types=1);

namespace SixMm\Shared\Pagination;

/** @template TItem of array<string, mixed> */
final class CursorPageResult
{
    /**
     * @param array<int, TItem> $items
     */
    public function __construct(
        private array $items,
        private int $pageSize,
        private bool $hasMore,
        private bool $hasPrevious,
        private ?string $nextCursor,
        private ?string $previousCursor
    ) {
    }

    /** @return array<int, TItem> */
    public function items(): array
    {
        return $this->items;
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }

    public function hasMore(): bool
    {
        return $this->hasMore;
    }

    public function hasPrevious(): bool
    {
        return $this->hasPrevious;
    }

    public function nextCursor(): ?string
    {
        return $this->nextCursor;
    }

    public function previousCursor(): ?string
    {
        return $this->previousCursor;
    }

    /**
     * @return array{
     *     lists: array<int, TItem>,
     *     page_size: int,
     *     has_more: bool,
     *     has_previous: bool,
     *     next_cursor: string|null,
     *     previous_cursor: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'lists' => $this->items,
            'page_size' => $this->pageSize,
            'has_more' => $this->hasMore,
            'has_previous' => $this->hasPrevious,
            'next_cursor' => $this->nextCursor,
            'previous_cursor' => $this->previousCursor,
        ];
    }
}
