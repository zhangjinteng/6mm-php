# Shared Prediction Configuration

The `SixMm\Shared\Prediction\Configuration` namespace provides shared validation and response policy for prediction configuration. It does not construct an authenticated identity or select an authorized merchant/site.

## Entry Points

- `ConfigurationActor` exposes the trusted operator ID, agent ID and trace ID from a host-created context.
- `ConfigurationResponder::platform`, `::owner` and `::site` normalize success and failure envelopes. Host RPC exceptions are adapted to `ConfigurationFailure` at the transport boundary.
- `OwnerConfigValidator` and `SiteConfigValidator` check returned targets, effective configuration, receipt identity and original request IDs. A malformed write response is not accepted as success.
- `PlatformConfigRules` preserves platform input validation.
- `GridConfigInput`, `GridLayoutInput`, `GridConfigException` and `GridConfigResponder` preserve shared decimal-string, command and receipt rules. Hosts retain compatibility aliases for the existing class names.

## Security And Recovery

Authentication, permission middleware, merchant/site authorization, transport identity metadata and database transactions remain in the host applications. Do not build `ConfigurationActor` from request-supplied operator or agent IDs.

Internal transport details are not returned for unknown exceptions. Unknown writes return an outcome-unknown error, retaining the original request ID for recovery. Host `HttpResponseException` is rethrown so authorization denials keep their existing status. Protocol-defined public errors and control states retain their compatible envelopes.

No table or schema migration is required by this extraction. Grid repositories, authorization and transaction ownership are not moved into the shared package.

## Verification

Run `tests/prediction_configuration.php` using an installed host Composer autoloader, and the host prediction/grid configuration suites with isolated SQLite/mock transport bootstraps. Do not run test commands against a production `.env`.
