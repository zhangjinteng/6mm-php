<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

final class PnlReportSchema
{
    public const TABLE = 'admin_futures_pnl_report_daily';

    public static function create(ConnectionInterface $connection): void
    {
        $table = $connection->getDriverName() === 'pgsql' ? 'public.' . self::TABLE : self::TABLE;
        $exists = $connection->getDriverName() === 'pgsql'
            ? $connection->selectOne('SELECT to_regclass(?) AS table_name', [$table])->table_name !== null
            : $connection->getSchemaBuilder()->hasTable($table);
        if ($exists) return;

        $connection->getSchemaBuilder()->create($table, static function (Blueprint $t): void {
            $t->date('stat_date')->comment('UTC统计日期');
            $t->string('source_type', 16)->comment('merchant、tenant、platform');
            $t->bigInteger('source_id')->comment('来源主键，平台为0');
            $t->string('source_code', 64)->comment('来源编号');
            $t->string('source_name', 128)->comment('来源名称');
            $t->string('symbol', 64)->default('')->comment('空字符串表示全部合约');
            $t->bigInteger('trading_user_count')->comment('去重成交用户数');
            $t->bigInteger('profitable_user_count')->comment('按用户已实现盈亏归并的盈利人数');
            $t->bigInteger('losing_user_count')->comment('按用户已实现盈亏归并的亏损人数');
            $t->decimal('trade_amount', 38, 16)->comment('成交金额USDT，沿用共享交易日报去重金额');
            $t->decimal('profit_amount', 38, 16)->comment('盈利用户已实现盈亏之和');
            $t->decimal('loss_amount', 38, 16)->comment('亏损用户已实现盈亏绝对值之和');
            $t->decimal('net_pnl', 38, 16)->comment('用户净盈亏=盈利金额-亏损金额');
            $t->json('user_metrics')->comment('月报跨日重算所需用户已实现盈亏和成交身份');
            $t->smallInteger('status')->comment('0统计中、1已完成、2数据不完整');
            $t->timestampTz('updated_at')->comment('最近成功聚合时间');
            $t->unique(['stat_date', 'source_type', 'source_id', 'symbol'], 'futures_pnl_daily_scope_unique');
            $t->index(['source_type', 'source_id', 'symbol', 'stat_date'], 'futures_pnl_daily_read_idx');
        });
    }
}
