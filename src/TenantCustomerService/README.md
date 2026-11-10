# 6MM customer service

`CustomerService` owns manual provisioning and bindings; `CustomerLifecycleService` owns durable open/close confirmation; `BusinessConfigStore` owns business entitlements and full-snapshot publication. Platform and white-label hosts supply `CustomerServiceContext`, not duplicated lifecycle implementations.

- The fixed upstream endpoint is server controlled. Do not accept URLs, merchant IDs, customer tenant IDs, keys, or actor identity from browser input.
- `customer_service` remains a **business** module in `wl_site_modules`; never put it into the ordinary frontend-feature catalogue.
- A service change affects every site of one tenant. Other modules, tenants, domains, policies, empty objects and snapshot extensions are preserved.
- HTTP runs outside database transactions. Durable claims, cooldown, bounded retries, version checks and confirmed-result reuse prevent concurrent or uncertain writes from becoming optimistic success.
- An unresolved action may only be explicitly verified in the same direction. No automatic write retries. Local publication failure after upstream confirmation must reuse confirmation.
- Mandatory host audit callbacks run transactionally. Audit only sanitized operation metadata. Never log request/response bodies, HTTP exceptions, passwords or keys.
- White-label `updated_by` must not reference a platform administrator ID; new rows use NULL, existing platform attribution is preserved. Snapshot `published_by` distinguishes actor namespaces.
- `CUSTOMER_SERVICE_EXCHANGE_API_KEY` is server-only. New customer bindings store `provider: "6mm"` and the upstream `appKey` directly in `wl_channel_configs.customer_service`, per the requested storage contract. This intentionally removes at-rest protection for that key; restrict database/backup access accordingly. Keys remain excluded from admin responses, audit logs and access snapshots. The explicitly requested theme projection exposes the saved appKey as config.channels.support.publicWidgetId only while customer service is enabled and bound; closed/unconnected states emit null. Theme history therefore contains widget keys.
- Legacy `provider: "sixmm"` / `app_key_encrypted` records remain readable. Keep the original shared `CUSTOMER_SERVICE_ENCRYPTION_KEY` (AES-256-CBC; Platform falls back to its APP_KEY) until all legacy bindings have been converted. A successful customer write normalizes the provider, saves `appKey`, and removes the ciphertext; a read does not migrate data. An unreadable legacy key fails closed without logging its value.
- New bindings and existing plaintext bindings do not require an encrypter. Keep the original fingerprint key for unresolved manual provisioning retries; new installations fall back to the server exchange API key as the HMAC key. Changing either key during an unresolved request can invalidate its retry fingerprint.
- Hosts must authorize before calling the store. White-label endpoints derive tenant ID from the authenticated principal and expose only customer-service toggle methods, never unrestricted business publication.

No database migration is needed. Existing customer bindings, module flags and operation state are reused.

Tests: `php tests/tenant_customer_service.php`; full host suites cover timeout, race, rollback, matching close responses and own-tenant authorization with fake HTTP.
