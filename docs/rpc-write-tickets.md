# Platform / Agent 统一写 RPC 小票

## 范围

只记录写调用，查询（包括走 TK 写节点的查询）不创建小票、不访问小票 Redis。原有应用日志保持原样。

共享实现位于 `SixMm\Shared\RpcTickets`。宿主通过 `GrpcOptions::forService()` 将原生 `CallInvoker` 注入客户端；现有 Funding 日志 invoker 继承共享 `RecordedUnaryCall`，保留原日志。已接入 TK 主入口、手续费、费率初始化、交易对生命周期、预警配置、预测管理、条件单、TronPay、Funding 和对冲客户端。

RPC 请求、metadata、响应和重试策略不变，不新增通用重发或自动对账接口。TK 使用 SDK 方法策略分类，不按节点地址判断写入。其他服务使用显式写方法表；新增方法需同步核对策略及客户端接入。

## 格式

根字段固定：`schema_version`、`kind`、`id`、`operation_id`、`attempt_no`、`application`、`service`、`method`、`service_identity`、`context`、`metadata`、`scope`、`command_ids`、`endpoint`、`request`、`response`、`rpc_state`、`grpc_code`、`business_codes`、`error`、`business_managed`、`started_at`、`finished_at`、`duration_ms`、`business_completed_at`、`expires_at`。

- `application` 为 `platform` 或 `agent`，`kind=rpc_write`，`schema_version=1`。
- Protobuf、数组字段名统一为 snake_case，64 位 ID/版本和十进制金额保持字符串。
- 每次实际尝试单独一张小票；同应用、同方法、相同命令及完整请求关联同一 `operation_id`，不同请求不合并。没有命令号时不假设可幂等重放。
- 鉴权 metadata 不入库；只保留 trace、操作人、服务身份等白名单字段。密码、token、密钥字段脱敏。小票中的请求仅供审计，不作为通用重放载荷。
- 请求超过 2 MiB 在发送前拒绝；回执超过 1 MiB 保存长度和摘要。异常只保存类型、代码，不保存可能泄密的异常全文。

## 状态与保留

`prepared`：已保存、准备发送；`confirmed`：RPC/已知业务回执确认；`rejected`：明确拒绝；`partial`：批量部分成功；`unknown`：超时、异常、缺失回执或仅受理尚未完成。

- 明确拒绝：保留 7 天。
- 普通写 RPC 的确认回执：保留 7 天。它只说明 RPC 已确认，不擅自说明后续业务 SQL 已完成。
- 未知、部分成功、发送后未收尾：不设 TTL。
- 同原命令的确认重试可为以前未知的尝试补充完成确认，保留原 `rpc_state` 与回执，不伪造原调用成功。
- 手续费使用显式 `OperationContext` 关联已有业务操作，RPC 成功后也不设 TTL，待本地提交及 VIP 重算均完成后再保留 7 天。
- 未确认的其他业务可由各自可靠的核对/收尾流程调用 `WriteJournal::completeOperation()`，不能仅因时间过去或猜测执行成功而确认。

发送前写小票失败则不发送写 RPC。发送后的结果存储失败不改变实际 RPC 返回值、不诱发额外重发：原 prepared/unknown 记录保留，并记录不含请求秘密的存储告警。

## Redis

复用两端 `handling_fee_operations` 独立连接，默认同一 Redis 服务 2 号库。通用小票前缀 `rpc-write:{tickets}:v1`，可用 `RPC_WRITE_TICKET_PREFIX` 配置，两端应一致。

键：`:ticket:<id>` 保存小票；`:operation:<hash>:tickets` 与 `:count` 保存尝试关联；`:pending` 索引待确认记录。不得将本库配置为普通缓存库、执行 FLUSHDB/FLUSHALL 或启用淘汰；应持久化并备份。不新增数据库表。

没有新增面向用户的小票查询接口。以后开放查询时必须按可信登录身份校验 application、站点、商户与用户归属，不能把全局 pending 索引直接返回给商户。

## 验证

- `php tests/rpc_write_tickets.php`
- `php tests/rpc_ticket_native.php`（有宿主 gRPC 依赖时用假原生 call 验证，不发送网络请求）
- `RPC_TICKET_TEST_REDIS_SOCKET=/tmp/<owned>/redis.sock php tests/rpc_ticket_redis.php`（仅独立 socket-only 临时 Redis）
- 两端 `RpcWriteTicketsTest`；原手续费、条件单、资金日志、TK SDK 接入回归。
