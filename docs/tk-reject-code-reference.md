# Trade Kernel 错误码对照表

核对日期：2026-11-16。依据当前工作区 TK 服务端、Proto 和 SDK 重试策略，共 **74 个已定义数值（含成功码 `0`，非零拒绝码 73 个）**。这是一份当前代码对接说明，不代表所有部署环境均已升级到相同版本，也不表示调用身份自动具备表中 RPC 权限。未定义的编号（例如 `2006`、`4011`）不补造含义。

`AccountChange` 的永久防重命中仍返回 `2009`；同请求或异载荷均不再次执行。使用原 ID 调用 `GetAccountChangeResult` 确认首次结果，`UNKNOWN/PENDING` 不构成退款或重投依据。原账户域最终拒绝码保持，详见 [持久回执说明](AccountChange持久回执与对账说明.md)。

`GetUserProfile` 不新增业务拒绝码：不存在资料是 gRPC OK + `found=false`；非法 `user_id`（包括空请求）返回 `InvalidArgument`，恢复或查询依赖不可用返回 `Unavailable`。ACL 沿用 `Unauthenticated`／`PermissionDenied`，旧服务不支持方法时返回 `Unimplemented`。查询结果不能作为资料写入的独立提交证明。

> 本文档完整列出 `internal/core/command/errors.go` 定义的 `reject_code`，包括内部兜底码；并核对 RPC 入口、公共校验、账户／规则处理和结果映射。并非每个 RPC 都返回其中每个码。
> proto 字段名仍为 `reject_code`，数值已升级为 4 位分段码；`message` 仅作兜底说明，API / 前端多语言应优先按 `source=trade_kernel + reject_code` 映射文案。

## 接入原则

- `reject_code=0` 表示当前响应阶段没有业务拒绝。预检通过不等于已经下单；`ACCEPTED`、`PENDING` 或进行中的作业不等于结算／划转全部完成；查询还须判断 `found` 和实际状态。普通写接口的提交边界仍以该 RPC 和部署的 ACK 模式为准。
- `reject_code!=0` 表示业务拒绝；只要 gRPC status 为 OK，上游必须读取响应体或批量 item 内的 `reject_code`。
- `message` 不作为多语言 key，不建议用精确字符串做逻辑判断。
- `RejectInvalidConfig=6002` 已用于经营范围、风险通知等公开 RPC 的业务校验，API / 前端必须保留中文兜底，不能视为不可能出现。
- 新增错误码只追加，不复用旧码，不改变既有码含义。
- 手续费总配置／杠杆档位兼容省略 `command_id`，负值复用 `2001`；手续费白标／代理专属范围要求正值，零值同样返回 `2001`。新版 TK 固定持久化，相同正值 ID 与相同规范化请求返回原结果，不同请求复用 ID 返回 `2009`。总配置及杠杆删除的 `version=0` 保留旧语义，手续费专属范围删除非正版本返回 `6004`；正值不等于当前规则版本返回 `6003`，负值返回 `6004`；详见[配置接入说明](rpc_upstream_integration_guide.md#34-配置与管理接口)。
- 上述配置 RPC 的配置 owner 或持久化依赖不可用时返回 gRPC `Unavailable`；已受理但 WAL 结果未收敛时返回 gRPC `Unknown`。它们不是业务拒绝成功，也不能据此生成新 ID 盲重试；旧无 ID 请求必须先查询确认。批量仍逐项返回结果，SDK 保持 `NO_AUTO_RETRY`。

## 下架返回 7004 的解释

`DelistSymbolTrading` 与 `DelistAndSettleSymbol` 实际请求字段是 **`mark_price` 和 `mark_price_time`**。`invalid settlement_price` 是复用拒绝码的默认英文文案，并不表示这两个 RPC 存在 `settlement_price` 参数；后者是 `SettleFundingRate` 的参数。

| 模式／输入 | 当前处理 |
| --- | --- |
| 两项均省略，等价于 `mark_price=""`、`mark_price_time=0` | TK 自动取得并固定该次操作的标记价及行情源时间。 |
| 两项均提供 | 使用调用方给定价时；价格须为可解析的正数，时间须为正 Unix 毫秒时间戳。 |
| 只提供其中一项、显式价格为 `"0"`／负数／非法格式 | 拒绝。范围下架返回 `7004`；旧全局接口的正价格配非正时间返回 `7003`。 |
| 自动模式下没有行情存储、没有该品种行情、价格非正、行情 `SourceTS <= 0`，或快照过期 | 返回 `7004`，不会因未传价而任选一个旧价继续结算。 |

严格价格解析最多 8 位小数，超出的尾零也会被拒绝，例如 `"1.2300000000"`；可无损规范为 `"1.23"`。不要使用二进制浮点数计算、舍入或传递资金价格。自动模式的过期检查使用 TK **接收时间 `RecvTS`** 与当前时间之差，阈值为 `MarkPriceStaleThreshold`（代码默认 3 秒，现场以实际配置为准）；另要求行情源时间 `SourceTS > 0`，固定的 `mark_price_time` 采用该源时间。

例如管理后台提供的 `SOXSUSDT` 请求仅包含 `command_id`、`symbol`、`reason`、`scope.kind=1` 和 `expected_version`，属于 **PLATFORM 自动取价**。对当前实现，这种请求的 `7004` 表示没有取得有效标记价；单凭响应不能继续区分缺失、过期或无效价时，也不能据此判定经营版本和暂停前置条件已通过（自动取价早于这些业务准备校验）。应核对 TK 接收该品种的标记价链路，而不是要求后台随意补价格。

该自动取价拒绝发生在本次下架准备及 WAL 写入前。若同一业务此前有超时／未知响应，仍应先用原 `command_id` 调用 `GetSymbolTradingOperation` 核对；`found=false` 不是通用的失败证明。行情恢复且原操作结果已明确后，可以原 ID、原载荷受控重发。已经受理的自动操作继续省略价时，TK 复用原固定价；不要把返回的固定价再填回原请求，改变其取价模式。显式价时仅在业务负责人确认结算依据后使用。

## 重试规则及 RPC 缩写

表内“是否可重试”是业务处理建议，**不是让 SDK 自动重试非零 `reject_code`**。当前 Go SDK 收到 gRPC OK 响应即返回，不根据响应体拒绝码再发一次；RPC 重试分类来自根仓 `docs/contracts/tk-sdk/sdk_rpc_manifest.json`。

- 原操作重发：保留同一 `command_id`、同一完整请求、同一外部交易／轮次身份，不能因超时换 ID。对照具体 RPC 的策略与回执能力再决定是否重试。
- “修正／状态改善后再评估”：原样循环不会解决问题。先确认旧请求结果；修改载荷或重新执行业务属于新的操作，应建立新的稳定身份，不能覆盖已经受理的原命令。`AccountChange` 已持久化的最终拒绝不会因余额后来变化而按原 ID 重新执行。
- “先查原结果”：不得仅凭拒绝码、超时、`UNKNOWN`、`PENDING` 或未查到结果，就退款、补记资金成功或再次扣款。尤其 `AccountChange` 使用 `GetAccountChangeResult` 查询原命令及实际变动金额。
- 批量调用逐项确认，保留成功项及各自命令 ID；顶层 gRPC OK 或成功计数不代表全批成功。不要将已成功项换 ID 整批重发。

| SDK 策略 | 含义／处理边界 |
| --- | --- |
| `QUERY_ONLY` | 只读请求可按 SDK 配置重试可恢复的传输错误；查询结果仍按节点应用水位和接口契约解释。 |
| `SAFE_RETRY_BY_COMMAND_ID` | 下单、改单、撤单及批量平仓等保留原命令身份重发；不得由此假设每个业务拒绝均可重试。 |
| `WAL_BOUND_SAFE_RETRY` | 指定 RPC 按原身份和其 WAL／幂等契约重发；最终业务状态仍须单独判断，不能把资金费 `ACCEPTED` 当作全部资金落账。 |
| `ADMIN_RETRY_WITH_CONFIRM` | 下架、范围变更、人工资金调整等默认不自动重试；确认原结果及业务意图后受控重发。 |
| `NO_AUTO_RETRY` | 手续费／杠杆配置批量等默认只调用一次；结果未知时查询确认，不能自行假设库会重试。 |

为便于阅读，下表使用这些完整 RPC 集合的简称：

| 表内简称 | 对应 RPC |
| --- | --- |
| 下单 | `PlaceOrder`、`BatchPlaceOrder`。 |
| 下单／预检 | 上述下单方法及 `ValidatePlaceOrder`；预检不写 WAL、不冻结资金。 |
| 改单 | `AmendOrder`、`BatchAmendOrder`。 |
| 撤单 | `CancelOrder`、`BatchCancelOrder`。 |
| 手续费配置写入 | `UpdateFeeLevelConfig`、`BatchUpdateFeeLevelConfig`、`DeleteFeeLevelConfig`；仅适用于该行触发条件对应的动作。 |
| 杠杆档位配置写入 | `UpdateLeverageMarginConfig`、`BatchUpdateLeverageMarginConfig`、`DeleteLeverageMarginConfig`；仅适用于该行触发条件对应的动作。 |
| 用户身份写入 | 旧 `InitUserAccount`、`UpdateUserProfile`；不是所有带用户字段的新 RPC。 |
| VIP 写入 | `UpdateUserVipLevel`、`BatchUpdateUserVipLevel`。 |
| 经营范围写入 | `InitializeSymbolTradingScope`、`ListSymbolForTrading`、`PauseSymbolTrading`、`ResumeSymbolTrading`、`DelistSymbolTrading`。 |

`CloseUserPositions` 还会逐项返回其生成的市价全平子单拒绝码：它复用批量下单链路，仅能命中符合全平输入与豁免规则的分支；表中单独标出的调用前校验仍适用。不要把所有下单参数错误都解释成后台可向这个 RPC 传入对应字段。`DebugLiquidatePosition` 是受权限控制的调试方法，本表列出不表示建议业务方调用。

`GetAccountChangeResult` 等结果查询可携带原执行拒绝码，含义与原写操作相同；查询接口自身的参数、权限和就绪错误可能走 gRPC。其他无 `reject_code` 字段的查询接口也可能映射成 gRPC 状态，详见文末，不能从本表反推它们一定返回数值拒绝码。

## 分段说明

| 分段 | 分类 | 说明 |
| --- | --- | --- |
| `0` | 无业务拒绝 | 仍需结合接口响应阶段与最终状态。 |
| `1000-1999` | 系统错误 | 服务不可用、内部错误、背压等。 |
| `2000-2999` | 通用请求 / 参数错误 | 缺少公共字段、字段超长、幂等冲突等。 |
| `3000-3999` | 交易业务错误 | 价格、数量、订单类型、盘口、改单、下单规则等。 |
| `4000-4999` | 账户 / 资金错误 | 账户、余额、金额、用户身份、VIP 等。 |
| `5000-5999` | 仓位 / 保证金 / 风险错误 | 杠杆、保证金模式、持仓、挂单、强平接管等。 |
| `6000-6999` | 配置 / 规则错误 | 交易对、手续费、杠杆保证金档位、配置版本等。 |
| `7000-7999` | 清结算 / 资金费率错误 | 资金费轮次、费率、结算价、结算时间等。 |

## 详细对照表

| Code | 常量 | 中文含义 | 触发条件 | 适用 RPC／链路 | 是否可重试 | 建议处理方式 |
| --- | --- | --- | --- | --- | --- | --- |
| `0` | `RejectNone` | 当前请求阶段无业务拒绝 | 写入、只读预检、异步受理或结果查询均可能为 0；不等于所有后续业务均完成。 | 所有带 `reject_code` 的响应／批量项；结果查询中的原执行结果 | 不需重投；异步业务继续查询 | 结合 `status`、`valid`、`found`、`operation` 和批量项判断；`ACCEPTED`／进行中继续查询。 |
| `1000` | `RejectInternalError` | 内部／兜底错误 | 内部异常、算术溢出或未细分业务拒绝；当前 `AccountChange` 全部划出被持仓／挂单阻挡也映射为该码。 | 公共写入链路；下单／预检；配置写入；`AccountChange` | 先对账／排查；不盲重试 | 保存 RPC、原请求和 command_id，交 TK 排查；`AccountChange` 先查永久回执，不能把所有 1000 都当作瞬时故障。 |
| `1001` | `RejectServiceUnavailable` | 服务不可用 | Dispatcher、账户或交易对路由暂不可用。 | 公共 Dispatcher／账户／交易对路由；下单、改单、撤单及资金写入 | 条件重试；先确认原结果 | 核对就绪、路由和恢复状态；仅按 RPC 重试策略复用原请求，资金／管理操作先查原结果。 |
| `1002` | `RejectServiceBusy` | 服务繁忙 | 队列或 ring 背压。 | 公共 Dispatcher／队列；`InitUserAccount`、`UpdateUserProfile`、`CreditContractFromFunding` | 条件重试；退避并确认原结果 | 限流退避，避免放大流量。 |
| `2001` | `RejectMissingCommandID` | 命令 ID 缺失或非法 | 通常为 `command_id <= 0`；手续费总配置／杠杆配置允许 0，负值仍拒绝；专属手续费范围要求正值。 | 要求命令身份的写入 RPC；下单预检；`GetSymbolTradingOperation`（配置写入例外见接入原则） | 否；修正请求后再发起 | 使用稳定正整数 ID，JSON 中建议以字符串传递避免精度损失；重发同一操作保持 ID 和载荷。 |
| `2002` | `RejectMissingUserID` | 用户 ID 缺失或非法 | 要求用户身份的命令中 `user_id <= 0`。 | 用户维度的下单、改单、撤单、账户资金写入、身份写入、VIP 写入、用户杠杆／保证金调整 | 否；修正请求后再发起 | 核对真实用户 ID；系统级结算／下架按各自契约，无需补造用户。 |
| `2003` | `RejectMissingSymbol` | 缺少 `symbol` | 交易对必填接口未传交易对。 | 下单／预检、改单、撤单；`CloseUserPositions`、`AdjustUserSymbolLeverage`、`AdjustUserSymbolMarginMode`、`AdjustIsolatedPositionMargin`、`ApplyFundingFee`、`SettleFundingRate`、`DelistAndSettleSymbol`、`UpdateSymbolConfig`、杠杆档位配置写入、`DebugLiquidatePosition` | 否；修正请求后再发起 | 修正请求参数。 |
| `2004` | `RejectMissingOrderID` | 缺少 `order_id` | 撤单、改单或强平请求缺少订单标识。 | 撤单、改单；`DebugLiquidatePosition`；内部批量订单载荷校验 | 否；修正请求后再发起 | 传 `order_id` 或按接口约定传 `client_order_id`。 |
| `2005` | `RejectMissingTransactionID` | 缺少 `transaction_id` | 充值、提现、账户变动缺少外部交易 ID。 | `Deposit`、`Withdraw`、`AccountChange` | 否；修正请求后再发起 | 修正请求，保证外部幂等 ID 稳定。 |
| `2007` | `RejectFieldTooLong` | 字段超长 | 例如 symbol 超过 32 字节、client_order_id 超过 64 字节、transaction_id／funding_round_id 超过 128 字节；其他字段按各接口上限。 | 下单／预检、改单、撤单、资金写入、用户身份写入、`ConvertUserType`、`SettleFundingRate`、`DelistAndSettleSymbol` 等经过公共长度校验的 RPC | 否；修正请求后再发起 | 按字节上限修正；不要截断业务 ID 后造成碰撞。 |
| `2008` | `RejectInvalidCommandType` | 命令类型非法 | 内部命令类型未识别。 | 公共命令分发（内部兜底；公开 RPC 不让调用方指定内部命令类型） | 否；交 TK 排查 | 通常为内部问题，告警排查。 |
| `2009` | `RejectDuplicateCommand` | 命令重复或请求身份冲突 | 同一 command_id 已占用／执行，或同 ID 请求指纹不同；部分接口同请求直接回原成功结果，AccountChange 重复仍为 2009。 | 有命令去重／请求指纹的写入 RPC；`AccountChange`、经营范围写入、配置写入、Funding 划转、`UpdateRiskNotificationConfig` | 先查原结果；禁止换 ID 重投 | 按原 command_id 查询；核对首次请求指纹和结果，不把重复直接当成功或失败，不生成新 ID 绕过去重。 |
| `3001` | `RejectInvalidPrice` | 价格非法 | 价格字符串不可解析、超出固定精度／范围，或要求正价格时非正。 | 下单／预检、改单；`DebugLiquidatePosition` | 否；修正请求后再发起 | 修正对应价格字段；严格价格解析最多 8 位小数，超出的尾零也会拒绝。 |
| `3002` | `RejectInvalidQuantity` | 数量非法 | 数量格式非法、数量非正、改单新总数量不合法。 | 下单／预检、改单；`DebugLiquidatePosition` | 否；修正请求后再发起 | 修正数量字段。 |
| `3003` | `RejectInvalidSide` | 方向非法 | `side` 不是 BUY / SELL。 | 下单／预检；`DebugLiquidatePosition` | 否；修正请求后再发起 | 修正枚举值。 |
| `3004` | `RejectInvalidOrderType` | 订单类型非法 | 订单类型不支持，或 BBO 与非 LIMIT 订单组合冲突。 | 下单／预检 | 否；修正请求后再发起 | 按 RPC 要求使用 LIMIT／MARKET；BBO 只用于支持的限价模式。 |
| `3005` | `RejectInvalidTimeInForce` | `time_in_force` 非法 | 非空且不是 `GTC` / `IOC` / `FOK`。 | 下单／预检 | 否；修正请求后再发起 | 修正枚举值。 |
| `3006` | `RejectMakerOnlyConflict` | `maker_only` 与市价单冲突 | 市价单传入 `maker_only=true`。 | 下单／预检 | 否；修正请求后再发起 | 取消 `maker_only` 或改为限价单。 |
| `3007` | `RejectMinQuantity` | 数量低于最小下单量 | `qty < min_quantity`。 | 下单／预检、改单 | 否；修正请求后再发起 | 按交易对规则调整数量。 |
| `3008` | `RejectStepSize` | 数量不满足步长 | `qty` 不是 `step_size` 的整数倍。 | 下单／预检、改单 | 否；修正请求后再发起 | 按步长对齐数量。 |
| `3009` | `RejectMinNotional` | 名义价值低于最小值 | 非只减仓单的 `price * qty < min_notional`；只减仓下单、全平及原只减仓订单的改单豁免。 | 下单／预检、改单 | 否；修正请求后再发起 | 普通单按规则调整；平仓须正确传 `reduce_only=true` 或 `close_all=true`，最小数量与步长仍须满足。 |
| `3010` | `RejectMaxLimitOrderQty` | 限价数量超过上限 | 受该规则约束的 LIMIT 数量超过 max_limit_order_qty；只减仓、强平及有效做市商的豁免按现有实现。 | 下单／预检、改单 | 否；修正请求后再发起 | 调整数量并核对生效规则；改单按新总数量校验。 |
| `3011` | `RejectMaxMarketOrderQty` | 市价数量超过上限 | 受该规则约束的 MARKET 数量超过 max_market_order_qty；只减仓、强平及有效做市商的豁免按现有实现。 | 下单／预检 | 否；修正请求后再发起 | 降低数量，确认原请求未成交后再决定新的订单。 |
| `3012` | `RejectSlippageRange` | 滑点不在允许区间 | 市价单未传必需滑点，或滑点低于 / 高于交易对配置范围。 | 下单／预检 | 否；修正请求后再发起 | 按交易对 `min_slippage/max_slippage` 调整。 |
| `3013` | `RejectBBODepthInsufficient` | 对手深度不足 | BBO 取价或市价单参考价没有足够对手盘。 | 下单／预检 | 行情恢复后受控重试 | 等待盘口恢复或改用限价。 |
| `3014` | `RejectOrderNotFound` | 订单不存在或已终态 | 撤单 / 改单目标订单不存在、已成交、已撤或标识不匹配。 | 撤单、改单 | 先查订单；不盲重试 | 查询订单状态，避免重复操作。 |
| `3015` | `RejectDuplicateClientOrderID` | `client_order_id` 冲突 | 用户活跃挂单中已存在相同 `client_order_id`。 | 下单／预检 | 先查原订单；不盲重试 | 复用原订单查询，或生成新的业务订单 ID。 |
| `3016` | `RejectMaxOpenOrdersExceeded` | 挂单数超过上限 | 用户在该交易对的活跃挂单数达到限制。 | 下单 | 释放挂单额度后再评估 | 核对当前挂单与生效限额，由业务方决定撤单或调整配置，不自动扩权。 |
| `3017` | `RejectAmendNotSupported` | 不支持改单 | 非活动 LIMIT + GTC 普通挂单，或订单状态不支持改单。 | 改单 | 否；先查原订单状态 | 查询订单状态后再决定是否重新下单。 |
| `3018` | `RejectAmendWouldCross` | 改单会立即成交 | `maker_only` 改价后会跨盘口成交。 | 改单 | 否；修正请求后再发起 | 调整价格，确保仍为 maker。 |
| `3019` | `RejectMarkPriceMissing` | 标记价缺失或过期 | 价格保护、逐仓保证金安全减少或身份转换估值需要标记价，但当前标记价缺失或不可用。 | 下单／预检、改单；`AdjustIsolatedPositionMargin`、`ConvertUserType` | 有效行情恢复后受控重试 | 等待行情恢复，或检查标记价源。 |
| `3020` | `RejectPriceProtectExceeded` | 价格超过标记价保护范围 | 下单 / 改单价格超出标记价比例区间。 | 下单／预检、改单 | 否；修正请求后再发起 | 按返回文案或交易对规则调整报价。 |
| `3021` | `RejectInvalidPriceType` | `price_type` 非法 | BBO 价格类型不是 `0-4`。 | 下单／预检 | 否；修正请求后再发起 | 修正 BBO 枚举。 |
| `3022` | `RejectInvalidPlaceType` | `place_type` 非法 | 公共 RPC 伪造强平来源或传入非法来源。 | 下单／预检 | 否；修正请求后再发起 | 普通上游只能传普通 / 仓位来源。 |
| `3023` | `RejectInvalidAmendPayload` | 改单 payload 非法 | 批量改单 item 为空，或 `price/qty` 都未传。 | 改单 | 否；修正请求后再发起 | 补齐改价或改量字段。 |
| `3024` | `RejectInvalidSlippage` | 滑点格式非法 | `slippage` 不是合法十进制比例。 | 下单／预检；`CloseUserPositions` | 否；修正请求后再发起 | 修正滑点格式。 |
| `3025` | `RejectInvalidOrderPayload` | 下单 payload 非法 | 批量下单 item 为空、内部订单载荷字段组合非法，或 `close_all=true` 携带非零 `side/qty`、`maker_only=true`、BBO 等冲突字段；`BatchPlaceOrder` 的限价全平本次也明确返回该码。 | 下单／预检；`CloseUserPositions`、`DebugLiquidatePosition` | 否；修正请求后再发起 | 修正下单请求结构；单笔限价全平使用明确正价格且 `price_type=0`。 |
| `4001` | `RejectAccountNotFound` | 账户不存在 | 账户尚未初始化，或开户路径无法取得有效账户状态。 | `InitUserAccount`、`AccountChange`、`ApplyFundingFee`、`AdjustIsolatedPositionMargin`、`ConvertUserType`、`CreateContractToFundingTransfer`；改单 | 开户／状态修复后再评估 | 先调用开户 / 初始化流程。 |
| `4002` | `RejectInsufficientBalance` | 可用余额或转换权益不足 | 冻结、提现、扣款、增加逐仓保证金、调杠杆或划转不满足可用资金约束；身份转换也可能因转换权益非正拒绝。 | 下单、改单；`Withdraw`、`AccountChange`、`AdjustUserSymbolLeverage`、`AdjustIsolatedPositionMargin`、`ConvertUserType`、`CreateContractToFundingTransfer` | 余额／权益改善后再评估 | 按对应账户／仓位核对可用资金；已持久拒绝不能靠原 ID 再扣一次，后续业务须另行确认。 |
| `4003` | `RejectInvalidAmount` | 金额非法 | 金额格式或方向约束不满足：充值／提现／指定额划转及保证金调整要求正值；AccountChange 普通调整和 ApplyFundingFee 要求非零；全部划出按各自契约。 | `Deposit`、`Withdraw`、`AccountChange`、`ApplyFundingFee`、`AdjustIsolatedPositionMargin`、`CreditContractFromFunding`、`CreateContractToFundingTransfer` | 否；修正请求后再发起 | 不要统一强制所有金额大于 0；按 RPC 正负语义、是否全部划出和固定精度修正。 |
| `4004` | `RejectInvalidChangeType` | 账户变动类型非法 | `AccountChange.change_type` 不在支持范围。 | `AccountChange` | 否；修正请求后再发起 | 修正账户变动枚举。 |
| `4005` | `RejectUserProfileNotFound` | 用户身份资料不存在 | 真实 / 虚拟转换找不到本地身份快照。 | `ConvertUserType` | 身份同步后再评估 | 先同步用户身份资料或核对用户初始化流程。 |
| `4006` | `RejectInvalidUserType` | `user_type` 非法 | 用户类型不是普通、虚拟、做市商等受控值。 | 用户身份写入；`ConvertUserType` | 否；修正请求后再发起 | 修正用户类型。 |
| `4007` | `RejectInvalidAgentID` | `agent_id` 非法 | 代理归属为负数，或真实 / 虚拟互转缺少代理归属。 | 用户身份写入；`ConvertUserType`、`CreditContractFromFunding` | 否；修正请求后再发起 | 修正代理归属。 |
| `4008` | `RejectInvalidProfileVersion` | 身份版本非法 | profile_version／expected_profile_version 非正；版本与当前值不匹配通常走 6003。 | 用户身份写入；`ConvertUserType` | 否；修正请求后再发起 | 从身份权威源获取合法版本；更新版本和 expected_version 的含义不同，不直接套用加一。 |
| `4009` | `RejectInvalidVIPLevel` | `vip_level` 非法 | VIP 等级为负数。 | VIP 写入 | 否；修正请求后再发起 | 修正 VIP 等级。 |
| `4010` | `RejectInvalidFundingTransferID` | 划转身份非法或不存在 | Funding 入账要求 funding_transfer_id > 0；Contract → Funding 内部 ID／结果处理也复用此码。 | `CreditContractFromFunding`；Contract → Funding 的内部划转命令／回执处理 | 否；修正请求后再发起 | Funding 入账使用 Asset 权威正整数划转 ID；Contract → Funding ID 由 TK 生成，不让后台任意补造。 |
| `4012` | `RejectUnsupportedAsset` | 资产不支持 | 现有 Funding ↔ Contract 划转路径的资产不是 USDT。 | `CreditContractFromFunding`、`CreateContractToFundingTransfer` | 否；修正请求后再发起 | 按当前接口资产范围传值；不要通过改资产名称重投同一资金操作。 |
| `5001` | `RejectInvalidLeverage` | 杠杆非法 | 杠杆小于等于 0。 | `AdjustUserSymbolLeverage`、`AdjustIsolatedPositionMargin`；`DebugLiquidatePosition` | 否；修正请求后再发起 | 修正杠杆值。 |
| `5002` | `RejectLeverageOutOfRange` | 杠杆超出档位范围 | 用户杠杆或非只减仓原订单锁定杠杆超过当前持仓对应的最新档位上限；账户本地可恢复的有效做市商身份豁免下单／改单此项动态准入，不限倍数。 | 下单、改单；`AdjustUserSymbolLeverage` | 否；修正请求后再发起 | 非豁免用户的新单查询档位后调整杠杆；改单仍使用原订单杠杆，必要时撤单后重新下单。独立调杠杆 RPC 的原上限规则不变。 |
| `5003` | `RejectInvalidMarginMode` | 保证金模式非法／不适用 | 保证金模式不在全仓／逐仓枚举内，或逐仓保证金调整目标实际不是逐仓仓位。 | `AdjustUserSymbolMarginMode`、`AdjustIsolatedPositionMargin`；`DebugLiquidatePosition` | 否；修正请求后再发起 | 核对请求枚举和当前仓位模式，不只检查参数拼写。 |
| `5004` | `RejectPositionExists` | 持仓阻挡当前操作 | 调杠杆／切换模式受到现有持仓约束，或禁止带仓转换时虚拟用户带仓转真实用户。 | `AdjustUserSymbolLeverage`、`AdjustUserSymbolMarginMode`、`ConvertUserType` | 业务前置条件满足后再评估 | 先核对 RPC 前置条件，再由用户决定处理持仓；不得自动平仓。AccountChange 全部划出的同类阻挡当前为 1000。 |
| `5005` | `RejectOpenOrdersExist` | 挂单或资金占用阻挡操作 | 调杠杆／切换模式受到活跃订单、冻结或占用约束。 | `AdjustUserSymbolLeverage`、`AdjustUserSymbolMarginMode` | 业务前置条件满足后再评估 | 查询订单和资金占用后处理，不自动撤单。AccountChange 全部划出的同类阻挡当前为 1000。 |
| `5006` | `RejectReduceOnlyPosition` | 只减仓位不足 | `reduce_only` 无可减持仓或可减数量不足；`PlaceOrder.close_all=true` 时表示账户串行受理时无仓位，或当前仓位已被既有同向只减仓挂单全部占用；`CloseUserPositions.symbols` 为空时也表示该用户当前无非零仓位。 | 下单／预检、改单；`CloseUserPositions` | 持仓／占用变化后再评估 | 调整数量、撤销已有只减仓挂单或确认当前持仓。 |
| `5007` | `RejectPositionLiquidating` | 仓位处于强平接管 | 普通下单、保证金调整或身份转换与强平接管状态冲突。 | 下单；`AdjustIsolatedPositionMargin`、`ConvertUserType` | 强平处理结束后再评估 | 等待强平结束并重查仓位；不要用更换 ID 绕过接管。 |
| `5008` | `RejectPositionNotFound` | 指定持仓不存在 | `position_id` 与当前用户、交易对或持仓生命周期不匹配。 | `AdjustIsolatedPositionMargin` | 否；重查当前持仓 | 重新查询当前持仓，禁止用旧持仓 ID 重试。 |
| `5009` | `RejectInvalidMarginOperation` | 逐仓保证金调整方向非法 | `operation` 不是 `ADD` 或 `REDUCE`。 | `AdjustIsolatedPositionMargin` | 否；修正请求后再发起 | 修正 proto 枚举。 |
| `5010` | `RejectMarginBelowInitial` | 权益低于标记价初始保证金 | `REDUCE` 后 `margin_after + unrealized_pnl(mark)` 低于 `mark_price × remaining_qty ÷ current_leverage`。 | `AdjustIsolatedPositionMargin` | 否；减少金额或改善风险后再评估 | 降低减少金额或等待浮盈改善。 |
| `5011` | `RejectMarginRiskLimit` | 触及逐仓风险线 | `REDUCE` 后 `margin_after` 低于当前维持保证金，或权益不再严格高于当前维持保证金。 | `AdjustIsolatedPositionMargin` | 否；减少金额或改善风险后再评估 | 降低减少金额或等待风险改善。 |
| `5012` | `RejectOpenCrossDeficit` | 存在 OPEN 全仓欠损 | OPEN 全仓欠损期间禁止继续把钱包资金转入逐仓保证金。 | `AdjustIsolatedPositionMargin` | 欠损处理后再评估 | 先处理全仓欠损。 |
| `6001` | `RejectInvalidSymbol` | 交易对不可交易 | symbol 不存在／未启用，或当前用户不具备该交易对经营准入（暂停、下架、未登记范围、非法非空归属等）。空站点自动补齐不等于授予开仓许可；平台做市商仍受平台限制。 | 下单／预检、改单、撤单；`AdjustUserSymbolLeverage`、`AdjustUserSymbolMarginMode`、`ApplyFundingFee`、`SettleFundingRate`、`DelistAndSettleSymbol`、`UpdateSymbolConfig`、`DebugLiquidatePosition` | 状态／配置修复后再评估 | 核对基础交易对状态和 `GetUserSymbolConfig.trading_access`，不要仅按“symbol 拼写错误”处理。 |
| `6002` | `RejectInvalidConfig` | 配置、范围或状态迁移非法 | 经营范围字段／模式非法，操作不满足当前状态迁移；风险通知、转换策略、身份／VIP／站点配置也可能复用兜底码。 | 经营范围写入；`ListSymbolTradingConfigs`、`UpdateRiskNotificationConfig`、`UpdateUserConvertConfig`、用户身份写入、VIP 写入、`CreateContractToFundingTransfer`；下单、改单的配置／归属兜底 | 否；核对参数、配置及状态 | 这是实际可返回的业务码，应提供中文兜底；范围操作先查询本级及上级状态，不应无限重试或一律重新初始化。 |
| `6003` | `RejectConfigVersionStale` | 版本过旧或期望版本不匹配 | 更新版本未超过当前／墓碑版本；删除版本不等于当前行；经营 expected_version 或身份转换期望版本与当前值不符。 | 经营范围写入；`UpdateSymbolConfig`、手续费配置写入、杠杆档位配置写入、VIP 写入、用户身份写入、`ConvertUserType`、`UpdateRiskNotificationConfig`；内部划转版本核对 | 否；重查版本，不能原样循环 | 先查询：更新用更高的新版本；删除／经营 expected_version 使用读到的当前版本。确认旧操作结果后再提交新操作。 |
| `6004` | `RejectInvalidConfigVersion` | 配置版本非法 | 更新要求正版本；经营 expected_version 仅负值非法，首次可为 0；总手续费／杠杆删除 version=0 兼容旧语义，专属手续费删除要求正版本。 | 经营范围写入；`UpdateSymbolConfig`、手续费配置写入、杠杆档位配置写入、VIP 写入、`UpdateRiskNotificationConfig`；内部划转版本核对 | 否；修正请求后再发起 | 按对应 RPC 的更新版本／期望版本规则修正，不能一律填 0 或一律加一。 |
| `6005` | `RejectLeverageMarginNotFound` | 杠杆保证金档位不可用 | 目标配置档位不存在，或普通开仓／改单、逐仓保证金调整所需的有效档位不能匹配。 | 下单、改单；`UpdateLeverageMarginConfig`、`BatchUpdateLeverageMarginConfig`、`DeleteLeverageMarginConfig`、`AdjustIsolatedPositionMargin` | 有效档位修复后再评估 | 查询并修复档位；下单／改单的只减仓和有效做市商动态准入豁免仍保留。精确查询 GetLeverageMarginConfig 的未找到是 gRPC NotFound。 |
| `6006` | `RejectFeeLevelNotFound` | 手续费等级不可用 | 删除目标不存在，或下单／用户类型转换无法取得所需手续费配置。 | 下单；`DeleteFeeLevelConfig`、`ConvertUserType` | 有效费率配置修复后再评估 | 核对用户等级和代理 → 白标 → 平台的生效配置；GetFeeLevelConfig 精确查询未找到返回 gRPC NotFound，而非响应体 6006。 |
| `6007` | `RejectInvalidSymbolConfig` | 交易对配置非法 | `UpdateSymbolConfig` 字段格式、范围或组合非法。 | `UpdateSymbolConfig` | 否；修正请求后再发起 | 修正交易对配置 payload。 |
| `6008` | `RejectInvalidLeverageConfig` | 杠杆保证金配置非法 | 档位、名义区间、维持保证金率 / 金额等非法。 | 杠杆档位配置写入 | 否；修正请求后再发起 | 修正杠杆保证金档位配置。 |
| `6009` | `RejectInvalidFeeLevelConfig` | 手续费等级配置非法 | 手续费等级或范围字段非法。 | 手续费配置写入 | 否；修正请求后再发起 | 修正等级、规范站点字符串及代理归属范围。 |
| `6010` | `RejectInvalidFeeRate` | 手续费率非法 | maker／taker 比例字符串解析失败、为负、定点溢出或转换后的 ppm 超过范围；当前严格解析最多 8 位小数。 | `UpdateFeeLevelConfig`、`BatchUpdateFeeLevelConfig` | 否；修正请求后再发起 | 传比例而非百分数，例如 0.0002；0.0002000000 有 10 位小数，当前会拒绝，可在保证数值不变时规范为 0.0002。 |
| `7001` | `RejectMissingFundingRoundID` | 缺少资金费轮次 ID | `SettleFundingRate.funding_round_id` 为空。 | `SettleFundingRate` | 否；修正请求后再发起 | 生成稳定轮次 ID。 |
| `7002` | `RejectInvalidFundingRate` | 资金费率非法 | funding_rate 不能按兼容比例解析器精确表示；0、正数、负数均允许，超精度非零尾数不允许。 | `SettleFundingRate` | 否；修正请求后再发起 | 按比例字符串提供费率；不要把允许的负费率误判为错误。 |
| `7003` | `RejectInvalidFundingTime` | 结算时间非法 | SettleFundingRate 的 funding_time <= 0；旧 DelistAndSettleSymbol 显式正价格搭配非正 mark_price_time。 | `SettleFundingRate`、`DelistAndSettleSymbol` | 否；修正请求后再发起 | 提供 Unix 毫秒时间；下架自动取价必须同时省略 mark_price 和 mark_price_time。范围下架的同类价时错误为 7004。 |
| `7004` | `RejectInvalidSettlementPrice` | 结算价格或价时依据无效 | 资金费 settlement_price 非法／非正；下架 mark_price 非法、价格和时间只填一项、范围下架时间非正；自动下架无有效新鲜标记价。 | `SettleFundingRate`、`DelistSymbolTrading`、`DelistAndSettleSymbol` | 显式价时：修正；自动取价：行情恢复后受控重试 | 先区分 RPC 和取价模式。自动模式检查 TK 标记价、SourceTS 和接收新鲜度；显式模式修正成对价时。不要填 0／页面展示价来绕过；已受理或未知先查原操作。 |

## API / 前端映射建议

推荐 API 对前端返回统一结构时保留来源：

```json
{
  "code": 4002,
  "source": "trade_kernel",
  "message": "insufficient balance"
}
```

前端或 API 多语言 key 可以按以下形式维护：

```text
error.trade_kernel.4002 = 余额不足
error.trade_kernel.3020 = 价格超过标记价保护范围
error.trade_kernel.7004 = 结算价格或价时依据无效
```

如果后续 API 还需要跨服务统一错误码，建议保留 `source` 或使用 `source + code` 作为命名空间，避免不同服务的 4 位码互相冲突。

下架自动模式可显示“当前交易对暂无有效标记价，暂不能下架结算，请检查行情后重试”；显式模式可显示“下架价格或价格时间无效，请检查”。日志仍保留原 `reject_code`、`message`、RPC、取价模式与命令 ID，不把推测的“行情过期”写成唯一原因。

## gRPC 错误与业务拒绝必须分开

| gRPC 状态 | 中文含义／常见场景 | 建议处理 |
| --- | --- | --- |
| `InvalidArgument` | 参数非法；部分查询和新接口不使用旧 rejectCode。 | 修正参数，不原样循环。 |
| `Unauthenticated`／`PermissionDenied` | 服务身份缺失、RPC 未授权、作用域或归属不允许；`method_not_allowed` 属于此类。 | 核对 `x-tk-service` 和实际 ACL，不通过更换业务参数绕过。 |
| `NotFound` | 精确查询的配置／资源不存在。 | 按该查询语义处理；不是资金操作未执行的通用证明。 |
| `AlreadyExists` | 请求身份冲突或已有记录。 | 核对原请求和结果，不换 ID 盲重试。 |
| `FailedPrecondition` | 服务、业务状态或客户端契约前置条件不满足。 | 排查具体 message 和状态，满足前提后再处理。 |
| `Aborted` | 原操作在执行中、并发冲突等。 | 查询原操作，按该 RPC 约定保留身份处理。 |
| `Unavailable`／`ResourceExhausted` | 未就绪、连接／路由异常、容量／背压；某些写路径也用 Unavailable 表示结果未知。 | 读取具体上下文，写入先对账；允许重试时有界退避。 |
| `DeadlineExceeded`／`Canceled`／`Unknown` | 调用超时、取消或提交结果不明确。 | 不能等同“未执行”；查询原身份，禁止自动退款或换 ID 再扣。 |
| `Unimplemented` | 目标版本不支持该 RPC。 | 核对版本与路由；不能静默退回作用域更大的旧写接口。 |
| `Internal`／`DataLoss` | 内部异常或持久事实与索引不能收敛。 | 保留原命令和证据，交 TK 排查，不无限重试。 |

例如 `GetFeeLevelConfig`／`GetLeverageMarginConfig` 精确查询未找到是 gRPC `NotFound`；`GetUserProfile` 未找到是 gRPC OK 且 `found=false`。并不存在“所有查询没数据都返回 4001／6006”的统一规则。

## 主子跨钱包新增接口

新统一响应使用 `result_code` 字符串，不能因没有旧 `reject_code` 就判断成功。`NOT_FOUND`／`UNKNOWN` 不可作终态，只有 durable、RECEIPT、完整原请求相同且终态才可处理。参数或未知字段为 InvalidArgument，身份／查询归属错误为 PermissionDenied，冲突为 AlreadyExists，未就绪为 Unavailable，新源关闭等未持久拒绝为 FailedPrecondition，WAL 结果不明为 Unknown；这些 gRPC 错误均不是退款证据。业务持久拒绝记录继续可查，细节见[主子跨钱包说明](主子跨钱包划转接入说明.md)。不新增或改义旧 RejectCode 枚举。

## 核验来源

- [拒绝码唯一枚举及默认文案](../internal/core/command/errors.go)、[公共命令校验](../internal/core/command/validator.go)、[RPC 错误映射](../internal/logic/errors.go)。
- [RPC 定义](../trade_kernel.proto)、[RPC 接入说明](rpc_upstream_integration_guide.md)、[SDK 路由及重试策略](../../docs/contracts/tk-sdk/sdk_rpc_manifest.json)。
- [范围下架入口](../internal/logic/symbol_trading.go)、[自动取标记价](../internal/svc/delist_auto_price.go)、[行情新鲜度](../internal/core/rules/mark_price_store.go)。
- [AccountChange 持久回执](AccountChange持久回执与对账说明.md)、[实际拒绝持久化映射](../internal/core/account/account_change_receipt.go)、[配置查询返回方式](../internal/logic/getconfiglogic.go)。
