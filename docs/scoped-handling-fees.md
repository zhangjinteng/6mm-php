# 白标、主商户手续费初始化

初始化功能复用已有表结构，不再提供手续费归属改表迁移；不修改配置中的数据库。

## 归属与复制

`handling_fee_level_config` 的有效行按 `(home_site_id, agent_id, level)` 唯一：平台 `0/0`，白标 `site/0`，主商户 `site/agent`。子账号不创建独立费率。

共享入口 `ScopedHandlingFeeConfigService::ensureScope()` 复制平台定义的全部等级（包括普通会员 0）；主商户每个等级优先使用所属站点配置，缺少时回退平台。复制原始小数费率，不做百分比换算。已有等级和时间戳保持不变，软删除等级允许重新补建。平台费率必须至少包含等级 0；没有默认配置时开户失败，不编造费率。

`initialize(site, agent, publish)` 在同一 SQL 事务中补缺并仅发布新建等级，严格验证 TK 每项归属、等级、重复项和成功总数。重复保存完整配置不发送 RPC。既有独立费率不会随上级后续修改自动更新。

平台和 Agent 的 `HandlingFeeScopeInitializer` 仅负责适配各自 TK 传输。总后台白标新建/修改/补开户、主商户新建/修改已接入。Agent 本人资料保存中，白标主账号采用首个所属启用站点，商户主账号采用数据库中的站点及自身 ID；子账号不单独初始化。现有权限校验不变，不接收请求覆盖归属。

## 发布前置条件

1. 审核并备份原表；核查旧记录的商户站点归属、有效重复等级、约束和引用依赖。
2. 共享包应包含 `HandlingFeeScope`、`ScopedHandlingFeeConfigService`、`FeeLevelBatch` 和范围化的查询/修改服务，两个宿主使用同一实现。不要只部署宿主而遗漏共享包，否则会出现类不存在。
3. 复用现有 `handling_fee_level_config`：`home_site_id`、`agent_id` 为非空且默认 0，未删除记录按 `(home_site_id, agent_id, level)` 唯一。已有归属约束和唯一索引保持不动，不因名称不同重复创建；上线前核对旧数据归属，本功能不自动回填归属或改表。
4. 验证 TK 当前版本及 `admin`、`agent_admin` 服务身份可调用带范围的 `BatchUpdateFeeLevelConfig`；本次自动测试使用假传输，未连接实际 TK。
5. 在测试环境验证开户、修改补缺、重复保存、跨站拒绝、TK 超时与部分失败后再部署。不要在旧结构上启用新查询（新查询依赖两个归属字段）。

手续费归属迁移、配套 SQL 和迁移专用测试已移除。若其他环境缺少上述结构，需先单独核对，不要直接启用依赖这些字段的业务代码。

## 边界与风险

- SQL 失败能回滚本地账户和费率，无法撤销已经被 TK 接受的项目；接口会提示可能部分生效，必须核对，不承诺分布式原子事务。
- 已有费率完整但 TK 缺配置时，本次不会全量覆盖 TK，需要另行核对/修复；初始化不是对账工具。
- 平台旧模型已固定 `home_site_id=0 AND agent_id=0`，避免平台列表、编辑、删除、重排、VIP 门槛查询和批量同步混入下级。下级费率是初始化快照，不自动跟随上级。已有下级独立费率时，禁止导致原有 VIP 编号变化的插入/门槛重排，并禁止直接删除仍有下级费率的等级；需要专项等级迁移。末尾新增等级仍可进行，下级在下一次保存账户时补缺。
- 本次没有新增白标/商户费率编辑页面或后台缓存。共享查询/修改方法已支持双维度范围；独立费率编辑若后续对外开放，宿主仍须完成操作权限校验和 TK 同步。

## 验证

- 共享：`php tests/scoped_handling_fees.php`、`php tests/run.php`。
- Platform：`HandlingFeeBatchResultTest`、`HandlingFeeScopeInitializerTest`、`AgentAccountCreateTest`、`TenantAccountTest` 的 fee 测试及 VIP 查询回归。
- Agent：`HandlingFeeAccountInitializationTest`。
