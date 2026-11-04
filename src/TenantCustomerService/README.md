# 6MM customer service

`CustomerService` owns manual provisioning and encrypted bindings; `CustomerLifecycleService` owns durable open/close confirmation; `BusinessConfigStore` owns business entitlements and full-snapshot publication. Platform and white-label hosts supply `CustomerServiceContext`, not duplicated lifecycle implementations.

- The fixed upstream endpoint is server controlled. Do not accept URLs, merchant IDs, customer tenant IDs, keys, or actor identity from browser input.
- `customer_service` remains a **business** module in `wl_site_modules`; never put it into the ordinary frontend-feature catalogue.
- A service change affects every site of one tenant. Other modules, tenants, domains, policies, empty objects and snapshot extensions are preserved.
- HTTP runs outside database transactions. Durable claims, cooldown, bounded retries, version checks and confirmed-result reuse prevent concurrent or uncertain writes from becoming optimistic success.
- An unresolved action may only be explicitly verified in the same direction. No automatic write retries. Local publication failure after upstream confirmation must reuse confirmation.
- Mandatory host audit callbacks run transactionally. Audit only sanitized operation metadata. Never log request/response bodies, HTTP exceptions, passwords or keys.
- White-label `updated_by` must not reference a platform administrator ID; new rows use NULL, existing platform attribution is preserved. Snapshot `published_by` distinguishes actor namespaces.
- `CUSTOMER_SERVICE_EXCHANGE_API_KEY` is server-only. Both hosts must use the **same** `CUSTOMER_SERVICE_ENCRYPTION_KEY` (AES-256-CBC) to read existing bindings. For existing installations, this must be the original platform APP_KEY, not a newly generated value. Platform defaults to its existing APP_KEY for backward compatibility. Agent requires an explicit shared key and fails closed if missing.
- Hosts must authorize before calling the store. White-label endpoints derive tenant ID from the authenticated principal and expose only customer-service toggle methods, never unrestricted business publication.

No database migration is needed. Existing customer bindings, module flags and operation state are reused.

Tests: `php tests/tenant_customer_service.php`; full host suites cover timeout, race, rollback, matching close responses and own-tenant authorization with fake HTTP.
