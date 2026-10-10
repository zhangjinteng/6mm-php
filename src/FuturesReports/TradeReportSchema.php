<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

final class TradeReportSchema
{
    public const TABLE = 'admin_futures_trade_report_daily';

    public static function create(ConnectionInterface $connection): void
    {
        // Always public: search_path switches must not create a duplicate report table.
        $table = $connection->getDriverName() === 'pgsql' ? 'public.' . self::TABLE : self::TABLE;
        $schema = $connection->getSchemaBuilder();
        if ($schema->hasTable($table)) return;
        $schema->create($table, static function (Blueprint $t): void {
            $t->date('stat_date')->comment('UTC统计日期');
            $t->string('source_type', 16)->comment('来源类型：merchant商户、tenant白标、platform平台');
            $t->bigInteger('source_id')->comment('来源主键，平台为0；白标为tenant_id');
            $t->string('source_code', 64)->comment('来源显示编号');
            $t->string('source_name', 128)->comment('来源显示名称');
            $t->string('symbol', 64)->default('')->comment('合约；空字符串表示全部合约');
            $t->bigInteger('trading_user_count')->comment('去重成交用户数');
            $t->bigInteger('order_count')->comment('新建委托笔数');
            $t->decimal('order_amount', 38, 16)->comment('委托金额USDT');
            $t->bigInteger('filled_order_count')->comment('去重成交委托数');
            $t->bigInteger('fill_count')->comment('去重成交笔数');
            $t->decimal('trade_amount', 38, 16)->comment('去重成交金额USDT');
            $t->bigInteger('cancel_order_count')->comment('撤单笔数');
            $t->decimal('cancel_amount', 38, 16)->comment('撤单未成交金额USDT');
            $t->json('identities')->comment('月报去重所需用户、委托、成交ID及成交金额');
            $t->smallInteger('status')->comment('0统计中、1已完成、2数据不完整');
            $t->timestampTz('updated_at')->comment('最近成功聚合时间');
            $t->unique(['stat_date', 'source_type', 'source_id', 'symbol'], 'futures_trade_daily_scope_unique');
            $t->index(['source_type', 'source_id', 'symbol', 'stat_date'], 'futures_trade_daily_read_idx');
        });
    }
}
