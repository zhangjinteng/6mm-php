<?php

namespace SixMm\Shared\FuturesReports\Legacy\Models;

class AgentFuturesDailyStatistic extends PublicModel
{
    public const USER_TYPE_ALL = 0;

    public const SYMBOL_SCOPE_ALL = 0;

    public const SYMBOL_SCOPE_SINGLE = 1;

    public const STATUS_PROCESSING = 0;

    public const STATUS_COMPLETED = 1;

    public const STATUS_PARTIAL = 2;

    public const STATUS_FAILED = 3;

    protected $table = 'admin_agent_futures_daily_statistics';

    protected $fillable = [
        'agent_id',
        'stat_date',
        'user_type',
        'settle_asset',
        'symbol_scope',
        'symbol',
        'trading_user_count',
        'order_count',
        'order_amount',
        'filled_order_count',
        'fill_count',
        'trade_amount',
        'cancel_order_count',
        'cancel_amount',
        'trading_fee',
        'commission_user_count',
        'commission_count',
        'maker_commission',
        'taker_commission',
        'fee_user_count',
        'fee_count',
        'maker_trade_amount',
        'taker_trade_amount',
        'maker_handling_fee',
        'taker_handling_fee',
        'position_user_count',
        'long_position_user_count',
        'short_position_user_count',
        'closing_position_amount',
        'long_position_amount',
        'short_position_amount',
        'occupied_margin',
        'unrealized_pnl',
        'realized_pnl',
        'funding_fee',
        'funding_settlement_user_count',
        'funding_settlement_count',
        'funding_settlement_position_amount',
        'funding_fee_income',
        'funding_fee_expense',
        'liquidation_count',
        'liquidation_fee',
        'liquidation_user_count',
        'liquidation_position_amount',
        'liquidation_trade_amount',
        'liquidation_realized_pnl',
        'bankruptcy_count',
        'bankruptcy_loss',
        'uncollected_liquidation_fee',
        'uncollected_trading_fee',
        'margin_change',
        'profitable_user_count',
        'losing_user_count',
        'profit_amount',
        'loss_amount',
        'net_pnl',
        'status',
        'calculation_version',
        'last_aggregated_at',
        'finalized_at',
    ];

    protected $casts = [
        'agent_id' => 'integer',
        'stat_date' => 'date:Y-m-d',
        'user_type' => 'integer',
        'symbol_scope' => 'integer',
        'trading_user_count' => 'integer',
        'order_count' => 'integer',
        'filled_order_count' => 'integer',
        'fill_count' => 'integer',
        'cancel_order_count' => 'integer',
        'commission_user_count' => 'integer',
        'commission_count' => 'integer',
        'fee_user_count' => 'integer',
        'fee_count' => 'integer',
        'position_user_count' => 'integer',
        'long_position_user_count' => 'integer',
        'short_position_user_count' => 'integer',
        'funding_settlement_user_count' => 'integer',
        'funding_settlement_count' => 'integer',
        'liquidation_count' => 'integer',
        'liquidation_user_count' => 'integer',
        'bankruptcy_count' => 'integer',
        'profitable_user_count' => 'integer',
        'losing_user_count' => 'integer',
        'status' => 'integer',
        'calculation_version' => 'integer',
        'last_aggregated_at' => 'datetime:Y-m-d H:i:s',
        'finalized_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
