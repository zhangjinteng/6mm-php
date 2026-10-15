<?php

declare(strict_types=1);

namespace SixMm\Shared\FundingOverview;

final class FundingOverviewListQuery
{
    public const SORTABLE_FIELDS = ['user_id', 'username', 'funding_balance', 'contract_equity',
        'contract_occupied', 'total_assets', 'last_changed_at'];

    public function __construct(
        public int $page = 1,
        public int $pageSize = 20,
        public string $keyword = '',
        public ?string $startTime = null,
        public ?string $endTimeExclusive = null,
        public string $orderBy = 'funding_balance',
        public string $orderDirection = 'desc'
    ) {
        $this->page = max(1, $page);
        $this->pageSize = min(100, max(1, $pageSize));
        $this->keyword = trim($keyword);
        $this->orderBy = in_array($orderBy, self::SORTABLE_FIELDS, true) ? $orderBy : 'funding_balance';
        $this->orderDirection = strtolower($orderDirection) === 'asc' ? 'asc' : 'desc';
    }
}
