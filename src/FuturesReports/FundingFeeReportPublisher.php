<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Carbon\CarbonImmutable;
use RuntimeException;
use SixMm\Shared\FuturesReports\Legacy\AgentFuturesFactRedisRepository;
use SixMm\Shared\FuturesReports\Legacy\AgentFuturesHourlyFactService;

final class FundingFeeReportPublisher
{
    public function __construct(
        private AgentFuturesFactRedisRepository $buckets,
        private SourceDirectory $directory,
        private FundingFeeReportStore $store
    ) {}

    public function publish(string $date, int $hours, bool $completed, array $sourceIds): int
    {
        if ($hours < 1 || $hours > 24) throw new RuntimeException('统计小时数必须为1至24');
        $day = CarbonImmutable::parse($date, 'UTC')->startOfDay();
        $ids = [];
        for ($i = 0; $i < $hours; $i++) {
            foreach ($this->hour($day->addHours($i), $sourceIds) as $row) {
                $ids[(string) $row['user_id']] = true;
            }
        }
        $directory = $this->directory->load(array_keys($ids));
        $facts = (function () use ($day, $hours, $sourceIds): \Generator {
            for ($i = 0; $i < $hours; $i++) {
                foreach ($this->hour($day->addHours($i), $sourceIds) as $row) yield $row;
            }
        })();
        $rows = (new FundingFeeReportAggregator())->build($date, $facts, $directory, $completed);
        return $this->store->replace($date, $rows);
    }

    private function hour(CarbonImmutable $hour, array $sourceIds): array
    {
        $bucket = $this->buckets->getHour($hour);
        if (!$bucket || ($bucket['status'] ?? '') !== 'completed'
            || (int) ($bucket['calculation_version'] ?? 0) < AgentFuturesHourlyFactService::CALCULATION_VERSION
            || ($bucket['agent_ids'] ?? []) !== $sourceIds || !is_array($bucket['rows'] ?? null)
            || CarbonImmutable::parse($bucket['window_start'] ?? '', 'UTC')->ne($hour)
            || CarbonImmutable::parse($bucket['window_end'] ?? '', 'UTC')->ne($hour->addHour())) {
            throw new RuntimeException('共享资金费报表小时桶缺失、不完整、范围不匹配或版本过旧');
        }
        return $bucket['rows'];
    }
}
