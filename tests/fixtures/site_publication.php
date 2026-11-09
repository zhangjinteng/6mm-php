<?php

declare(strict_types=1);

use Illuminate\Database\Connection;

/** Isolated SQLite equivalent of the publication contract (no access to application data). */
function publicationFixture(Connection $db, array $sites): void
{
    foreach ([
        'wl_site_config_publications' => 'publication_version integer primary key, published_by text, publish_reason text, published_at text',
        'wl_site_config_snapshots' => 'site_id integer, config_version integer references wl_site_config_publications, previous_config_version integer, rollback_of_config_version integer, schema_version integer, payload text, payload_sha256 text, primary key(site_id,config_version)',
        'wl_site_auth_policies' => 'site_id integer primary key, version integer, registration_class text, email_registration_enabled boolean, email_login_enabled boolean, password_management_enabled boolean, updated_by integer, updated_at text',
        'wl_site_email_templates' => 'site_id integer primary key, version integer, site_name text, default_locale text, templates text, updated_by integer',
        'wl_site_email_template_versions' => 'site_id integer, template_version integer, payload text, payload_sha256 text, published_snapshot_version integer references wl_site_config_publications, primary key(site_id,template_version)',
        'wl_site_config_domains' => 'domain text primary key, site_id integer not null',
        'wl_site_domains' => 'domain text unique, site_id integer, is_primary boolean, created_at text',
    ] as $name => $columns) $db->statement("CREATE TABLE IF NOT EXISTS $name ($columns)");
    foreach (['auth_policy' => 'policy_version integer, before_policy text, after_policy text', 'email_template' => 'template_version integer, before_templates text, after_templates text'] as $key => $columns) {
        $db->statement("CREATE TABLE wl_site_{$key}_audits ($columns, site_id integer, snapshot_version integer references wl_site_config_publications, operator_id integer, reason text, source_service text, request_id text, action text)");
    }
    $db->statement('PRAGMA foreign_keys=ON');
    $db->table('wl_site_config_publications')->insert(['publication_version' => 8]);
    foreach ($sites as $site) {
        $raw = json_encode($site, JSON_THROW_ON_ERROR);
        $db->table('wl_site_config_snapshots')->insert(['site_id' => $site['siteId'], 'config_version' => 8,
            'schema_version' => 4, 'payload' => $raw, 'payload_sha256' => hash('sha256', $raw)]);
    }
}
