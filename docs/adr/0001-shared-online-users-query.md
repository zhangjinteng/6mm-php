# ADR 0001: Shared online-user query boundary

- Status: Accepted
- Date: 2026-08-08

## Context

The agent and platform administration applications display the same online-user business data but use different authentication, routing, permissions, and data scopes. Copying controllers between the applications would couple shared query behavior to one application's authorization model.

## Decision

The package owns the online-user query object, pagination result, database filtering, sorting, and row projection. The host application must resolve authentication and pass an explicit `UserDataScope` implementation. HTTP controllers, response envelopes, IP enrichment, and routes remain in the host application.

The online-user query is exposed separately from the general user-list query so it does not execute unrelated wallet, volume, PnL, or ClickHouse aggregation logic.

## Consequences

- Agent and platform applications can share the same query behavior without sharing authentication code.
- An empty agent scope fails closed and returns no rows.
- Schema changes to the shared `users` and `agent_user_bindings` fields require a compatible package release.
- The package does not own database migrations; schema ownership remains with the applications.
