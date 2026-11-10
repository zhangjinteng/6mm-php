# Theme support projection

User-approved contract: both hosts share ThemeSnapshotWriter; config.channels.support contains provider "6mm" and publicWidgetId from the authenticated tenant's saved appKey. User confirmed both fields must become null when customer service is switched off.

- Gate the projection on the site's business customer_service flag, recognized 6MM provider, bound status and valid plaintext appKey. Missing/invalid/legacy ciphertext-only sources emit null. Never project exchange API keys, encryption blobs or admin passwords.
- The existing ConfigSnapshotWriter delegation covers both host lifecycle toggles in their publication transaction. Also delegate from manual customer binding persistence so direct binding updates refresh the same projection.
- Validate open/close/resume, foreign tenant isolation, missing keys, pending/unsupported states, idempotence, and rollback/retry using SQLite and fake HTTP. No production writes, migration, dependency upgrades or release.
- This explicitly exposes appKey in theme payloads and immutable history; ordinary administrative responses/logs remain redacted.
