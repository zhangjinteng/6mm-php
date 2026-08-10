# 6mm PHP

Shared PHP contracts, query objects, and domain services used by the 6MM administration applications.

## Install

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "git@git-hub:zhangjinteng/6mm-php.git"
    }
  ],
  "require": {
    "zhangjinteng/6mm-php": "^0.2"
  }
}
```

Authentication, routes, permissions, and application-specific data-scope resolution remain in each application.

## Shared condition-order list

`ConditionOrderListQueryService` owns the relational condition-order query,
active external-binding lookup, identity filters, pagination, generated-order
fill quantity, and current-versus-historical user-type semantics. The caller
selects one `ConditionOrderKind` (`CONDITION` or `TP_SL`) and one
`ConditionOrderLifecycle` (`CURRENT`, `HISTORY`, or `ALL`) and must provide an
explicit `UserDataScope`.

`ConditionOrderKind::ALL` is retained only for backward-compatible host
endpoints that historically returned both kinds when no kind filter was sent;
new UI presets should select `CONDITION` or `TP_SL` explicitly.

Current rows use `users.user_type`; historical rows use the snapshot stored on
`condition_orders.user_type`. Rows expose the public UID as `user_id` and retain
the internal ID as `platform_user_id`. Authentication, HTTP envelopes, product
category enrichment, translated display labels, detail navigation, and cancel
RPC calls remain application-owned.

## Shared liquidation-record user context

`LiquidationListQuery` normalizes the reusable liquidation filters, pagination,
time boundaries, and allowed sorting fields. `LiquidationUserContextService`
matches public UID, username, preferred name, and active external bindings inside
an explicit `UserDataScope`, then hydrates source rows with public identity while
rejecting rows outside the authorized scope. A valid historical `user_type`
snapshot remains authoritative and the current user profile is only a fallback.

ClickHouse aggregation, product-category symbol expansion, fee calculations,
detail/trade-order endpoints, authentication, and HTTP envelopes remain owned by
the host application.

## Shared trade-fill user context

`TradeFillListQuery` normalizes trade-fill pagination, filters, time boundaries,
and allowed sorting fields. `TradeFillUserContextService` resolves scoped users,
matches public UID, username, preferred name, and active external bindings, then
hydrates ClickHouse rows with public identity while rejecting rows outside the
explicit `UserDataScope`. A valid historical `user_type` remains authoritative;
the current user profile is only used as a fallback.

ClickHouse SQL, order/position history joins, product-category enrichment,
authentication, detail drawers, and HTTP envelopes remain host-owned.

## Shared current-position user context

`CurrentPositionListQuery` normalizes the reusable current-position filters,
pagination, and allowed sorting fields. `CurrentPositionUserContextService`
resolves platform users inside an explicit `UserDataScope`, matches public UID,
username, preferred name, and active external bindings, then hydrates source
position rows with public identity and account balances while dropping rows
outside the authorized scope.

The position source itself remains application-owned: ClickHouse SQL, market
subscriptions, gRPC position enrichment, TP/SL relations, close-position
commands, authentication, and HTTP envelopes are not dependencies of the
shared package.

## Shared current-order user context

`CurrentOrderListQuery` normalizes cursor pagination and current-order filters
before a host calls its trading source. `CurrentOrderUserContextService`
resolves current users inside an explicit `UserDataScope`, supports public UID,
username, preferred-name, and active external-binding lookup, and hydrates
trading-source rows with public identity while rejecting out-of-scope rows.

The open-order source, cursor encoding, authentication, product-category
enrichment, confirmation dialogs, and cancel commands remain application-owned.

## Shared history-position user context

`HistoryPositionListQuery` normalizes reusable history-position filters,
pagination, statuses, time boundaries, and allowed sorting fields.
`HistoryPositionUserContextService` resolves users inside an explicit
`UserDataScope`, matches public UID, username, preferred name, and active
external bindings, and hydrates source rows with public identity while dropping
rows outside the authorized scope. A valid `history_position_query.user_type`
snapshot remains authoritative; `users.user_type` is used only as a fallback.

ClickHouse SQL, fee and trigger-mode enrichment, product categorization,
authentication, HTTP envelopes, detail drawers, and trade-record navigation
remain application-owned.

## Shared history-order user context

`HistoryOrderListQuery` normalizes reusable history-order pagination,
terminal-status, filter, time, and sorting inputs before a host calls its
history source. `HistoryOrderUserContextService` resolves users inside an
explicit `UserDataScope`, creates scope-safe current user-type fallback IDs,
and preserves `history_order_query.user_type` while hydrating public and
external user identities.

ClickHouse SQL, condition-order relations, product categorization,
authentication, HTTP envelopes, and detail drawers remain application-owned.

## Shared account change log list

`AccountChangeLogListQueryService` owns the reusable account-change projection,
identity and active external-binding joins, filtering, stable cursor pagination,
and historical `user_type` fallback. The host must provide a `UserDataScope`;
an empty scope fails closed. Rows expose the public UID as `user_id`, the
internal platform user ID as `platform_user_id`, and use the log snapshot
`user_type` before falling back to the current user type.

Authentication, HTTP response envelopes, local-time to UTC conversion, product
category enrichment, and business-specific detail actions remain
application-owned.

## Shared user asset list

`UserAssetListQueryService` owns the reusable base asset projection, user and
active external-binding lookup, filtering, sorting, and pagination. The host
must provide a `UserDataScope`; an empty scope fails closed. Authentication,
HTTP response envelopes, trade-kernel account/position hydration, market-price
subscriptions, and live position valuation remain application-owned.

`UserAssetListQuery::agentId` optionally filters a recommendation relationship,
including platform users whose agent ID is `0`. Asset rows expose the public UID
as `user_id` and the internal application user ID as `platform_user_id`; hosts
should use the latter for balance operations such as deposit and deduction.

## Shared user list

`UserListQueryService` owns the reusable user-list projection, filtering,
pagination, aggregate sorting, and active external-binding lookup. The host must
pass an explicit `UserDataScope`; it can additionally provide included or
excluded internal platform user IDs for application-owned filters such as
current ClickHouse positions. `UserListQuery::agentId` optionally narrows the
authorized scope to one recommendation relationship, including platform users
whose agent ID is `0`. Authentication, HTTP response envelopes, IP enrichment,
and position lookup remain application-owned.

## Shared user detail

`UserDetailQueryService` resolves a user by public UID and requires the host
application to provide an explicit `UserDataScope`. It returns the common user
identity/login projection plus the internal platform user ID needed by the host
to query its own trading services. Authentication, IP enrichment, live account
data, and HTTP response envelopes remain application-owned.

## Optional user trading actions

`UserTradingActionService` resolves a public user UID inside the supplied
`UserDataScope`, then calls a host implementation of
`UserTradingActionGateway` with the internal platform user ID. The gateway owns
the actual cancel-all-orders and close-all-positions RPC calls, transactions,
logging, and error mapping. Applications that do not need these actions do not
implement or instantiate the gateway.

```php
$actions = new UserTradingActionService(
    new UserDetailQueryService(DB::connection()),
    $applicationTradingGateway
);

$accepted = $actions->cancelAllOrders($publicUserId, $scope);
```
