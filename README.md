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

## Shared user asset list

`UserAssetListQueryService` owns the reusable base asset projection, user and
active external-binding lookup, filtering, sorting, and pagination. The host
must provide a `UserDataScope`; an empty scope fails closed. Authentication,
HTTP response envelopes, trade-kernel account/position hydration, market-price
subscriptions, and live position valuation remain application-owned.

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
