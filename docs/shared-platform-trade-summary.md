# 共享平台交易汇总

- 共享后端：`SixMm\Shared\PlatformReports\TradeSummary*`。
- 共享 UI：`MmPlatformTradeSummaryReport`。
- 存储：`public.admin_platform_trade_summary_daily`，不随 search_path 切换而复制到 control_plane。
- agent：`/platformTradeSummary/lists`，只读登录账号解析出的主商户；不接收前端传来的身份范围。
- platform：`/platform-trade-summary/lists`，保留菜单权限检查；默认平台，可切换白标/商户，支持来源名称字面模糊搜索及来源 ID 精确搜索。

## 统计口径

以 UTC 自然日归集，币种为 USDT。合约笔数和金额复用共享合约交易日报 `symbol=''` 的 `fill_count`、`trade_amount`，不重新扫描 ClickHouse。预测有效订单为已确定 win/loss 的玩家订单，退款不计入有效用户、订单数、下注金额；读取与既有预测日报相同的事件日期规则。

联合交易用户 = 合约用户集合与有效预测用户集合的并集。日/月用户数均去重，月报不是日报人数相加。成交笔数与金额、有效订单数与金额按不重叠 UTC 日累计。

预测归属只使用 public.users 中 home_site_id>0、user_type 为普通/模拟的用户；商户/白标映射显式从 public.agents 与 control_plane.wl_sites、wl_tenants 获取。平台、白标、商户是包含关系，不能相加。合约侧沿用同一共享报表发布范围。

金额仅使用十进制字符串/BCMath 和 NUMERIC(38,16)。预测库 18 位固定小数中多余尾随零可接受；超过 16 位的有效小数直接报错，禁止静默截断。API 不返回内部 identities；日报 SQL 不读取该列，月报用游标逐日合并用户集合。

后端 `stat_status` 保留真实源状态，用于诊断数据完整性；缺失合约汇总或归属冲突仍标记为不完整，失败不会覆盖原单日数据。共享 UI 的标签按 UTC 统计日期展示：仅当天日报、当月月报显示“统计中”，其他周期显示“已完成”。显示标签不修改源状态，也不代表已补齐缺失数据。

## 迁移及平台生产任务

两端新增迁移 `2026_10_14_000040_create_shared_platform_trade_summary.php` 幂等创建同一 public 表。

```bash
php artisan migrate --path=database/migrations/2026_10_14_000040_create_shared_platform_trade_summary.php --force
php artisan platform:refresh-trade-summary --initialize
php artisan platform:refresh-trade-summary --date=2026-10-13
```

`--initialize` 只初始化已发布合约日报已有日期（最多近 366 日），不会重新计算历史 ClickHouse 数据，也不会覆盖旧用户日报表。更早时期若没有完整合约用户去重明细，不能从旧人数汇总猜测月报去重人数，应另行安排来源回补。

platform 配置 `PLATFORM_TRADE_SUMMARY_ENABLED=true` 后，沿用现有 Laravel 调度器，每五分钟汇总当天，UTC 00:15 重算昨日；顺序在现有合约数据生产任务之后。agent 不增加生产任务。预测库读取为只读事务、30 秒单条 SQL 超时，任务有 Redis 单生产者锁。

本次只做本地源码/依赖工作副本同步；未发布共享包或部署生产镜像。正式发布后需安装包含上述共享类与组件的版本再执行迁移。
