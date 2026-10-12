<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use SixMm\Shared\FuturesReports\Legacy\AgentFuturesFactRedisRepository;
use SixMm\Shared\FuturesReports\Legacy\AgentFuturesHourlyFactService;

/** Publishes from the same validated hour buckets and already-deduplicated trade daily rows. */
final class PnlReportPublisher
{
    public function __construct(private AgentFuturesFactRedisRepository $buckets, private SourceDirectory $directory,
        private ConnectionInterface $database, private PnlReportStore $store) {}

    public function publish(string $date, int $hours, array $sourceIds): int
    {
        if ($hours < 1 || $hours > 24) throw new RuntimeException('统计小时数必须为1至24');
        $day = CarbonImmutable::parse($date, 'UTC')->startOfDay();
        $userIds = [];
        for ($hour = 0; $hour < $hours; $hour++) {
            $bucket = $this->hour($day->addHours($hour), $sourceIds);
            foreach ($bucket['rows'] as $fact) $userIds[(string) $fact['user_id']] = true;
            unset($bucket, $fact);
        }
        $directory = $this->directory->load(array_keys($userIds));
        unset($userIds);
        $table = ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . TradeReportSchema::TABLE;
        $tradeRows = $this->database->table($table)->where('stat_date', $date)
            ->get(['stat_date', 'source_type', 'source_id', 'source_code', 'source_name', 'symbol', 'trade_amount', 'status'])
            ->map(static fn (object $row): array => (array) $row)->all();
        if ($tradeRows === []) throw new RuntimeException('共享交易日报尚未发布，不能发布盈亏日报');
        $facts = (function () use ($day, $hours, $sourceIds): \Generator {
            for ($hour = 0; $hour < $hours; $hour++) {
                $bucket = $this->hour($day->addHours($hour), $sourceIds);
                foreach ($bucket['rows'] as $fact) yield $fact;
                unset($bucket, $fact);
            }
        })();
        return $this->store->replace($date, (new PnlReportAggregator())->build($date, $facts, $directory, $tradeRows));
    }

    private function hour(CarbonImmutable $hour, array $sourceIds): array
    {
        $bucket = $this->buckets->getHour($hour);
        if (!$bucket || ($bucket['status'] ?? '') !== 'completed'
            || (int) ($bucket['calculation_version'] ?? 0) < AgentFuturesHourlyFactService::CALCULATION_VERSION
            || ($bucket['agent_ids'] ?? []) !== $sourceIds || !is_array($bucket['rows'] ?? null)
            || CarbonImmutable::parse($bucket['window_start'] ?? '', 'UTC')->ne($hour)
            || CarbonImmutable::parse($bucket['window_end'] ?? '', 'UTC')->ne($hour->addHour())) {
            throw new RuntimeException('共享盈亏报表小时桶缺失、不完整、范围不匹配或版本过旧');
        }
        return $bucket;
    }
}
