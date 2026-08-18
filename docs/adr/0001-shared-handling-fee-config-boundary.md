# ADR 0001: Shared handling-fee configuration boundary

- Status: Accepted
- Date: 2026-08-18

## Context

Platform Admin and Agent Admin need the same handling-fee configuration table and edit experience. Their authentication, permissions, HTTP routes, cache invalidation, operation logs, and gRPC synchronization are different. Agent-owned rows are currently read-only while platform rows (`agent_id = 0`) are writable.

## Decision

`6mm-ui` provides one composite `MmHandlingFeeConfig` component. It owns the table, owner filter, add/edit dialog, delete dialog, form validation, loading states, and platform-versus-agent read-only presentation. The host injects a list request and optional create, update, delete, detail, and create-default callbacks. Missing callbacks mean that capability is unavailable.

`6mm-php` provides the normalized list criteria, database query/projection service, create-default/detail reads, and `HandlingFeeConfigWriteGuard`. Host applications retain controllers, authorization, transactions, cache invalidation, audit logging, and gRPC synchronization.

## Consequences

- Platform Admin and Agent Admin render the same UI without sharing application routes.
- Agent Admin can use the component in query-only mode.
- Server-side write authorization remains mandatory; hiding UI actions is not a security boundary.
- Hosts adapt their existing response envelope to the component's small typed contract.

## Alternatives considered

1. Put Platform HTTP URLs and permission directives in the component. Rejected because Agent Admin would inherit Platform-specific infrastructure.
2. Share only the table and leave every dialog in each host. Rejected because the requested edit interaction would immediately diverge again.
3. Move cache and gRPC side effects into `6mm-php`. Rejected because those integrations are deployment-specific and do not belong in a reusable domain/query package.
