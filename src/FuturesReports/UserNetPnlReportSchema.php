<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

final class UserNetPnlReportSchema
{
    public const TABLE = 'admin_futures_user_net_pnl_report_daily';

    public static function create(ConnectionInterface $database): void
    {
        $table = $database->getDriverName() === 'pgsql' ? 'public.' . self::TABLE : self::TABLE;
        $schema = $database->getSchemaBuilder();
        $exists = $database->getDriverName() === 'pgsql'
            ? $database->selectOne('SELECT to_regclass(?) AS table_name', [$table])->table_name !== null
            : $schema->hasTable($table);
        if ($exists) return;
        $schema->create($table, static function (Blueprint $t): void {
            $t->date('stat_date')->comment('UTC统计日期');
            $t->string('source_type', 16)->comment('merchant商户、tenant白标、platform平台');
            $t->bigInteger('source_id')->comment('来源主键，平台为0');
            $t->string('source_code', 64)->comment('来源显示编号');
            $t->string('source_name', 128)->comment('来源显示名称');
            $t->string('settle_asset', 20)->default('USDT')->comment('结算币种');
            foreach (UserNetPnlReportAggregator::COUNTS as $field) $t->bigInteger($field)->default(0);
            foreach (UserNetPnlReportAggregator::AMOUNTS as $field) $t->decimal($field, 38, 16)->default(0);
            $t->smallInteger('status')->default(0)->comment('0统计中、1已完成、2数据不完整');
            $t->timestampTz('updated_at')->comment('最近成功聚合时间');
            $t->unique(['stat_date', 'source_type', 'source_id', 'settle_asset'], 'futures_user_pnl_daily_scope_uq');
            $t->index(['source_type', 'source_id', 'stat_date'], 'futures_user_pnl_daily_read_idx');
        });
    }
}
