<?php

namespace SixMm\Shared\FuturesReports\Legacy\Models;

class AgentDailyReportRun extends PublicModel
{
    public const STATUS_RUNNING = 0;

    public const STATUS_SUCCESS = 1;

    public const STATUS_PARTIAL = 2;

    public const STATUS_FAILED = 3;

    protected $table = 'admin_agent_daily_report_runs';

    protected $fillable = [
        'agent_id',
        'stat_date',
        'job_name',
        'status',
        'attempt_count',
        'calculation_version',
        'source_window_start',
        'source_window_end',
        'source_watermark',
        'affected_rows',
        'checksum',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected $casts = [
        'agent_id' => 'integer',
        'stat_date' => 'date:Y-m-d',
        'status' => 'integer',
        'attempt_count' => 'integer',
        'calculation_version' => 'integer',
        'source_window_start' => 'datetime:Y-m-d H:i:s',
        'source_window_end' => 'datetime:Y-m-d H:i:s',
        'source_watermark' => 'array',
        'affected_rows' => 'integer',
        'started_at' => 'datetime:Y-m-d H:i:s',
        'finished_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
