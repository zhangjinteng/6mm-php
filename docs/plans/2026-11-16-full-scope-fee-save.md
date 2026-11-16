# Full-scope fee save implementation plan

**Goal:** Every new create/update sends all active VIP tiers owned by the authenticated scope in one BatchUpdateFeeLevelConfig call.

**Architecture:** Build a scope snapshot merged with the submitted tier, persist all item command IDs and versions, verify every response item and counts, then atomically update local rows and their TK versions. Existing pending payloads remain immutable. Deletes retain DeleteFeeLevelConfig. Partial or unknown outcomes remain pending.

**Tech Stack:** PHP 8.2+, shared 6mm-php, Laravel, Composer, SQLite offline fixtures.

1. Cover platform/site/merchant full batches, more than 20 tiers, ordinary-member omitted protobuf values, mismatched/duplicate/partial results, exact retries, and SQL finalization.
2. Update HandlingFeeConfigManager snapshot preparation and local batch application; use canonical ownership in FeeLevelBatch validation. Keep legacy pending commands replayable.
3. Update existing CRUD mocks and host endpoint assertions; run affected shared and host tests using isolated stores.
4. Build a new local package excluding unrelated untracked work, upgrade agent/platform, verify installed code and lockfiles. Do not send live TK requests.
