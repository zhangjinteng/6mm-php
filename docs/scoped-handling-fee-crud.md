# 范围内手续费率管理

共享后端 `HandlingFeeConfigManager` 与共享前端 `MmHandlingFeeConfig` 已接入 Platform 和 Agent。平台固定 `0/0`；白标使用当前账户首个有权限的启用站点及 `agent_id=0`；商户使用数据库中的站点和主商户 ID，子账号共享主商户范围。请求参数不能覆盖归属。

## 数据与同步

- 表内、HTTP 与 TK 均传原始小数费率；组件使用 `rate-unit="fraction"`，以百分比编辑和展示，包含零费率。
- VIP 编号固定，删除不重排其他等级，不重用已删除编号。删除 0 级被禁止。删除本层费率后按同级站点、平台继承；已确认的删除不会被账户补缺初始化重新建立。
- Redis 保存操作请求、原始 TK command_id、version、操作前后数据、操作人、回执及状态，不新增同步记录表。TK 未知结果不会改写本地费率；必须重试原操作。收到明确成功后先保存回执，再更新本地；SQL 提交失败或提交后 Redis 更新失败均可恢复，不得将 SQL 回滚当作 TK 回滚。
- 待确认、TK 已成功但本地未完成、VIP 待重算的操作不设置过期时间。全部完成（含 VIP 重算）或 TK 明确拒绝后保留 **7 天**；重复重试不会延长期限。前端请求号带创建时间，过期旧请求不能被当作新命令执行。
- 账户初始化通过宿主缓存保存命令与版本以抵抗数据库回滚；生产缓存应持久化。已有完整范围不会自动覆盖 TK。
- 原 VIP 日任务按用户站点与商户读取有效阈值，并处理本次费率操作影响的用户。无需新增定时任务。

## 部署

1. 先部署含本变更的两个共享包，再部署宿主。
2. 复用已有 `handling_fee_level_config` 的归属字段、`tk_version` 及未删除记录的 `(home_site_id, agent_id, level)` 唯一索引。不再包含操作日志建表代码；Agent 的 `2026_11_12_200000_enable_scoped_handling_fee_crud` 只更新白标、商户菜单及增删改权限项，不自动给普通角色授权。已执行过的旧日志表不自动删除，历史待确认记录应先核对再切换。
3. 生产需验证 TK 的 `admin` 与 `agent_admin` 身份具备查询与变更费率的 ACL：`GetFeeLevelConfig`、`BatchUpdateFeeLevelConfig`、`DeleteFeeLevelConfig`。不得以管理员身份代替商户身份绕过上游权限。
4. 当前本地打包用于联调，不等于已向共享包仓库发布。发布流水线应替换为对应正式版本。
5. 两端必须使用相同的 `HANDLING_FEE_REDIS_*` 连接地址和鉴权参数及 `HANDLING_FEE_REDIS_PREFIX`（默认 `handling-fee:{operations}:v1`）。小票及恢复记录固定使用 **DB0**，不再使用 `HANDLING_FEE_REDIS_DB`，允许与普通缓存同库。缓存清理必须只清理实际 `laravel_` 开头的缓存 key，不得执行 FLUSHDB/FLUSHALL；手续费和 RPC 小票前缀不得设为 `laravel_`。应持久化、备份并使用 `noeviction`；未确认记录不能当普通缓存清理。Redis 不可用时手续费写操作失败关闭，不会先发送 TK 写请求。原 DB2 记录不自动搬动或删除，有未完成操作时需先核对。

## 验证

共享回归：`composer test`；UI：`npm run typecheck`、`npm test -- --run src/components/handling-fee-config/__tests__/HandlingFeeConfig.test.ts`。
宿主隔离回归使用 SQLite 和假 RPC，不修改真实 TK 费率。
Redis 集成回归使用独立临时 Redis：`HANDLING_FEE_TEST_REDIS_SOCKET=/tmp/.../redis.sock php tests/handling_fee_redis.php`，不得指向正式实例。
