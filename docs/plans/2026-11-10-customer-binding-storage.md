# Customer binding storage change

User-requested contract: persist provider as 6mm and upstream appKey as plaintext in wl_channel_configs.customer_service. No release, dependency upgrade, or live data migration.

1. Centralize provider/key compatibility in the shared customer-service implementation. Accept legacy sixmm/encrypted rows on reads, normalize them only during an authenticated write. New bindings store appKey and remove app_key_encrypted. A missing encryption key must not block new plaintext bindings.
2. Preserve legacy decryption keys for existing records and HMAC retry fingerprints; use the exchange API key as the fingerprint fallback for new installations. Preserve authorization, remote endpoint, retry/claim and local publication transactions.
3. Keep keys out of public DTOs, errors, audit records and snapshots. Neither endpoint accepts caller-supplied provider/key. Do not add channels.support to theme snapshots in this change.
4. Verify shared lifecycle and both host endpoints using isolated SQLite and fake HTTP, including legacy conversion, plaintext operation without an encrypter, malformed/failed responses, namespace isolation and rollback. Existing source changes are user-owned and must be preserved.
