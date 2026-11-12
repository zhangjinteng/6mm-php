<?php

/** SQLite-only disposable fixture, never creates or touches a production database. */
function translationSettingsFixture(\Illuminate\Database\ConnectionInterface $db): void
{
    if ($db->getDriverName() !== 'sqlite') throw new RuntimeException('SQLite fixture only');
    $attached = array_column($db->select('PRAGMA database_list'), 'name');
    if (!in_array('cms', $attached, true)) $db->statement("ATTACH DATABASE ':memory:' AS cms");
    $audit = 'created_at text, created_by_type text, created_by_id integer, updated_at text, updated_by_type text, updated_by_id integer, updated_by_name text';
    $db->statement('CREATE TABLE IF NOT EXISTS cms.spaces (id integer PRIMARY KEY, home_site_id integer, template_key text, space_type text, source_locale text, is_enabled boolean, revision integer, translation_setting_id integer, enabled_locales text, '.$audit.')');
    $db->statement("CREATE TABLE IF NOT EXISTS cms.translation_settings (id integer PRIMARY KEY AUTOINCREMENT, config_key text UNIQUE, provider text DEFAULT 'google', api_version text DEFAULT 'v2', is_enabled boolean DEFAULT 0, credential_ref text, api_key_ciphertext text, project_id text, concurrency_limit integer DEFAULT 3, request_timeout_seconds integer DEFAULT 30, max_attempts integer DEFAULT 3, $audit)");
    \SixMm\Shared\Cms\TranslationSettingsSchema::upgrade($db);
}
