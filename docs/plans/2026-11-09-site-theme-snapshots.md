# Site theme snapshots implementation plan

**Goal:** Persist the approved site configuration JSON through one shared writer, called by both hosts, with mandatory initialization on white-label creation/edit.

**Architecture:** ThemeSnapshotWriter reads trusted tenant/site-scoped source data on the caller's connection and appends immutable, hashed theme versions inside the caller's transaction. ConfigSnapshotWriter delegates after publication; SEO/channel/compliance source saves delegate directly. Account creation uses publication; edits and owner provisioning synchronize the first/selected owned site. No user-supplied payload or credentials are accepted.

**Tech Stack:** PHP 8.2+, Illuminate Database, PostgreSQL; SQLite isolated tests.

1. Add shared payload builder/writer; stable JSON ordering, numeric site ID string, explicit code mappings and null optional settings. Per user clarification legal documents include usePlatformTemplate and the published custom URL (null for inline content without a URL). Test mapping, isolation, idempotence, version chain, checksum and rollback.
2. Delegate from existing config publication and source saves, preserving customer-service lifecycle. Use the existing publication sequence; reuse a publication version only when greater than the prior theme version.
3. Wire Platform tenant create/edit/provision and Agent tenant-account create/edit through the shared writer without accepting tenant/site overrides.
4. Extend test fixtures, run shared and host tests against isolated SQLite, inspect scoped diffs. Do not publish dependencies or touch application databases.
