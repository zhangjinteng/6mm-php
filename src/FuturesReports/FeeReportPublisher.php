<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Carbon\CarbonImmutable;
use RuntimeException;
use SixMm\Shared\FuturesReports\Legacy\{AgentFuturesFactRedisRepository, AgentFuturesHourlyFactService};

/** Cache-only publication; never calls a source provider or ClickHouse. */
final class FeeReportPublisher
{
    public function __construct(private AgentFuturesFactRedisRepository $buckets, private SourceDirectory $directory, private FeeReportStore $store) {}

    public function publish(string $date, int $hours, bool $completed, array $sourceIds): int
    {
        if ($hours < 1 || $hours > 24) throw new RuntimeException('统计小时数必须为1至24');
        $day = CarbonImmutable::parse($date, 'UTC')->startOfDay();
        $ids = [];
        for ($i = 0; $i < $hours; $i++) {
            $bucket = $this->hour($day->addHours($i), $sourceIds);
            foreach ($bucket['rows'] as $row) $ids[(string) $row['user_id']] = true;
            unset($bucket, $row);
        }
        $directory = $this->directory->load(array_keys($ids));
        unset($ids);
        $facts = (function () use ($day, $hours, $sourceIds): \Generator {
            for ($i = 0; $i < $hours; $i++) {
                $bucket = $this->hour($day->addHours($i), $sourceIds);
                foreach ($bucket['rows'] as $row) yield $row;
                unset($bucket, $row);
            }
        })();
        return $this->store->replace($date, (new FeeReportAggregator())->build($date, $facts, $directory, $completed));
    }

    private function hour(CarbonImmutable $hour, array $sourceIds): array
    {
        $bucket = $this->buckets->getHour($hour);
        if (!$bucket || ($bucket['status'] ?? '') !== 'completed'
            || (int) ($bucket['calculation_version'] ?? 0) < AgentFuturesHourlyFactService::CALCULATION_VERSION
            || ($bucket['agent_ids'] ?? []) !== $sourceIds || !is_array($bucket['rows'] ?? null)
            || CarbonImmutable::parse($bucket['window_start'] ?? '', 'UTC')->ne($hour)
            || CarbonImmutable::parse($bucket['window_end'] ?? '', 'UTC')->ne($hour->addHour())) {
            throw new RuntimeException('共享手续费报表小时桶 ' . $hour->format('Y-m-d H:00') . ' UTC 缺失、不完整、范围不匹配或版本过旧');
        }
        return $bucket;
    }
}
