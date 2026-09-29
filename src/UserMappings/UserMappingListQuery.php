<?php

declare(strict_types=1);

namespace SixMm\Shared\UserMappings;

final class UserMappingListQuery
{
    public const SORTABLE_FIELDS = [
        'mapping_id', 'username', 'agent_user_id', 'user_uid',
        'mapping_status', 'exception_type', 'recent_sync_at', 'agent_id',
    ];

    public function __construct(
        private int $page = 1,
        private int $pageSize = 20,
        private string $keyword = '',
        private string $mappingStatus = '',
        private string $exceptionType = '',
        private ?string $startTime = null,
        private ?string $endTimeExclusive = null,
        private string $orderBy = 'mapping_id',
        private string $orderDirection = 'desc'
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
        $this->keyword = trim($this->keyword);
        $this->mappingStatus = trim($this->mappingStatus);
        $this->exceptionType = trim($this->exceptionType);
        $this->startTime = trim((string) $this->startTime) ?: null;
        $this->endTimeExclusive = trim((string) $this->endTimeExclusive) ?: null;
        $this->orderBy = in_array($this->orderBy, self::SORTABLE_FIELDS, true)
            ? $this->orderBy : 'mapping_id';
        $this->orderDirection = strtolower($this->orderDirection) === 'asc' ? 'asc' : 'desc';
    }

    public function page(): int { return $this->page; }
    public function pageSize(): int { return $this->pageSize; }
    public function keyword(): string { return $this->keyword; }
    public function mappingStatus(): string { return $this->mappingStatus; }
    public function exceptionType(): string { return $this->exceptionType; }
    public function startTime(): ?string { return $this->startTime; }
    public function endTimeExclusive(): ?string { return $this->endTimeExclusive; }
    public function orderBy(): string { return $this->orderBy; }
    public function orderDirection(): string { return $this->orderDirection; }
}
