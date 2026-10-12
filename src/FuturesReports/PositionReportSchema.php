<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

final class PositionReportSchema
{
    public const TABLE = 'admin_futures_position_report_daily';

    public static function create(ConnectionInterface $connection): void
    {
        $table = ($connection->getDriverName() === 'pgsql' ? 'public.' : '') . self::TABLE;
        $schema = $connection->getSchemaBuilder();
        $exists = $connection->getDriverName() === 'pgsql'
            ? $connection->selectOne('SELECT to_regclass(?) AS table_name', [$table])->table_name !== null
            : $schema->hasTable($table);
        if ($exists) return;
        $schema->create($table, static function (Blueprint $t): void {
            $t->date('stat_date')->comment('UTC统计日期');
            $t->string('source_type', 16)->comment('merchant、tenant、platform');
            $t->bigInteger('source_id')->comment('来源ID，平台为0');
            $t->string('source_code', 64);
            $t->string('source_name', 128);
            $t->string('symbol', 64)->default('')->comment('空字符串表示全部合约');
            $t->bigInteger('position_user_count');
            $t->bigInteger('long_position_user_count');
            $t->bigInteger('short_position_user_count');
            $t->decimal('long_position_amount', 38, 16);
            $t->decimal('short_position_amount', 38, 16);
            $t->decimal('closing_position_amount', 38, 16);
            $t->decimal('occupied_margin', 38, 16);
            $t->decimal('unrealized_pnl', 38, 16);
            $t->decimal('average_leverage', 24, 8);
            $t->smallInteger('status')->comment('0统计中、1已完成、2数据不完整');
            $t->timestampTz('snapshot_at')->comment('实际持仓快照时间');
            $t->timestampTz('updated_at');
            $t->unique(['stat_date', 'source_type', 'source_id', 'symbol'], 'futures_position_daily_scope_unique');
            $t->index(['source_type', 'source_id', 'symbol', 'stat_date'], 'futures_position_daily_read_idx');
        });
    }
}
