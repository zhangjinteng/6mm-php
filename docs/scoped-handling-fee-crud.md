# 范围内手续费率管理

共享后端 `HandlingFeeConfigManager` 与共享前端 `MmHandlingFeeConfig` 已接入 Platform 和 Agent。平台固定 `0/0`；白标使用当前账户首个有权限的启用站点及 `agent_id=0`；商户使用数据库中的站点和主商户 ID，子账号共享主商户范围。请求参数不能覆盖归属。

## 数据与同步

- 表内、HTTP 与 TK 均传原始小数费率；组件使用 `rate-unit="fraction"`，以百分比编辑和展示，包含零费率。
- VIP 编号固定，删除不重排其他等级，不重用已删除编号。删除 0 级被禁止。删除本层费率后按同级站点、平台继承；已确认的删除不会被账户补缺初始化重新建立。
- `handling_fee_config_operations` 保存操作请求、原始 TK command_id、version、结果与状态。TK 未知结果不会改写本地费率；必须重试原操作。收到明确成功后再更新本地，发生本地提交错误仍可用同一操作对账重试。不得将 SQL 回滚当作 TK 回滚。
- 账户初始化通过宿主缓存保存命令与版本以抵抗数据库回滚；生产缓存应持久化。已有完整范围不会自动覆盖 TK。
- 原 VIP 日任务按用户站点与商户读取有效阈值，并处理本次费率操作影响的用户。无需新增定时任务。

## 部署

1. 先部署含本变更的两个共享包，再部署宿主。
2. 两个后端先应用 `2026_11_12_180000_scope_handling_fee_levels.php`（已有则跳过），再应用各自的 `2026_11_12_200000_*`。新增操作日志表；Agent 迁移更新白标、商户菜单及增删改权限项，不自动给普通角色授权。
3. 生产需验证 TK 的 `admin` 与 `agent_admin` 身份具备查询与变更费率的 ACL：`GetFeeLevelConfig`、`BatchUpdateFeeLevelConfig`、`DeleteFeeLevelConfig`。不得以管理员身份代替商户身份绕过上游权限。
4. 当前本地打包用于联调，不等于已向共享包仓库发布。发布流水线应替换为对应正式版本。

## 验证

共享回归：`composer test`；UI：`npm run typecheck`、`npm test -- --run src/components/handling-fee-config/__tests__/HandlingFeeConfig.test.ts`。
宿主隔离回归使用 SQLite 和假 RPC，不修改真实 TK 费率。
