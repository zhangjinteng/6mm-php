# Shared CMS integration

The CMS domain implementation is `src/Cms`. Platform controllers retain their legacy classes as compatibility adapters; white-label controllers must bind every operation with `withTenantSpace()` to a server-resolved site space. Translations use `new TranslationService($connection, [$spaceId])`.

## Database and permissions

- Hosts use the same PostgreSQL CMS schema. Existing CMS migrations are in the platform host (`2026_10_28_000001_create_cms_content_tables` and `2026_10_28_000002_unify_academy_categories`); this change does not execute them.
- Navigation entries alone only grant list access. Review and run `wl-action-permissions.sql` after creating content navigation. It adds action definitions, not role grants. Tenant owners see available action definitions; assign restricted roles explicitly. Re-login after changing permissions.
- For the four content modules, the agent's `php artisan cms:sync-white-label-permissions` previews the 28 category/content actions; `--apply` installs missing definitions transactionally through shared `WhiteLabelActionPermissions`. Existing IDs, grants and disabled/deleted definitions are preserved. Missing/ambiguous parent pages abort the installation. It does not install translation permissions or grant ordinary roles. Owners and shared super-role accounts inherit active definitions as before.
- Shared UI `cmsPermissionItems` / `cmsPermissionSubmission` preserve separately selectable list access when a CMS page has action children. Choosing a page never silently grants its write actions; choosing an action includes the ancestor page.
- Site discovery is read-only. A user with a content-create permission can explicitly initialize an empty site space. No platform templates are automatically copied.
- White-label list/metadata GETs may omit `space_id`: the host uses `WhiteLabelSiteContext` to resolve the first enabled own-tenant site (ordered by site ID) and returns `cms_context: { sites, site_id, space_id }` alongside the normal result. No content space is created or copied during reads. An uninitialized default site produces an empty bootstrap payload with a null context space; it does not silently switch to another site. Explicit foreign/template/disabled-site spaces remain forbidden.
- Writes and record-detail endpoints still require explicit `space_id`. Initialize returns the new context in the same response. `/cms/sites` remains compatible with older clients but is no longer needed by the agent CMS host.
- Set shared UI `CmsRuntime.resolveSiteInRequest = true` to mount the business page immediately and consume inline context. First context adoption does not remount/refetch the page; explicit site changes invalidate previous request adapters and late responses. Translation site selection remains available. Platform and non-opted-in hosts keep their original transport.
- Feedback attachments must use the private upload disk shared with the user-facing feedback uploader. Configure `CMS_FEEDBACK_ATTACHMENT_DISK` appropriately; never use `public`.

## Jobs

The platform remains the single scheduler owner. Its existing `cms:publish-*` commands now include enabled site spaces as well as the default template. Its `cms:translate-work` processes the shared translation queue when enabled. Do not schedule a second worker on the agent host against the same queue without an intentional deployment decision.

Categories have create/edit/delete and enabled state, not an independent publish lifecycle. News, courses, help articles and announcements have draft, published and scheduled states. Editing/deleting already published or scheduled content additionally requires the module's publish permission. Feedback remains read-only.

Manual translations do not require Google credentials. Machine translation and connection tests use the host's `cms_translation` configuration and `GOOGLE_TRANSLATE_API_KEY`; testing this integration does not call Google.

## Local development dependencies

CMS is exposed by the `@work-gpt/6mm-ui/cms` entry. The agent uses local `packages/work-gpt-6mm-ui-1.0.102-cms-inline.tgz` and PHP `packages/6mm-php-1.0.91-beta.1-cms-inline.zip` artifacts, with lockfile integrity, so reinstalling dependencies retains the combined context protocol. Include those artifacts when moving this local integration; replace them with reviewed published versions when releasing. These changes do not publish packages or deploy either host.

## Verification

- Platform `tests/Integration/Cms` runs only against a disposable localhost database via `CMS_MIGRATION_TEST_DSN`; fixtures roll back.
- Agent `tests/Feature/WhiteLabelCmsAccessTest.php` uses in-memory SQLite and verifies every CMS route rejects foreign, template and disabled spaces.
- Shared UI `src/cms/runtime.test.ts` verifies immutable site request bindings, old-adapter invalidation and read-only discovery.
- Agent `WhiteLabelCmsInlineContextTest` covers all ten pages, implicit read context, explicit-space selection, empty sites and explicit-only writes. Shared UI `site-transport.test.ts` covers concurrent replies, aborts, uploads and stale adapters. Browser `cms-inline-context.spec.ts` verifies immediate page rendering, no `/cms/sites` and no duplicate bootstrap request.
