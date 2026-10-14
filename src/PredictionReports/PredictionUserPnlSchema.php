<?php

declare(strict_types=1);

namespace SixMm\Shared\PredictionReports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

final class PredictionUserPnlSchema
{
    public const TABLE = 'admin_prediction_user_pnl_daily';

    public static function create(ConnectionInterface $database): void
    {
        $table = ($database->getDriverName() === 'pgsql' ? 'public.' : '') . self::TABLE;
        $schema = $database->getSchemaBuilder();
        $exists = $database->getDriverName() === 'pgsql'
            ? $database->selectOne('SELECT to_regclass(?) AS table_name', [$table])->table_name !== null
            : $schema->hasTable($table);
        if ($exists) return;
        $schema->create($table, static function (Blueprint $t): void {
            $t->date('stat_date')->comment('UTC统计日期');
            $t->string('source_type', 16)->comment('merchant、tenant、platform');
            $t->bigInteger('source_id')->comment('来源ID，平台为0');
            $t->string('source_code', 64);
            $t->string('source_name', 128);
            $t->string('play_type', 20)->comment('all、grid、high_low、up_down');
            $t->string('symbol', 40)->default('*')->comment('星号表示全部合约');
            $t->string('settle_asset', 20)->default('USDT');
            foreach (['trading_user_count', 'valid_trading_user_count', 'profitable_user_count',
                'losing_user_count', 'breakeven_user_count', 'order_count', 'win_order_count',
                'lose_order_count', 'refund_order_count'] as $field) $t->bigInteger($field);
            foreach (['stake_amount', 'valid_stake_amount', 'return_amount', 'user_net_pnl',
                'platform_pnl'] as $field) $t->decimal($field, 38, 16);
            $t->decimal('payout_rate', 20, 8);
            $t->smallInteger('status')->comment('0统计中、1已完成、2数据不完整');
            $t->timestampTz('updated_at');
            $t->unique(['stat_date', 'source_type', 'source_id', 'play_type', 'symbol'], 'prediction_user_pnl_daily_scope_uq');
            $t->index(['source_type', 'source_id', 'play_type', 'symbol', 'stat_date'], 'prediction_user_pnl_daily_read_idx');
        });
    }
}
