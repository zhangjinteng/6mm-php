<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/** One-time migration of retained per-user daily facts; live publication still uses hourly buckets. */
final class FundingFeeReportLegacyBackfill
{
    public function __construct(
        private ConnectionInterface $database,
        private SourceDirectory $directory,
        private FundingFeeReportStore $store
    ) {}

    /** @return array{days: int, rows: int, skipped: int} */
    public function run(string $from, string $to): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC');
        if (!$start || !$end || $start->toDateString() !== $from || $end->toDateString() !== $to
            || $end->lessThan($start) || $start->diffInDays($end) > 365
            || !$end->lessThan(CarbonImmutable::now('UTC')->startOfDay())) {
            throw new InvalidArgumentException('回填范围必须是过去的有效UTC日期，且不超过366天');
        }

        $prefix = $this->database->getDriverName() === 'pgsql' ? 'public.' : '';
        $summary = $prefix . 'admin_agent_futures_daily_statistics';
        $facts = $prefix . 'admin_agent_futures_user_symbol_daily';
        $target = $prefix . FundingFeeReportSchema::TABLE;
        $result = ['days' => 0, 'rows' => 0, 'skipped' => 0];
        $dates = $this->database->table($summary)->where('user_type', 2)->where('symbol_scope', 0)
            ->where('symbol', '')->whereBetween('stat_date', [$from, $to])
            ->distinct()->orderBy('stat_date')->pluck('stat_date');

        foreach ($dates as $rawDate) {
            $date = substr((string) $rawDate, 0, 10);
            // Do not replace live/hourly-produced data or a previous backfill.
            if ($this->database->table($target)->where('stat_date', $date)->exists()) {
                $result['skipped']++;
                continue;
            }
            $legacyStatus = $this->database->table($summary)->where('stat_date', $date)
                ->where('user_type', 2)->where('symbol_scope', 0)->where('symbol', '')
                ->selectRaw('MIN(status) AS minimum, MAX(status) AS maximum')->first();
            $completed = $legacyStatus !== null && (int) $legacyStatus->minimum === 2
                && (int) $legacyStatus->maximum === 2;
            $rows = $this->database->table($facts)->where('stat_date', $date)
                ->where('user_type', 2)->where('settle_asset', 'USDT')
                ->get(['agent_id', 'user_id', 'user_type', 'symbol', 'settle_asset',
                    'funding_settlement_count', 'funding_settlement_position_amount',
                    'funding_fee_income', 'funding_fee_expense', 'funding_fee'])
                ->map(static fn (object $row): array => (array) $row)->all();
            $directory = $this->directory->load(array_column($rows, 'user_id'));
            $aggregated = (new FundingFeeReportAggregator())->build($date, $rows, $directory, $completed);
            $result['rows'] += $this->store->replace($date, $aggregated);
            $result['days']++;
        }
        return $result;
    }
}
