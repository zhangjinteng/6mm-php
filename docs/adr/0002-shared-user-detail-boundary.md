# ADR 0002: Shared user-detail boundary

- Status: Accepted
- Date: 2026-08-10

## Context

Agent and platform administration applications need the same user-detail dialog,
but authenticate administrators differently and obtain current account, position,
and order data from application-owned services.

## Decision

The package owns lookup by public user UID, the common user projection, active
external-binding selection, and mandatory `UserDataScope` enforcement. The host
application owns authentication, routes, IP enrichment, and live trading-data
aggregation. The UI package consumes the normalized response without knowing the
host's authorization or transport details.

## Consequences

- Both administration applications can share the same identity lookup safely.
- An empty or mismatched scope fails closed.
- Live trading integrations remain replaceable per host application.
- The internal platform user ID is available to host code but is not included in
  the public detail projection.
