<?php

namespace SixMm\Shared\FuturesReports\Legacy;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class AgentFuturesHourlyFactCoordinator
{
    private AgentFuturesHourlyFactService $hourlyFactService;

    private AgentFuturesFactRedisRepository $repository;

    private AgentFuturesDailyFactService $dailyFactService;

    public function __construct(
        AgentFuturesHourlyFactService $hourlyFactService,
        AgentFuturesFactRedisRepository $repository,
        AgentFuturesDailyFactService $dailyFactService
    ) {
        $this->hourlyFactService = $hourlyFactService;
        $this->repository = $repository;
        $this->dailyFactService = $dailyFactService;
    }

    /**
     * 每5分钟路径：覆盖当前小时和上一小时，补齐今天缺失小时后聚合入库。
     *
     * @param int[] $agentIds
     * @return array{date: string, row_count: int, hour_count: int}
     */
    public function aggregateCurrent(
        array $agentIds,
        ?CarbonImmutable $now = null
    ): array {
        $now = ($now ?? CarbonImmutable::now('UTC'))->setTimezone('UTC');
        $currentHour = $now->startOfHour();
        $today = $now->startOfDay();

        $this->hourBucket($currentHour->subHour(), $agentIds, true);
        $this->hourBucket($currentHour, $agentIds, true);

        $snapshot = $this->hourlyFactService->currentPositionSnapshot($agentIds, $now);
        $this->repository->putSnapshot($today, $snapshot);
        $hourCount = $today->diffInHours($currentHour) + 1;
        $rowCount = $this->dailyFactService->persistHourlyBuckets(
            $today->toDateString(),
            $this->cachedBuckets($today, $hourCount, $agentIds),
            $snapshot
        );

        return [
            'date' => $today->toDateString(),
            'row_count' => $rowCount,
            'hour_count' => $hourCount,
        ];
    }

    /**
     * 昨日小时桶维护。常规每小时只补缺失桶；定格前可强制重建24小时。
     *
     * @param int[] $agentIds
     * @return array{date: string, row_count: int, hour_count: int}
     */
    public function aggregateYesterday(
        array $agentIds,
        bool $force = false,
        ?CarbonImmutable $now = null
    ): array {
        $now = ($now ?? CarbonImmutable::now('UTC'))->setTimezone('UTC');

        return $this->rebuildDate(
            $now->subDay()->toDateString(),
            $agentIds,
            null,
            $force
        );
    }

    /**
     * 手工补算：不指定小时则重建全天；指定小时则覆盖该小时，并补齐其余缺失桶。
     *
     * @param int[] $agentIds
     * @return array{date: string, row_count: int, hour_count: int}
     */
    public function rebuildDate(
        string $date,
        array $agentIds,
        ?int $hour = null,
        bool $forceAll = true
    ): array {
        $day = $this->parseDate($date);
        if ($hour !== null && ($hour < 0 || $hour > 23)) {
            throw new InvalidArgumentException('小时必须是 0 至 23 的整数');
        }

        if ($hour !== null) {
            $this->hourBucket($day->addHours($hour), $agentIds, true);
        }

        $rowCount = $this->dailyFactService->persistHourlyBuckets(
            $day->toDateString(),
            $this->dateBuckets($day, $agentIds, $hour, $forceAll)
        );

        return [
            'date' => $day->toDateString(),
            'row_count' => $rowCount,
            'hour_count' => 24,
        ];
    }

    /**
     * 逐小时读取并归并，避免24个小时桶同时驻留PHP内存。
     *
     * @param int[] $agentIds
     * @return \Generator<int, array<string, mixed>>
     */
    private function cachedBuckets(
        CarbonImmutable $start,
        int $hourCount,
        array $agentIds
    ): \Generator {
        for ($index = 0; $index < $hourCount; $index++) {
            $bucket = $this->hourBucket($start->addHours($index), $agentIds, false);
            yield $bucket;
            unset($bucket);
        }
    }

    /**
     * @param int[] $agentIds
     * @return \Generator<int, array<string, mixed>>
     */
    private function dateBuckets(
        CarbonImmutable $day,
        array $agentIds,
        ?int $repairedHour,
        bool $forceAll
    ): \Generator {
        for ($index = 0; $index < 24; $index++) {
            $force = $repairedHour === null ? $forceAll : false;
            $bucket = $this->hourBucket($day->addHours($index), $agentIds, $force);
            yield $bucket;
            unset($bucket);
        }
    }

    /** @param int[] $agentIds */
    private function hourBucket(
        CarbonImmutable $hour,
        array $agentIds,
        bool $force
    ): array {
        $hour = $hour->setTimezone('UTC')->startOfHour();

        if (!$force) {
            $existing = $this->repository->getHour($hour);
            if (
                $existing !== null &&
                (int) ($existing['calculation_version'] ?? 0) >=
                    AgentFuturesHourlyFactService::CALCULATION_VERSION &&
                (!isset($existing['agent_ids']) || $existing['agent_ids'] === array_values(array_map('intval', $agentIds)))
            ) {
                return $existing;
            }
        }

        unset($existing);
        // 先完成全部数据源查询，成功后才原子替换Redis，失败时保留旧桶。
        $bucket = $this->hourlyFactService->build($hour, $agentIds);
        $this->repository->putHour($hour, $bucket);

        return $bucket;
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
