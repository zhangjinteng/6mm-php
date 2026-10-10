# 共享合约交易日报 / 月报

## 职责

- 6mm-ui：`MmFuturesTradeReport`，基于 MmProTable，拥有日报/月报切换、查询、排序、分页和中英文文案；不包含 URL、权限或请求客户端。
- 6mm-php：共享小时事实采集、商户兼容汇总、多来源去重、目录关联、日报存储和月报查询。
- agent：鉴权后强制取主商户 ID，只查询本商户；页面保留原 URL。
- platform：鉴权/菜单权限、商户/白标/平台筛选及唯一事实生产者。

新的读取接口不查询 ClickHouse。日报在 PostgreSQL 分页，且不读取去重 JSON；月报使用已保存的身份集合跨日去重，日期窗口扩展为完整自然月，最多366天。

## 白标归属

实际数据库中 `home_site_id` 指向站点，不可直接等同于白标 `tenant_id`：

1. `public.agents.id` 是商户 ID，`users.agent_id` 是商户 ID。
2. 用户不可变的 `public.users.home_site_id` 优先，用户资料缺失时回退到商户 `home_site_id`。
3. 通过 `control_plane.wl_sites.site_id -> tenant_id` 查到 `control_plane.wl_tenants`。
4. 多个站点可归属同一个白标；同值商户/白标 ID 用 `source_type + source_id` 隔离。

映射缺失/冲突仍保留商户和平台事实，但标记“数据不完整”；不会把未确认的白标统计标成已完成。平台直接用户的历史 `agent_id=0` 也进入事实采集。

## 统计口径

- UTC 自然日；月报跨日合并，不直接相加“成交用户”。
- 成交用户：有成交的去重内部用户 ID。
- 成交订单数：去重订单 ID。
- 成交笔数/成交金额：按合约与成交 ID 去重；一个成交的两侧分属不同商户时，白标/平台不得重复计算。
- 委托、撤单笔数和金额维持原小时事实口径；金额用十进制字符串与 BCMath，不转换为 float。
- 金额成交率：成交金额 / 委托金额 × 100%，分母为0时返回0。
- 商户与白标存在包含关系；两类行不能直接混合求和。
- 旧版小时桶没有逐成交金额，不能用于新的白标/平台报表；版本/采集范围不一致时拒绝发布，保留之前成功的日报。

## Schema 配置

agent/platform 使用同一主库。推荐：

```dotenv
DB_CONNECTION=pgsql
DB_SCHEMA=public,control_plane
DB_CONNECT_TIMEOUT=5
```

`DB_SCHEMA` 支持 `public`、`control_plane` 或二者的逗号列表；Laravel 8 的 search_path 以数组传入，避免把整串当作一个 schema。

另有固定命名连接：

```php
DB::connection('public')->table('agents')->get();
DB::connection('control_plane')->table('wl_tenants')->get();
```

不要对长驻 worker 动态执行全局 SET search_path。共享报表及兼容模型的 public 表和白标目录的 control_plane 表均显式限定 schema，不依赖 search_path。应用其他旧业务模型仍需默认连接能解析 public，不建议把整个应用默认 search_path 只设为 control_plane。

## 共享 Redis

两端同一实例/库、相同 domain/key_prefix/version，使用不带应用前缀的专用连接：

```dotenv
FUTURES_REPORT_DOMAIN=freedex
FUTURES_FACT_REDIS_CONNECTION=futures_reports
FUTURES_FACT_REDIS_KEY_PREFIX=futures:facts:freedex
FUTURES_FACT_REDIS_VERSION=v7
FUTURES_FACT_REDIS_TTL_SECONDS=259200
```

专用连接默认回退 REDIS_*。两应用的默认 Redis 实例若不同，必须同时配置相同的 `FUTURES_FACT_REDIS_HOST/PORT/PASSWORD/DB/SCHEME` 或 URL；不要保留旧的 `FUTURES_FACT_REDIS_CONNECTION=default`，因为两端原有默认连接的物理前缀不同。

## 调度与保护

仅 platform 在 `FUTURES_REPORT_ENABLED=true` 时注册：

- 每5分钟：覆盖当前/上一小时，补缺失小时，持久化商户兼容事实，再发布共享汇总。
- UTC 00:00：合约持仓边界快照。
- UTC 00:05至00:55，每5分钟：从完整缓存重新发布昨日，供跨日迟到更新收敛；不强制扫描全天。

使用 platform Redis 缓存锁串行化事实、兼容汇总、共享发布，锁 TTL 为6小时；默认单条 ClickHouse 查询2线程/30秒。生产不能人工删除仍在运行任务的锁。

开启前必须确认 ClickHouse 查询账号允许下调查询限额。`readonly=1` 会禁止修改 SETTINGS，除非服务端通过 `CHANGEABLE_IN_READONLY` 明确授权对应设置。不得为绕过该错误删除线程/超时保护，更不能使用没有限额的自动线程配置执行冷启动。建议管理员保留只读权限，为 `max_threads`、`max_execution_time` 及读取量设置安全默认值/上下界并允许受限调整；随后先验证单小时成本，再初始化缓存。

```dotenv
# 只在 platform 开启；初始化前保持 false
FUTURES_REPORT_ENABLED=false
FUTURES_REPORT_MAX_MISSING_HOURS=2
FUTURES_REPORT_MAX_THREADS=2
FUTURES_REPORT_QUERY_TIMEOUT=30
```

缺失/过旧/采集范围变更超过2个小时桶时，在源查询前退出，不会冷启动自动扫描一整天。agent 的3个旧合约生产命令已拒绝执行，并移除了相应调度；非合约的用户/保证金/工作台任务及其状态检查未迁移。

手工命令（须先审批读取成本并在低峰执行，不是部署时自动运行）：

```bash
# 允许初始化明确的一个UTC日期；最多24个小时，仍受查询线程/超时限制
php artisan platform:refresh-futures-reports --date=2026-10-09 --bootstrap
# 缓存完整后的单小时修复；缺失太多仍会拒绝
php artisan platform:refresh-futures-reports --date=2026-10-09 --hour=12
```

今天请走普通刷新，不使用 --hour。历史日的持仓边界不能用今天的实时持仓冒充；--snapshot 只允许 UTC 零点当分钟。若长任务/故障使零点快照错过，旧持仓日报必须保留不完整状态，不能用稍后实时持仓补成已完成。超出上一小时的迟到成交需明确日期/小时修复，不承诺任意迟到记录自动重扫。

## 迁移及切换

新增 platform 迁移：

- `2026_10_10_000001_create_shared_futures_trade_report.php`：`public.admin_futures_trade_report_daily`，带中文注释、来源/日期/合约唯一键和读取索引。
- `2026_10_10_000002_add_futures_trade_report_menu.php`：platform“合约报表 / 合约交易”，组件路径 `simulated/tradeDailyReport`，权限 `futuresTradeReport:lists`。非超级管理员需按现有角色流程授权。

切换顺序：

1. 发布共享库及两端代码，先停止 agent 原合约调度；platform 生产开关保持关闭。
2. 核对两端主库、Schema 访问权限及共享 Redis；平台需写共享表/兼容统计表，agent 仅读取共享表。
3. 由有迁移权限的账号，在 platform 执行上述两项迁移（只执行指定迁移，勿无检查运行所有待迁移文件）。
4. 暂停 platform scheduler，临时开启生产开关，按明确日期/预算初始化需要的 v7 缓存并核对报表；完成后启用 platform scheduler。
5. 验收 agent 主/子账号隔离，platform 商户/白标筛选、月报跨日去重、排序/分页及原其他报表。

新共享表未初始化前列表为空。旧日报及原始流水不删除；旧数据缺少成交去重明细，不能直接相加伪造白标/月报，因此历史范围需要受控回补。迁移、缓存和调度的实际状态须以各部署环境的验收记录为准，发布共享包不等于已经初始化报表。

## 本地联调与正式发布

实现阶段本地接入使用 Composer path（6mm-php 版本映射1.0.39）及 npm file（6mm-ui 1.0.56），读取的是修改后的共享源码/构建文件，不是旧 vendor 包。联调目录要求：

```text
PhpstormProjects/
  agent/6mm-php, 6mm-ui, agent_admin, agent_admin_view
  platform/platform_admin, platform_admin_view
```

正式发布使用 6mm-php `v1.0.39`、6mm-ui `v1.0.56`（含 UI dist）。两应用须移除 Composer path 仓库，将 UI file 依赖切换为固定标签归档并重新生成锁文件；CI 不会取得未发布的本地目录。验收 vendor/node_modules 不再是本地共享目录的 symlink，并核对锁文件引用的提交/归档完整性。不要把本地 symlink vendor 复制到服务器。

## 验证

- 6mm-php：`composer test`；新的 `tests/futures_reports.php` 默认纯 SQLite 内存库，不访问生产。
- 该测试额外可用 `--pgsql=<端口>`，仅连接127.0.0.1的专用 `futures_report_test` 测试库；fixture 事务会回滚。
- agent：`vendor/bin/phpunit --filter 'AgentFutures|FuturesTradeReport' tests/Unit`。
- platform：`vendor/bin/phpunit tests/Unit/FuturesTradeReportIntegrationTest.php`。
- 6mm-ui：`npm run typecheck`、`npm test -- --run`、`npm run build`；两前端另执行 Vite build。

测试不代表真实 ClickHouse 负载或所有生产数据已验证；上线仍需观察耗时/读量/CPU与真实参数对照。
