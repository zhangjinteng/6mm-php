<?php

declare(strict_types=1);

/** SQLite test schema only; production uses the separately managed migration 9. */
function emailRowsFixture(\Illuminate\Database\Connection $db, string $prefix = ''): void
{
    $db->statement("CREATE TABLE IF NOT EXISTS {$prefix}wl_site_email_configs (site_id integer PRIMARY KEY, version integer NOT NULL, site_name text NOT NULL, logo_url text NOT NULL DEFAULT '', default_locale text NOT NULL, updated_by integer NOT NULL, created_at text, updated_at text)");
    $db->statement("CREATE TABLE IF NOT EXISTS {$prefix}wl_site_email_template_items (site_id integer, purpose text, locale text, subject text, text_body text, html_body text NOT NULL DEFAULT '', PRIMARY KEY(site_id,purpose,locale))");
    $db->statement("CREATE TABLE IF NOT EXISTS {$prefix}wl_site_email_template_item_versions (site_id integer, template_version integer, purpose text, locale text, payload text, payload_sha256 text, PRIMARY KEY(site_id,template_version,purpose,locale))");
}
