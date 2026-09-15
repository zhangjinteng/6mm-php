# ADR 0005: Shared hedging execution records with explicit data scopes

- Status: Accepted
- Date: 2026-09-15

## Context

Agent Admin and Platform Admin need the same hedging execution-record query and
table. Agent Admin must only expose records owned by the authenticated primary
agent, including when a child administrator is logged in. Platform Admin needs
an all-agent view and an optional exact-agent filter.

## Decision

`6mm-php` owns the execution query criteria, joins, filters, pagination, option
lists, and row projection. Every query requires a host-provided `UserDataScope`;
there is no unscoped default. Agent Admin passes `AgentIdsScope` after resolving
the primary agent from the authenticated administrator. Platform Admin passes
`AllUsersScope` when no agent is selected and `ExactAgentScope` when `agent_id`
is supplied.

`6mm-ui` owns the common execution-record table and accepts an injected request
function. Agent selection and the agent column are opt-in presentation features.
The host applications retain routes, authentication, permissions, response
envelopes, agent-name enrichment, and date/symbol rendering adapters.

## Consequences

- Agent Admin keeps its existing route and authenticated-agent isolation.
- Platform Admin can list all records or filter one agent without duplicating
  query and table behavior.
- Hiding the agent filter is not an authorization boundary; server-side scope
  selection remains mandatory.
- Both applications must install compatible `6mm-php` and `6mm-ui` releases
  before using the shared implementation in deployment.
