# ADR 0004: Optional user-list recommendation and trading actions

- Status: Accepted
- Date: 2026-08-10

## Context

Administration applications need an optional recommendation filter and
cancel-all-orders / close-all-positions actions on their user lists. Agent and
Platform use different authentication, permissions, confirmations, and trading
service clients.

## Decision

`UserListQuery` accepts an optional `agentId` and applies it only after the
mandatory host-provided `UserDataScope`. `UserTradingActionService` resolves a
public UID within that same scope and delegates the internal platform user ID to
a host-provided `UserTradingActionGateway`.

The package does not implement confirmation prompts, permissions, RPC clients,
transactions, or response envelopes.

## Consequences

- Recommendation filtering cannot widen the caller's authorized data scope.
- Trading actions cannot target a user outside the supplied scope.
- Applications opt in by providing filter options and/or a gateway.
- Existing list and detail consumers remain source-compatible.
