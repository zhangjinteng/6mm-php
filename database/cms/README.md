# Shared CMS integration

The CMS domain implementation is `src/Cms`. Platform controllers retain their legacy classes as compatibility adapters; white-label controllers must bind every operation with `withTenantSpace()` to a server-resolved site space. Translations use `new TranslationService($connection, [$spaceId])`.

## Database and permissions

- Hosts use the same PostgreSQL CMS schema. Existing CMS migrations are in the platform host (`2026_10_28_000001_create_cms_content_tables` and `2026_10_28_000002_unify_academy_categories`); this change does not execute them.
- Navigation entries alone only grant list access. Review and run `wl-action-permissions.sql` after creating content navigation. It adds action definitions, not role grants. Tenant owners see available action definitions; assign restricted roles explicitly. Re-login after changing permissions.
- Site discovery is read-only. A user with a content-create permission can explicitly initialize an empty site space. No platform templates are automatically copied.
- Feedback attachments must use the private upload disk shared with the user-facing feedback uploader. Configure `CMS_FEEDBACK_ATTACHMENT_DISK` appropriately; never use `public`.

## Jobs

The platform remains the single scheduler owner. Its existing `cms:publish-*` commands now include enabled site spaces as well as the default template. Its `cms:translate-work` processes the shared translation queue when enabled. Do not schedule a second worker on the agent host against the same queue without an intentional deployment decision.

Manual translations do not require Google credentials. Machine translation and connection tests use the host's `cms_translation` configuration and `GOOGLE_TRANSLATE_API_KEY`; testing this integration does not call Google.

## Local development dependencies

Both PHP hosts currently use a Composer path repository to this checkout (`1.0.87` compatibility version). Both Vue hosts use the locally packed `6mm-ui/work-gpt-6mm-ui-1.0.99.tgz`, including the `@work-gpt/6mm-ui/cms` entry. These are local integration references, not published releases. For deployment without sibling repositories, publish reviewed packages and replace the local references first.

## Verification

- Platform `tests/Integration/Cms` runs only against a disposable localhost database via `CMS_MIGRATION_TEST_DSN`; fixtures roll back.
- Agent `tests/Feature/WhiteLabelCmsAccessTest.php` uses in-memory SQLite and verifies every CMS route rejects foreign, template and disabled spaces.
- Shared UI `src/cms/runtime.test.ts` verifies immutable site request bindings, old-adapter invalidation and read-only discovery.
