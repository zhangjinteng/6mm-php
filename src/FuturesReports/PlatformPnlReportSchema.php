<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

final class PlatformPnlReportSchema
{
    public const TABLE = 'admin_platform_pnl_report_daily';

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
            $t->string('source_type', 16);
            $t->bigInteger('source_id');
            $t->string('source_code', 64);
            $t->string('source_name', 128);
            $t->string('settle_asset', 20)->default('USDT');
            foreach (PlatformPnlReportAggregator::AMOUNTS as $field) $t->decimal($field, 38, 16)->default(0);
            $t->smallInteger('status')->default(0);
            $t->timestampTz('updated_at');
            $t->unique(['stat_date', 'source_type', 'source_id'], 'platform_pnl_daily_scope_uq');
            $t->index(['source_type', 'source_id', 'stat_date'], 'platform_pnl_daily_read_idx');
        });
    }
}
