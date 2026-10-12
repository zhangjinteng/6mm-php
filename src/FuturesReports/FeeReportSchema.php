<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

final class FeeReportSchema
{
    public const TABLE = 'admin_futures_fee_report_daily';

    public static function create(ConnectionInterface $connection): void
    {
        $table = ($connection->getDriverName() === 'pgsql' ? 'public.' : '') . self::TABLE;
        $schema = $connection->getSchemaBuilder();
        $exists = $connection->getDriverName() === 'pgsql'
            ? $connection->selectOne('SELECT to_regclass(?) AS table_name', [$table])->table_name !== null
            : $schema->hasTable($table);
        if ($exists) return;
        $sqlite = $connection->getDriverName() === 'sqlite';
        $schema->create($table, static function (Blueprint $t) use ($sqlite): void {
            // SQLite fixtures keep exact decimals as text instead of NUMERIC's float affinity.
            $money = static fn (string $field) => $sqlite ? $t->text($field) : $t->decimal($field, 38, 16);
            $t->date('stat_date')->comment('UTC统计日期');
            $t->string('source_type', 16)->comment('来源类型：merchant商户、tenant白标、platform平台');
            $t->bigInteger('source_id')->comment('来源主键：商户ID、白标tenant_id，平台为0');
            $t->string('source_code', 64)->comment('来源显示编号');
            $t->string('source_name', 128)->comment('来源显示名称');
            $t->string('symbol', 64)->default('')->comment('合约；空字符串表示全部合约');
            $t->bigInteger('fee_user_count')->comment('有非零手续费记录的周期去重用户数');
            $t->bigInteger('fee_count')->comment('非零手续费记录笔数，按互不重叠小时累计');
            $money('maker_trade_amount')->comment('Maker成交金额USDT，手续费明细trade_value绝对值之和');
            $money('taker_trade_amount')->comment('Taker成交金额USDT，手续费明细trade_value绝对值之和');
            $money('maker_handling_fee')->comment('Maker手续费USDT，handling_fee绝对值之和，非返佣');
            $money('taker_handling_fee')->comment('Taker手续费USDT，handling_fee绝对值之和，非返佣');
            $money('total_handling_fee')->comment('总手续费USDT，Maker手续费加Taker手续费');
            $t->json('identities')->comment('月报去重私有扣费用户集合，不返回前端');
            $t->smallInteger('status')->comment('0统计中、1已完成、2数据不完整');
            $t->timestampTz('updated_at')->comment('最近成功发布时间');
            $t->unique(['stat_date', 'source_type', 'source_id', 'symbol'], 'futures_fee_daily_scope_unique');
            $t->index(['source_type', 'source_id', 'symbol', 'stat_date'], 'futures_fee_daily_read_idx');
        });
        if ($connection->getDriverName() === 'pgsql') $connection->statement("COMMENT ON TABLE public." . self::TABLE . " IS '内盘合约手续费共享日报：平台、白标及商户维度'");
    }
}
