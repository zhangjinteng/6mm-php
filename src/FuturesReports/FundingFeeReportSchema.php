<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

final class FundingFeeReportSchema
{
    public const TABLE = 'admin_futures_funding_fee_report_daily';

    public static function create(ConnectionInterface $connection): void
    {
        $table = $connection->getDriverName() === 'pgsql' ? 'public.' . self::TABLE : self::TABLE;
        $schema = $connection->getSchemaBuilder();
        $exists = $connection->getDriverName() === 'pgsql'
            ? $connection->selectOne('SELECT to_regclass(?) AS table_name', [$table])->table_name !== null
            : $schema->hasTable($table);
        if ($exists) return;

        $schema->create($table, static function (Blueprint $t): void {
            $t->date('stat_date');
            $t->string('source_type', 16);
            $t->bigInteger('source_id');
            $t->string('source_code', 64);
            $t->string('source_name', 128);
            $t->string('symbol', 64)->default('');
            $t->bigInteger('funding_settlement_count');
            $t->decimal('funding_settlement_position_amount', 38, 16);
            $t->decimal('funding_fee_income', 38, 16);
            $t->decimal('funding_fee_expense', 38, 16);
            $t->decimal('funding_fee_net_income', 38, 16);
            $t->json('user_ids');
            $t->smallInteger('status');
            $t->timestampTz('updated_at');
            $t->unique(['stat_date', 'source_type', 'source_id', 'symbol'], 'futures_funding_daily_scope_unique');
            $t->index(['source_type', 'source_id', 'symbol', 'stat_date'], 'futures_funding_daily_read_idx');
        });
    }
}
