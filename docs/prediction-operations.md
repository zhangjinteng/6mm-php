# Prediction Read SDK

Namespace: `SixMm\Shared\Prediction`.

## Responsibilities

- `PredictionPageReader`: shared list pipeline, query-contract gate, optional scope checks before and after display enrichment.
- `UserFilter`: exact UID and literal case-insensitive nickname/username search over a host-supplied scoped Eloquent builder. All OR branches stay nested. Empty matches remain an explicit empty set; more than 500 users is an error, not an unfiltered query.
- `GridOrderQueryContract`: shared raw-query allowlist and validation used by host FormRequests, including duplicate-parameter rejection.
- `GridOrderRepository` / `GridOrderPresenter`: read-only Grid snapshot SQL, scope predicates, exact decimal projection, funding projection, connection timeout and cleanup.
- `ReadResponder` / `GridResponder`: safe structured errors with trace IDs. No exception messages or database details are serialized.

## Trust Boundary

Hosts retain authentication, JWT/login-version checks, route permissions, identity construction, gRPC transport and server-generated identity headers. No client-supplied merchant/site/operator field establishes authority.

Agent passes its original principal to the gateway. `assertMerchant(..., true)` permits the global `agent_id="0"` response only for the rounds endpoint; it does not change the principal or apply to orders. Order responses are rejected in full on a conflicting merchant, including nested scope metadata.

Grid hosts implement `GridScope`. `homeSiteIds`, `isGlobal` and `isCurrent` come from the existing authority. `orderAgentIds()` returns Agent's root/direct-child order owners, `[]` for no owners, or `null` only for an authorized unrestricted owner dimension. The repository filters immutable order ownership as well as authorized sites. User-search matches are an additional intersection, not a replacement. Scope is checked again before returning results and after host label enrichment.

Display labels remain host-enriched: Agent uses its own user scope; Platform keeps source-name/type enrichment. Label lookup never rewrites financial data or order ownership.

## Error Contract

Success: HTTP 200, `{code:1,show:0,msg:"",data:...}`.

Failure: `{code:0,show:1,msg:<safe message>,data:{error_code,trace_id}}`.

HTTP 401 retains authentication failure, 403 permission failure, 404 missing order, 422 invalid query/overbroad user filter, 503 unavailable dependency/scope or incompatible query contract. The legacy Grid `FORBIDDEN` code can carry 401 when the host explicitly indicates login failure. HTTP exception payloads are rebuilt rather than forwarded. Unexpected failures are never reported as successful empty pages.

Configuration/write APIs are outside this read-only migration and retain their existing receipt and retry contracts.

## Verification

Run `php tests/prediction_operations.php`. `PREDICTION_TEST_AUTOLOAD` may point to a host's installed Composer autoloader; the test explicitly loads this checkout's Prediction namespace. Run host HTTP boundary suites in isolated test environments to verify real middleware, principal forwarding, merchant/site intersections, revocation, query wire validation and exact values. Do not run those suites using production environment files.
