<?php

namespace SixMm\Shared\FuturesReports\Legacy;

use SixMm\Shared\FuturesReports\Legacy\Models\AgentFuturesDailyStatistic;
use SixMm\Shared\FuturesReports\Legacy\Models\AgentFuturesUserPnlDailyStatistic;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class AgentFuturesDailySummaryCoordinator
{
    private AgentFuturesFactRedisRepository $repository;

    private AgentFuturesDailyStatisticService $dailyStatisticService;

    private AgentFuturesUserPnlDailyStatisticService $userPnlStatisticService;

    public function __construct(
        AgentFuturesFactRedisRepository $repository,
        AgentFuturesDailyStatisticService $dailyStatisticService,
        AgentFuturesUserPnlDailyStatisticService $userPnlStatisticService
    ) {
        $this->repository = $repository;
        $this->dailyStatisticService = $dailyStatisticService;
        $this->userPnlStatisticService = $userPnlStatisticService;
    }

    /**
     * @param int[] $agentIds
     * @return array{date: string, row_count: int, hour_count: int, unchanged: bool}
     */
    public function aggregateCurrent(
        array $agentIds,
        ?CarbonImmutable $now = null
    ): array {
        $now = ($now ?? CarbonImmutable::now('UTC'))->setTimezone('UTC');
        $day = $now->startOfDay();
        $hourCount = $day->diffInHours($now->startOfHour()) + 1;

        return $this->aggregateBuckets($day, $hourCount, $agentIds, true);
    }

    /**
     * @param int[] $agentIds
     * @return array{date: string, row_count: int, hour_count: int, unchanged: bool}
     */
    public function aggregateYesterday(
        array $agentIds,
        bool $force = false,
        ?CarbonImmutable $now = null
    ): array {
        $now = ($now ?? CarbonImmutable::now('UTC'))->setTimezone('UTC');

        return $this->aggregateBuckets(
            $now->subDay()->startOfDay(),
            24,
            $agentIds,
            $force
        );
    }

    /**
     * @param int[] $agentIds
     * @return array{date: string, row_count: int, hour_count: int, unchanged: bool}
     */
    public function rebuildDate(string $date, array $agentIds): array
    {
        return $this->aggregateBuckets(
            $this->parseDate($date),
            24,
            $agentIds,
            true
        );
    }

    /**
     * @param int[] $agentIds
     * @return array{date: string, row_count: int, hour_count: int, unchanged: bool}
     */
    private function aggregateBuckets(
        CarbonImmutable $day,
        int $hourCount,
        array $agentIds,
        bool $force
    ): array {
        $executionRows = [];
        $userPnlRows = [];
        $userTradeRows = [];
        $checksums = [];

        for ($index = 0; $index < $hourCount; $index++) {
            $hour = $day->addHours($index);
            $bucket = $this->repository->getHour($hour);
            if ($bucket === null) {
                throw new RuntimeException(
                    '合约日报缺少UTC小时事实桶：' . $hour->format('Y-m-d H:00:00')
                );
            }
            $this->validateBucket($bucket, $hour);
            $checksums[] = (string) ($bucket['checksum'] ?? '');

            foreach ($bucket['execution_rows'] as $row) {
                if (!is_array($row)) {
                    throw new InvalidArgumentException('合约小时成交汇总行格式无效');
                }
                $executionRows[] = $row;
            }
            foreach ($bucket['user_pnl_rows'] as $row) {
                if (!is_array($row)) {
                    throw new InvalidArgumentException('合约小时用户盈亏行格式无效');
                }
                $userPnlRows[] = $row;
            }
            foreach ($bucket['rows'] as $row) {
                if (!is_array($row)) {
                    throw new InvalidArgumentException('合约小时用户成交汇总行格式无效');
                }
                $userTradeRows[] = $row;
            }
        }

        $date = $day->toDateString();
        $checksum = hash('sha256', implode('|', array_merge([
            'user-pnl-v' . AgentFuturesUserPnlDailyStatisticService::CALCULATION_VERSION,
        ], $checksums)));
        if (!$force && $this->repository->getSummaryChecksum($day) === $checksum) {
            return [
                'date' => $date,
                'row_count' => 0,
                'hour_count' => $hourCount,
                'unchanged' => true,
            ];
        }

        $belongs = static fn (array $row): bool => in_array((int) ($row['agent_id'] ?? -1), $agentIds, true);
        $executionRows = array_values(array_filter($executionRows, $belongs));
        $userPnlRows = array_values(array_filter($userPnlRows, $belongs));
        $userTradeRows = array_values(array_filter($userTradeRows, $belongs));

        // 两类日报全部构造成功后，才进入同一事务替换，避免部分结果覆盖旧数据。
        $dailyRows = $this->dailyStatisticService->buildRows(
            $date,
            $executionRows,
            $agentIds
        );
        $userPnlDailyRows = $this->userPnlStatisticService->buildRows(
            $date,
            $userPnlRows,
            $agentIds,
            $userTradeRows
        );

        DB::transaction(static function () use (
            $agentIds,
            $date,
            $dailyRows,
            $userPnlDailyRows
        ): void {
            AgentFuturesDailyStatistic::query()
                ->whereIn('agent_id', $agentIds)
                ->where('stat_date', $date)
                ->delete();
            AgentFuturesUserPnlDailyStatistic::query()
                ->whereIn('agent_id', $agentIds)
                ->where('stat_date', $date)
                ->delete();

            foreach (array_chunk($dailyRows, 500) as $chunk) {
                AgentFuturesDailyStatistic::query()->insert($chunk);
            }
            foreach (array_chunk($userPnlDailyRows, 500) as $chunk) {
                AgentFuturesUserPnlDailyStatistic::query()->insert($chunk);
            }
        });

        $this->repository->putSummaryChecksum($day, $checksum);

        return [
            'date' => $date,
            'row_count' => count($dailyRows) + count($userPnlDailyRows),
            'hour_count' => $hourCount,
            'unchanged' => false,
        ];
    }

    /** @param array<string, mixed> $bucket */
    private function validateBucket(array $bucket, CarbonImmutable $hour): void
    {
        if (($bucket['status'] ?? null) !== 'completed') {
            throw new InvalidArgumentException('合约日报读取到未完成的小时事实桶');
        }
        if (
            (int) ($bucket['calculation_version'] ?? 0) <
            AgentFuturesHourlyFactService::CALCULATION_VERSION
        ) {
            throw new InvalidArgumentException('合约小时事实桶计算版本过旧，请先重建事实桶');
        }
        if (
            !array_key_exists('rows', $bucket) ||
            !is_array($bucket['rows']) ||
            !array_key_exists('execution_rows', $bucket) ||
            !is_array($bucket['execution_rows']) ||
            !array_key_exists('user_pnl_rows', $bucket) ||
            !is_array($bucket['user_pnl_rows'])
        ) {
            throw new InvalidArgumentException('合约小时事实桶版本过旧，请先重建事实桶');
        }

        try {
            $start = CarbonImmutable::parse(
                (string) ($bucket['window_start'] ?? ''),
                'UTC'
            )->setTimezone('UTC');
            $end = CarbonImmutable::parse(
                (string) ($bucket['window_end'] ?? ''),
                'UTC'
            )->setTimezone('UTC');
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('合约小时事实桶时间格式无效', 0, $e);
        }

        if (!$start->equalTo($hour) || !$end->equalTo($hour->addHour())) {
            throw new InvalidArgumentException('合约小时事实桶时间窗口不连续');
        }
    }

    private function parseDate(string $date): CarbonImmutable
    {
        $date = trim($date);
        try {
            $parsed = CarbonImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $date . ' 00:00:00',
                'UTC'
            );
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('统计日期格式必须为 YYYY-MM-DD', 0, $e);
        }

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('统计日期格式必须为 YYYY-MM-DD');
        }

        return $parsed->startOfDay();
    }
}
