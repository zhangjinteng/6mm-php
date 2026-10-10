<?php

namespace SixMm\Shared\FuturesReports\Legacy;

use SixMm\Shared\FuturesReports\Legacy\Models\Agent;
use SixMm\Shared\FuturesReports\Legacy\Models\AgentDailyReportRun;
use Carbon\CarbonImmutable;

class AgentDailyReportRunService
{
    public const JOB_FUTURES_FACT = 'futures_daily_fact';

    public const JOB_FUTURES_FACT_FINALIZE = 'futures_daily_fact_finalize';

    public const JOB_FUTURES_SUMMARY = 'futures_daily_summary';

    public const JOB_FUTURES_SUMMARY_FINALIZE = 'futures_daily_summary_finalize';

    public const JOB_FUTURES_POSITION_SNAPSHOT = 'futures_position_snapshot';

    public const JOB_FINALIZE = 'report_finalize';

    /**
     * @return int[]
     */
    public function start(string $jobName, string $statDate, ?array $agentIds = null): array
    {
        $agentIds = $agentIds ?? $this->mainAgentIds();
        $start = CarbonImmutable::parse($statDate, 'UTC')->startOfDay();
        $now = CarbonImmutable::now('UTC');

        foreach ($agentIds as $agentId) {
            $run = AgentDailyReportRun::query()->firstOrNew([
                'agent_id' => $agentId,
                'stat_date' => $start->toDateString(),
                'job_name' => $jobName,
            ]);
            $run->status = AgentDailyReportRun::STATUS_RUNNING;
            $run->attempt_count = ((int) $run->attempt_count) + 1;
            $run->calculation_version = 1;
            $run->source_window_start = $start;
            $run->source_window_end = $start->addDay();
            $run->source_watermark = [];
            $run->affected_rows = 0;
            $run->checksum = null;
            $run->started_at = $now;
            $run->finished_at = null;
            $run->error_message = null;
            $run->save();
        }

        return $agentIds;
    }

    public function succeed(
        string $jobName,
        string $statDate,
        array $agentIds,
        int $affectedRows
    ): void {
        $this->finish(
            $jobName,
            $statDate,
            $agentIds,
            AgentDailyReportRun::STATUS_SUCCESS,
            $affectedRows,
            null
        );
    }

    public function fail(
        string $jobName,
        string $statDate,
        array $agentIds,
        string $message
    ): void {
        $this->finish(
            $jobName,
            $statDate,
            $agentIds,
            AgentDailyReportRun::STATUS_FAILED,
            0,
            $message
        );
    }

    public function finishForAgent(
        string $jobName,
        string $statDate,
        int $agentId,
        int $status,
        int $affectedRows = 0,
        ?string $message = null
    ): void {
        $this->finish(
            $jobName,
            $statDate,
            [$agentId],
            $status,
            $affectedRows,
            $message
        );
    }

    /**
     * @return int[]
     */
    public function mainAgentIds(): array
    {
        return Agent::query()
            ->where('pid', 0)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($agentId): int => (int) $agentId)
            ->all();
    }

    private function finish(
        string $jobName,
        string $statDate,
        array $agentIds,
        int $status,
        int $affectedRows,
        ?string $message
    ): void {
        $now = CarbonImmutable::now('UTC');
        $message = $message === null ? null : mb_substr($message, 0, 4000);

        AgentDailyReportRun::query()
            ->whereIn('agent_id', $agentIds)
            ->where('stat_date', $statDate)
            ->where('job_name', $jobName)
            ->update([
                'status' => $status,
                'affected_rows' => max(0, $affectedRows),
                'finished_at' => $now,
                'error_message' => $message,
                'updated_at' => $now,
            ]);
    }
}
