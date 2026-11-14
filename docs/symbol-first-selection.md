# 交易对生命周期：严格上级检查与首次选品

后台采用比 TK 首次选品能力更严格的规则：

- 白标操作要求平台本地配置已上架、交易开启。
- 代理操作还要求所属白标的本地有效配置已上架、交易开启。
- 上述检查适用于上架、暂停、恢复、下架；上级暂停也阻止下级操作。
- 上架操作允许已登记、正版本范围的首次选品：本层 selected=false、state/effectiveState=UNSPECIFIED、canOpen=false、reason=NOT_SELECTED，且 blockingScope 明确指向本层。
- 只有上架操作可使用此例外，暂停、恢复和下架不放行未选中状态。未知枚举与合法零值分开解析。
- 原有已下架重新上架、已暂停恢复及父级代次失效重新选品，仍受严格上级检查约束。
- 查询失败、未知枚举及异常回执不提交本地状态变更。

首次选品仍需通过上述严格上级检查，再发送 ListSymbolForTrading；使用本层当前范围版本和稳定命令号，完成回执必须匹配。TK 最终检查实际经营条件，确认前不改本地开关。

运行 php tests/symbol_trading_lifecycle.php 验证。SIXMM_TEST_AUTOLOAD 可指定后台 vendor/autoload.php，验证实际安装的依赖。测试使用内存 SQLite 和模拟 RPC。
