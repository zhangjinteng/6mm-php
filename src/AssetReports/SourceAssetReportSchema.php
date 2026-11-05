<?php

declare(strict_types=1);

namespace SixMm\Shared\AssetReports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

/** Published exclusively by Platform. Both admin applications only query this contract. */
final class SourceAssetReportSchema
{
    public const TABLE = 'admin_source_asset_daily_statistics';
    public const VERSION = 3;

    public static function create(ConnectionInterface $db): void
    {
        $pg = $db->getDriverName() === 'pgsql';
        $table = ($pg ? 'public.' : '') . self::TABLE;
        // Laravel 8 does not reliably resolve qualified names when the connection has a custom search_path.
        $exists = $pg ? $db->table('information_schema.tables')->where('table_schema', 'public')
            ->where('table_name', self::TABLE)->exists() : $db->getSchemaBuilder()->hasTable($table);
        if ($exists) return;
        $db->getSchemaBuilder()->create($table, static function (Blueprint $t): void {
            $t->date('stat_date'); $t->string('source_type', 16); $t->string('source_id', 32);
            $t->string('source_name'); $t->string('account_type', 16); $t->string('currency', 16);
            $t->json('asset_report_opening')->nullable(); $t->json('asset_report_closing')->nullable();
            $t->json('asset_report_flows')->nullable(); $t->timestamps();
            $t->primary(['stat_date', 'source_type', 'source_id', 'account_type', 'currency'], 'source_asset_daily_pk');
        });
    }
}
