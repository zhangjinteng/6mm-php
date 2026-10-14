<?php
declare(strict_types=1);
namespace SixMm\Shared\PlatformReports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

final class TradeSummarySchema
{
    public const TABLE = 'admin_platform_trade_summary_daily';

    public static function table(ConnectionInterface $db): string
    {
        return ($db->getDriverName() === 'pgsql' ? 'public.' : '') . self::TABLE;
    }

    public static function create(ConnectionInterface $db): void
    {
        $table = self::table($db);
        $schema = $db->getSchemaBuilder();
        $exists = $db->getDriverName() === 'pgsql'
            ? $db->selectOne('SELECT to_regclass(?) AS table_name', [$table])->table_name !== null : $schema->hasTable($table);
        if ($exists) return;
        $sqlite = $db->getDriverName() === 'sqlite';
        $schema->create($table, static function (Blueprint $t) use ($sqlite): void {
            $t->date('stat_date')->comment('UTC统计日期');
            $t->string('source_type', 16)->comment('来源类型：platform平台、tenant白标、merchant商户');
            $t->bigInteger('source_id')->comment('来源主键：平台为0、白标为tenant_id、商户为agents.id');
            $t->string('source_code', 64)->comment('来源显示编号');
            $t->string('source_name', 128)->comment('来源显示名称');
            foreach (['total_trading_user_count' => '合约及有效预测联合去重交易用户数', 'trading_user_count' => '去重合约成交用户数',
                'contract_trade_count' => '合约成交笔数', 'prediction_trading_user_count' => '有效预测去重交易用户数',
                'prediction_valid_order_count' => '预测有效订单数，不含退款'] as $field => $comment) $t->bigInteger($field)->comment($comment);
            foreach (['contract_trade_amount' => '合约成交金额USDT', 'prediction_valid_stake_amount' => '预测有效下注金额USDT，不含退款'] as $field => $comment) {
                // SQLite test fixtures cannot represent arbitrary decimals with NUMERIC affinity.
                ($sqlite ? $t->string($field, 64) : $t->decimal($field, 38, 16))->comment($comment);
            }
            $t->json('identities')->comment('内部月报去重用合约及预测用户集合，禁止返回前端');
            $t->smallInteger('status')->comment('统计状态：0统计中、1已完成、2源数据不完整');
            $t->timestampTz('updated_at')->comment('最近成功汇总时间');
            $t->unique(['stat_date', 'source_type', 'source_id'], 'platform_trade_summary_scope_uq');
            $t->index(['source_type', 'source_id', 'stat_date'], 'platform_trade_summary_read_idx');
        });
    }
}
