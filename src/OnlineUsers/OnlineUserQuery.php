<?php

declare(strict_types=1);

namespace SixMm\Shared\OnlineUsers;

final class OnlineUserQuery
{
    private const SORTABLE_FIELDS = ['vip_level', 'last_login_at'];

    public function __construct(
        private int $page = 1,
        private int $pageSize = 20,
        private string $keyword = '',
        private ?int $userType = null,
        private ?int $vipLevel = null,
        private ?string $createdAtStart = null,
        private ?string $createdAtEndExclusive = null,
        private string $orderBy = 'last_login_at',
        private string $orderDirection = 'desc'
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
        $this->keyword = trim($this->keyword);
        $this->orderBy = in_array($this->orderBy, self::SORTABLE_FIELDS, true)
            ? $this->orderBy
            : 'last_login_at';
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

    public function vipLevel(): ?int
    {
        return $this->vipLevel;
    }

    public function createdAtStart(): ?string
    {
        return $this->createdAtStart;
    }

    public function createdAtEndExclusive(): ?string
    {
        return $this->createdAtEndExclusive;
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
