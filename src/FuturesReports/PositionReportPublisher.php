<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class PositionReportPublisher
{
    public function __construct(private SourceDirectory $directory, private PositionReportStore $store) {}

    public function publish(string $date, array $snapshot, bool $completed): int
    {
        if ((int) ($snapshot['calculation_version'] ?? 0) < Legacy\AgentFuturesHourlyFactService::CALCULATION_VERSION) {
            throw new InvalidArgumentException('持仓快照使用旧统计口径，不能作为全用户类型的持仓报表发布');
        }
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        if (!$day || $day->toDateString() !== $date) throw new InvalidArgumentException('统计日期格式错误');
        if (!$completed && $date !== CarbonImmutable::now('UTC')->toDateString()) {
            throw new InvalidArgumentException('历史日期只能使用次日零点的已完成持仓快照');
        }
        $at = CarbonImmutable::parse((string) ($snapshot['snapshot_at'] ?? ''), 'UTC')->setTimezone('UTC');
        $expected = $completed ? $day->addDay()->toDateString() : $date;
        if ($at->toDateString() !== $expected) {
            throw new InvalidArgumentException('持仓快照时间与统计日期不匹配，禁止用当前持仓回填历史');
        }
        $ids = [];
        foreach (($snapshot['rows'] ?? []) as $row) $ids[(string) ($row['user_id'] ?? '')] = true;
        $rows = (new PositionReportAggregator())->build($date, $snapshot, $this->directory->load(array_keys($ids)), $completed);
        return $this->store->replace($date, $rows);
    }
}
