# Shared symbol-tag translations

- `SymbolTagTranslations` owns language validation, draft metadata, scoped tag ownership and transactional writes to the existing `symbol_tag_translation` table. It keeps `tag_name`, `tag_name_zh` and `tag_name_en` synchronized. A full language draft replaces the existing translations; blank values remove those translations. No schema migration is required.
- `forScope(site, agent)` selects `symbol_tag`; platform uses `(0,0)`, white label uses its authorized site and agent `0`, merchants use their authorized site/main agent. Scope comes from the host, never the request body. Legacy Agent callers without `forScope` still use `agent_symbol_tag` and `agent_symbol_tag_id`.
- Reads and manual saves do not resolve Google credentials. Only automatic translation calls the host runtime loader after checking ownership. The returned translations are previews, not writes.
- Agent retains `agent_translation_config` and its existing cipher. Platform's new tag adapter uses the same `cms.spaces` default template / `cms.translation_settings` credential as its existing CMS/SEO platform configuration. A corrupt encrypted credential never falls back to an environment key.
- `Cms\PlainTextTranslator` and `Cms\GoogleTranslationClient` are shared by SEO and tags. Tag batches have bounded concurrency, individual failure reporting and safe errors; provider response bodies and keys are not returned. CMS HTML/job translation keeps its existing worker and uses the same underlying Google client.
- Platform new routes require `symbol_tag:add` or `symbol_tag:edit` to load/preview, and `symbol_tag:edit` to save existing translations. Create/edit accept optional `translations`, saved in the same database transaction as the tag. Product category publication retains the existing safe-publication behavior after commit.
- Shared UI adapters use `MmMultilingualFields`: one name field for tags, title/description for SEO. Editing/translation is draft-only until Apply, then the normal save action persists it. CMS full-page job management remains separate.

## Unified settings

- All three settings entry points use `MmTranslationSettingsDialog` and `TranslationSettingsInput`: target locales, automatic translation, masked encrypted key, concurrency, timeout, attempts and optimistic revision.
- Platform tags use the canonical platform CMS settings, just like platform content/SEO. White-label tags use the first enabled owned site's content-space settings. Reads never create spaces; no client-provided tenant/site can change scope.
- Merchants continue using independent `agent_translation_config` records with their existing encryption domain. Agent migration `2026_11_12_160000_extend_merchant_translation_settings` adds nullable target locales, timeout, attempts and revision. Existing keys are not rewritten; before the first settings save, legacy merchant targets default to the canonical CMS catalog.
- White-label settings writes require `cms_translation:config` as well as access to the tag page; platform settings writes require `cms_translation:config`. Others can view read-only settings. Existing merchant parent/subaccount ownership is retained.
- The 20-language tag catalog now matches CMS (including pt-PT, uz, fil and az). Older pt/ko/th/tr translation rows are retained in the database, not deleted by a new-catalog save.
- Legacy `translationConfigActions` / `/translation-config/*` remain compatibility APIs. The updated Agent/Platform pages use the new settings contract, not the legacy form.

Verification: `php tests/tag_translations.php`; Agent `AgentSymbolTagTranslationServiceTest`, `SymbolTagControllerTest`, `WhiteLabelSeoTest`; Platform `SymbolTagTranslationsTest`, `SymbolTagTranslationPersistenceTest`, `SharedSymbolTagManagerTest`, `TenantSeoConfigTest`; UI multilingual fields/tag adapters, symbol tag manager and SEO tests. All HTTP is faked and databases isolated during tests.

Source-only: no dependency upgrade, vendor edits, real-database migration, package release, or real Google/Redis requests. Deploy the shared sources/package before the Agent migration and then the host adapters; do not point new host code at an older shared package.
