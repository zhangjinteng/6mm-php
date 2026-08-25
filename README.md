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

## Shared prediction configuration

`Prediction\PredictionPlatformTemplateService` is the application-facing
gateway for platform prediction templates. It delegates protocol transport,
metadata, request construction, and response mapping to
`zhangjinteng/6mm-prediction`, while keeping Laravel configuration, HTTP
validation, authorization, logs, and translated errors inside each host.

```php
use SixMm\Shared\Prediction\PredictionPlatformTemplateService;

$service = new PredictionPlatformTemplateService(
    timeoutMicroseconds: (int) config('prediction.grpc_timeout'),
    target: (string) config('prediction.grpc_host'),
    token: (string) config('prediction.grpc_token'),
    tls: (bool) config('prediction.grpc_tls'),
);

$template = $service->getTemplate((string) $operatorId);
```

Platform and agent applications should depend on `6mm-php`; they do not need
to import generated protobuf classes or instantiate gRPC stubs directly.

## Shared product-category catalog

`ProductCategorySnapshot` defines the versioned Redis payload shared by the
platform and agent applications. It normalizes contract symbols and stable
category codes, carries Chinese and English display names, validates a content
hash, and builds the reverse category-to-symbol index used by list filters.

Applications implement `ProductCategorySnapshotProvider`; the platform owns
database publication and the agent remains read-only. `ProductCategoryResolver`
then provides symbol lookup, reverse lookup, dynamic category options, and row
enrichment through `product_category` and `product_category_name`. Unknown
symbols remain unclassified and are never assumed to be cryptocurrency.

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

## Shared liquidation-trade list

`LiquidationTradeListQueryService` owns the ClickHouse query that groups fills by
position and liquidation order, stable pagination, fee/notional aggregation,
host-timezone formatting, and fail-closed agent scoping. Applications provide a
`LiquidationTradeQueryExecutor`, resolve the authorized agent IDs, and keep
authentication and HTTP response envelopes application-owned.

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

`CurrentPositionListQuery::largeContract()` and
`CurrentPositionListQuery::liquidationWarning()` expose optional large-contract
and liquidation-warning filter intents. Both default to `false`. The host
application remains responsible for resolving configured thresholds, current
market prices, risk-position sources, and source-specific predicates used to
select large or liquidation-warning positions.

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

## Shared fee commission list

`FeeCommissionListQueryService` owns the reusable relational commission
projection, public/external identity joins, filtering, stable sorting, counting,
and pagination. The host must provide an explicit `UserDataScope`; an empty
scope fails closed. Identifiers such as trade, order, and position IDs are
serialized as strings so JavaScript clients retain their exact values.

When relational snapshots are incomplete, an application can inject a
`FeeCommissionTradeDetailProvider`. This keeps ClickHouse connections and SQL
inside each host while allowing the shared service to return one consistent row
contract. Authentication, local-time conversion, count caching, HTTP envelopes,
agent commission configuration, and business cell navigation remain
application-owned.

## Shared handling-fee configuration

`HandlingFeeConfigListQuery` normalizes the owner and pagination inputs.
`HandlingFeeConfigQueryService` returns the stable threshold-ordered projection,
keeps agent owners isolated, exposes previous-threshold values across pages, and
provides owner-scoped detail and next-level reads. The default owner is the
platform configuration (`agent_id = 0`).

```php
use SixMm\Shared\HandlingFees\HandlingFeeConfigListQuery;
use SixMm\Shared\HandlingFees\HandlingFeeConfigQueryService;
use SixMm\Shared\HandlingFees\HandlingFeeConfigUpsertService;
use SixMm\Shared\HandlingFees\HandlingFeeConfigWriteGuard;

$service = new HandlingFeeConfigQueryService(DB::connection());
$result = $service->search(new HandlingFeeConfigListQuery(
    agentId: $resolvedAgentId,
    page: (int) request('page_no', 1),
    pageSize: (int) request('page_size', 20),
));

$guard = new HandlingFeeConfigWriteGuard();
$guard->assertAllows($config->agent_id);

$upsert = new HandlingFeeConfigUpsertService(DB::connection());
$row = $upsert->upsertAgentLevel(
    agentId: $authenticatedMainAgentId,
    level: $platformFallbackRow->level,
    makerFeeRate: $databaseMakerRate,
    takerFeeRate: $databaseTakerRate,
);
```

The upsert service copies every missing platform tier before the first agent edit,
then updates the selected level transactionally. Maker and Taker rates cannot be
lower than the platform rate for the same level, lower than the next tier, or
higher than the previous tier. Rates use database decimal units (for example,
`0.0002` means `0.02%`).

The host must resolve `$resolvedAgentId` and `$authenticatedMainAgentId` from its
authenticated scope. Controllers, authorization, percentage display conversion,
cache invalidation, audit logs, gRPC synchronization, and HTTP response envelopes
remain application-owned. See
[`docs/adr/0001-shared-handling-fee-config-boundary.md`](docs/adr/0001-shared-handling-fee-config-boundary.md).

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
whose agent ID is `0`. Authentication, HTTP response envelopes, and position
lookup remain application-owned. IP enrichment can be composed from the shared
IP location module described below.

## Shared user detail

`UserDetailQueryService` resolves a user by public UID and requires the host
application to provide an explicit `UserDataScope`. It returns the common user
identity/login projection plus the internal platform user ID needed by the host
to query its own trading services. Authentication, live account data, and HTTP
response envelopes remain application-owned.

## Shared IP location

`IpLocations\IpInfoService` owns IP normalization, public/private
classification, batch IPinfo lookup, canonical response shaping, and separate
success/failure cache lifetimes. Host applications provide an
`IpLocationCache`, normally through `LaravelIpLocationCache`, and read the
IPinfo settings from their own environment.

Multiple cache misses use IPinfo's official `/batch` endpoint in chunks of up
to 1,000 IPs. Plans without Batch access fall back to sequential single-IP
requests. Transport errors, rate limits, and provider failures return a
temporary `unavailable` result without writing a negative cache entry; explicit
per-IP empty responses retain the configured failure cache lifetime.

The canonical result contains `ip`, `kind`, `country_code`, `country`, `region`,
`region_code`, `city`, and `timezone`. `kind` is one of `resolved`, `private`,
or `unavailable`.

When `zhangjinteng/6mm-addr` is installed, the service automatically enriches
resolved locations with `country_names`, `region_names`, and `city_names` maps.
Each map contains `en` and `zh`; missing Chinese names fall back to English.
Applications without the optional package keep the original response shape.

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
