<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

final class RiskReportSchema
{
    public const TABLE = 'admin_futures_risk_report_daily';

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
            // SQLite's NUMERIC affinity converts precise decimal strings to floats; keep text in test fixtures.
            $money = static fn (string $field) => $sqlite ? $t->text($field) : $t->decimal($field, 38, 16);
            $t->date('stat_date')->comment('UTC统计日期');
            $t->string('source_type', 16)->comment('来源类型：merchant商户、tenant白标、platform平台');
            $t->bigInteger('source_id')->comment('来源主键：商户ID、白标tenant_id，平台为0');
            $t->string('source_code', 64)->comment('来源显示编号');
            $t->string('source_name', 128)->comment('来源显示名称');
            $t->string('symbol', 64)->default('')->comment('合约；空字符串表示全部合约');
            $t->bigInteger('liquidation_user_count')->comment('跨小时去重强平用户数');
            $t->bigInteger('liquidation_count')->comment('按用户及仓位ID去重的强平次数');
            $money('liquidation_position_amount')->comment('强平仓位金额USDT，按仓位取最大值后求和');
            $money('liquidation_trade_amount')->comment('实际强平成交金额USDT');
            $money('liquidation_realized_pnl')->comment('用户强平已实现盈亏USDT，保留正负号');
            $money('liquidation_fee')->comment('实际收取强平费用USDT');
            $t->bigInteger('bankruptcy_count')->comment('按最终穿仓账本事件去重的穿仓次数');
            $money('bankruptcy_loss')->comment('账本最终穿仓损失USDT，按事件取最大值后求和');
            $money('uncollected_liquidation_fee')->comment('账本最终未收强平费USDT');
            $money('uncollected_trading_fee')->comment('账本最终未收正常交易手续费USDT');
            $t->json('identities')->comment('月报去重私有集合：用户、仓位及最终穿仓事件，不返回前端');
            $t->smallInteger('status')->comment('0统计中、1已完成、2数据不完整');
            $t->timestampTz('updated_at')->comment('最近成功发布时间');
            $t->unique(['stat_date', 'source_type', 'source_id', 'symbol'], 'futures_risk_daily_scope_unique');
            $t->index(['source_type', 'source_id', 'symbol', 'stat_date'], 'futures_risk_daily_read_idx');
        });
        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement("COMMENT ON TABLE public." . self::TABLE . " IS '内盘合约强平共享日报：平台、白标及商户维度'");
        }
    }
}
