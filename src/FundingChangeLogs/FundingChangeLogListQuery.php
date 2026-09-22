<?php

declare(strict_types=1);

namespace SixMm\Shared\FundingChangeLogs;

final class FundingChangeLogListQuery
{
    private string $startTime;
    private string $endTimeExclusive;

    private const SORTABLE_FIELDS = ['ledger_id', 'balance_change', 'balance_before', 'balance_after', 'created_at'];
    public const CHANGE_TYPES = [
        'deposit',
        'stake',
        'payout',
        'refund',
        'transfer_hold_created',
        'transfer_hold_released',
        'transfer_out',
        'transfer_in',
        'agent_transfer_in',
        'agent_transfer_out',
    ];
    private const GAMES = ['', 'prediction', 'prediction_updown', 'prediction_highlow', 'grid'];

    public function __construct(
        private int $page = 1,
        private int $pageSize = 20,
        private string $keyword = '',
        private string $changeType = '',
        private string $game = '',
        ?string $startTime = null,
        ?string $endTimeExclusive = null,
        private string $orderBy = 'created_at',
        private string $orderDirection = 'desc'
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
        $this->keyword = trim($this->keyword);
        $this->changeType = strtolower(trim($this->changeType));
        $this->changeType = $this->changeType === '' || in_array($this->changeType, self::CHANGE_TYPES, true)
            ? $this->changeType
            : '';
        $this->game = strtolower(trim($this->game));
        $this->game = in_array($this->game, self::GAMES, true) ? $this->game : '';
        $this->startTime = trim((string) $startTime);
        $this->endTimeExclusive = trim((string) $endTimeExclusive);
        $this->orderBy = in_array($this->orderBy, self::SORTABLE_FIELDS, true) ? $this->orderBy : 'created_at';
        $this->orderDirection = strtolower($this->orderDirection) === 'asc' ? 'asc' : 'desc';
    }

    public function page(): int { return $this->page; }
    public function pageSize(): int { return $this->pageSize; }
    public function keyword(): string { return $this->keyword; }
    public function changeType(): string { return $this->changeType; }
    public function game(): string { return $this->game; }
    public function startTime(): string { return $this->startTime; }
    public function endTimeExclusive(): string { return $this->endTimeExclusive; }
    public function orderBy(): string { return $this->orderBy; }
    public function orderDirection(): string { return $this->orderDirection; }
}
