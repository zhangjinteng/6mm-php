<?php

declare(strict_types=1);

namespace SixMm\Shared\UserAssets;

final class UserAssetListQuery
{
    private const SORTABLE_FIELDS = [
        'ua_id',
        'user_id',
        'public_user_id',
        'uid',
    ];

    public function __construct(
        private int $page = 1,
        private int $pageSize = 20,
        private string $keyword = '',
        private ?int $userType = null,
        private string $orderBy = 'ua_id',
        private string $orderDirection = 'desc'
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(1000, max(1, $this->pageSize));
        $this->keyword = trim($this->keyword);
        $this->userType = $this->userType !== null && $this->userType > 0
            ? $this->userType
            : null;
        $this->orderBy = in_array($this->orderBy, self::SORTABLE_FIELDS, true)
            ? $this->orderBy
            : 'ua_id';
        $this->orderDirection = strtolower($this->orderDirection) === 'asc' ? 'asc' : 'desc';
    }

    public function page(): int
    {
        return $this->page;
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }

    public function keyword(): string
    {
        return $this->keyword;
    }

    public function userType(): ?int
    {
        return $this->userType;
    }

    public function orderBy(): string
    {
        return $this->orderBy;
    }

    public function orderDirection(): string
    {
        return $this->orderDirection;
    }
}
