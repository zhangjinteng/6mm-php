<?php

namespace SixMm\Shared\FuturesReports\Legacy\Models;

class AgentFuturesUserPnlDailyStatistic extends PublicModel
{
    protected $table = 'admin_agent_futures_user_pnl_daily_statistics';

    protected $fillable = [
        'agent_id',
        'stat_date',
        'user_type',
        'settle_asset',
        'settled_user_count',
        'profitable_user_count',
        'losing_user_count',
        'breakeven_user_count',
        'close_order_count',
        'close_amount',
        'realized_pnl',
        'trading_fee',
        'funding_fee',
        'liquidation_fee',
        'status',
        'calculation_version',
        'last_aggregated_at',
        'finalized_at',
    ];

    protected $casts = [
        'agent_id' => 'integer',
        'stat_date' => 'date:Y-m-d',
        'user_type' => 'integer',
        'settled_user_count' => 'integer',
        'profitable_user_count' => 'integer',
        'losing_user_count' => 'integer',
        'breakeven_user_count' => 'integer',
        'close_order_count' => 'integer',
        'status' => 'integer',
        'calculation_version' => 'integer',
        'last_aggregated_at' => 'datetime:Y-m-d H:i:s',
        'finalized_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
