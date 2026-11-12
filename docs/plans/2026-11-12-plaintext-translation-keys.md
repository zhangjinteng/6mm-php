# Plaintext Translation Keys Implementation Plan

**Goal:** Store translation Google API keys unchanged in `cms.translation_settings.api_key`, with no encryption/decryption on normal reads or writes.

**Architecture:** Keep the shared settings store and existing platform/merchant/site authorization. Add a nullable plaintext key column. Migration-only code decrypts historical CMS ciphertext using the historical CMS master key, verifies any existing plaintext and clears ciphertext in one transaction; damaged or divergent rows abort. The old merchant migration uses the historical Agent APP_KEY to convert directly into plaintext. Historical encrypted columns remain empty compatibility columns, never plaintext containers. No production migration or release is executed.

**Tech Stack:** PHP/Laravel, PostgreSQL migrations, shared Vue UI, isolated SQLite/PostgreSQL and fake HTTP tests.

## Tasks

1. Write a failing shared-store test requiring unchanged plaintext storage and masked responses without an encryption key.
2. Update shared credential resolution/store and merchant compatibility adapters. Keep blank-input preservation, revision locking, environment fallback only for the existing CMS environment-reference path, and no merchant fallback.
3. Add an idempotent transaction-only ciphertext conversion and separately named Agent/Platform migrations. Convert old merchant credentials directly into plaintext, retaining verification-before-drop safeguards.
4. Correct shared UI copy and annotate host encryption configuration as historical-migration-only. Do not edit real `.env` or expose key values.
5. Test existing ciphertext conversion, bad-master-key rollback, plaintext/ciphertext conflicts, idempotence, masked API responses, scoped access and execution. Run PHP syntax, shared tests, relevant host tests and shared UI tests.
6. Update deployment notes: plaintext increases database/backup exposure; old decryption keys are needed until migration succeeds; new code must not be mixed with older ciphertext writers. No release/installation/live database changes.
