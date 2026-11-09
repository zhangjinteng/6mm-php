<?php

declare(strict_types=1);

/** SQLite-only fixture: fills missing source tables, never used by application code. */
function themeFixture(\Illuminate\Database\Connection $db, string $prefix = ''): void
{
    foreach ([
        'wl_site_config_themes' => 'site_id integer, config_version integer, schema_version integer, payload text, payload_sha256 text, previous_config_version integer, published_by text, publish_reason text, published_at text, primary key(site_id, config_version), check(config_version>0), check(previous_config_version IS NULL OR previous_config_version<config_version), foreign key(site_id,previous_config_version) references wl_site_config_themes(site_id,config_version)',
        'wl_site_domains' => 'site_id integer, domain text, is_primary boolean, template_id text, logo_url text, favicon_url text',
        'wl_site_modules' => 'site_id integer, module_code text, module_type text, enabled boolean',
        'wl_site_seo_configs' => 'site_id integer, indexing_enabled boolean, translations text, og_image_url text, google_verification_token text, verification_domain text',
        'wl_channel_configs' => 'tenant_id integer, social_links text, app_downloads text, customer_service text',
        'partner_account_profiles' => 'home_site_id integer, agent_id integer, support_email text, legal_entity_name text, registration_number text, registered_address text, effective_date text',
        'wl_agreements' => 'site_id integer, scope_type text, locale text, agreement_type text, source_mode text, custom_type text, is_enabled boolean, published_version integer, published_url text',
    ] as $table => $columns) $db->statement("CREATE TABLE IF NOT EXISTS {$prefix}{$table} ($columns)");
    // Older focused fixtures predate these source columns.
    foreach (['wl_site_domains'=>['is_primary'=>"boolean DEFAULT 1"],
        'wl_site_modules'=>['module_type'=>"text DEFAULT 'business'"]] as $table=>$columns) {
        foreach ($columns as $column=>$type) {
            $existing = array_column($db->select("PRAGMA {$prefix}table_info('{$table}')"), 'name');
            if (!in_array($column, $existing, true)) {
                $db->statement("ALTER TABLE {$prefix}{$table} ADD COLUMN {$column} {$type}");
            }
        }
    }
}
