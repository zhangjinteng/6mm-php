<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/** Restores merchant history only from real persisted UTC-boundary snapshots. */
final class PositionReportLegacyBackfill
{
    public function __construct(private ConnectionInterface $database, private SourceDirectory $directory, private PositionReportStore $store) {}

    public function backfill(string $date): int
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        if (!$day || $day->toDateString() !== $date || !$day->lessThan(CarbonImmutable::now('UTC')->startOfDay())) {
            throw new InvalidArgumentException('只能回填已结束的UTC统计日');
        }
        $boundary = $day->addDay()->format('Y-m-d H:i:s');
        $boundaryQuery = $this->database->getDriverName() === 'pgsql'
            ? $day->addDay()->format('Y-m-d H:i:sP') : $boundary;
        $table = ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . 'admin_agent_futures_user_symbol_daily';
        $facts = $this->database->table($table)->where('stat_date', $date)
            ->where('position_snapshot_at', $boundaryQuery)
            ->get(['agent_id', 'user_type', 'user_id', 'symbol', 'long_position_amount', 'short_position_amount',
                'closing_position_amount', 'occupied_margin', 'unrealized_pnl'])
            ->map(static fn ($row): array => (array) $row)->all();
        if ($facts === []) return 0;
        $ids = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['user_id'], $facts)));
        $directory = $this->directory->load($ids);
        $scope = new ReportUserScope($directory);
        $observedMerchants = [];
        foreach ($facts as $fact) {
            $merchant = $scope->merchant($fact);
            if ($merchant !== null) $observedMerchants[(string) $merchant['source_id']] = true;
        }
        $rows = (new PositionReportAggregator())->build($date, [
            'status' => 'completed', 'snapshot_at' => $boundary, 'rows' => $facts,
        ], $directory, true);
        return $this->store->insertMissingMerchantRows($date, array_values(array_filter($rows,
            static fn (array $row): bool => $row['source_type'] === 'merchant' && isset($observedMerchants[(string) $row['source_id']]))));
    }
}
