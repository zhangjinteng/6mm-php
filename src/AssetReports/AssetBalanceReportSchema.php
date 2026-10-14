<?php

declare(strict_types=1);

namespace SixMm\Shared\AssetReports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

final class AssetBalanceReportSchema
{
    public const DIRECT_TABLE = 'admin_asset_direct_balance_daily';

    public static function create(ConnectionInterface $db): void
    {
        $table = ($db->getDriverName() === 'pgsql' ? 'public.' : '') . self::DIRECT_TABLE;
        $schema = $db->getSchemaBuilder();
        $exists = $db->getDriverName() === 'pgsql'
            ? $db->selectOne('SELECT to_regclass(?) AS table_name', [$table])->table_name !== null
            : $schema->hasTable($table);
        if ($exists) return;
        $schema->create($table, static function (Blueprint $t): void {
            $t->date('stat_date');
            $t->bigInteger('tenant_id');
            $t->string('currency', 16);
            $t->string('account_type', 16);
            $t->bigInteger('funded_user_count')->default(0);
            $t->decimal('opening_total', 38, 16)->nullable();
            $t->decimal('closing_total', 38, 16)->nullable();
            $t->decimal('closing_available', 38, 16)->nullable();
            $t->decimal('closing_occupied', 38, 16)->nullable();
            $t->boolean('is_finalized')->default(false);
            $t->timestampTz('opening_snapshot_at')->nullable();
            $t->timestampTz('closing_snapshot_at')->nullable();
            $t->timestampTz('updated_at');
            $t->unique(['stat_date', 'tenant_id', 'currency', 'account_type'], 'asset_direct_daily_scope_unique');
            $t->index(['tenant_id', 'stat_date'], 'asset_direct_daily_read_idx');
        });
    }
}
