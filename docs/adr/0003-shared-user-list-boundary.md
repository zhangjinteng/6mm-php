# ADR 0003: Shared user-list query boundary

- Status: Accepted
- Date: 2026-08-10

## Context

Agent and platform administration applications need the same user-list filters,
projection, aggregate sorting, and pagination. Their authentication, authorized
agent scope, IP enrichment, and trading-position providers remain different.

## Decision

The package owns `UserListQuery` and `UserListQueryService`. Every search
requires a host-provided `UserDataScope`. The query accepts optional included or
excluded internal platform user IDs so the host can apply application-specific
filters before count and pagination without moving those integrations into the
shared package.

The host owns authentication, route wiring, response envelopes, IP enrichment,
and retrieval of the included or excluded user IDs.

## Consequences

- Shared filters and aggregate sorting stay consistent across administration
  applications.
- Empty explicit include lists and empty data scopes fail closed.
- ClickHouse or trading-kernel clients remain replaceable host integrations.
- A compatible package release must be installed before a host switches its
  endpoint to the shared query service.
