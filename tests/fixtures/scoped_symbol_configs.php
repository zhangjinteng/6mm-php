<?php

/** Existing upstream columns in isolated fixtures only; never a production migration. */
function scopedSymbolFixture(\Illuminate\Database\ConnectionInterface $db): void
{
    $schema=$db->getSchemaBuilder();
    if (!$schema->hasTable('symbol_config')) $db->statement("CREATE TABLE symbol_config (id integer primary key autoincrement,
        symbol text NOT NULL,sort integer,is_active integer,is_visible boolean,liquidation_fee text,
        min_quantity text,version integer,created_at text,updated_at text,deleted_at text)");
    if (!$schema->hasTable('symbol_tag')) $db->statement("CREATE TABLE symbol_tag (id integer primary key autoincrement,
        tag_name text,tag_code text NOT NULL DEFAULT '',tag_name_zh text,tag_name_en text,parent_id integer DEFAULT 0,
        sort integer,is_enable integer,count integer DEFAULT 0,created_at text,updated_at text,deleted_at text)");
    if (!$schema->hasTable('symbol_config_tag')) $db->statement('CREATE TABLE symbol_config_tag (id integer primary key autoincrement,
        symbol_config_id integer,symbol_tag_id integer)');
    foreach (['symbol_config','symbol_tag','symbol_config_tag'] as $table) {
        foreach (['home_site_id'=>'integer NOT NULL DEFAULT 0','agent_id'=>'integer NOT NULL DEFAULT 0','revision'=>'integer NOT NULL DEFAULT 1'] as $column=>$type) {
            if (!$schema->hasColumn($table,$column)) $db->statement("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");
        }
    }
    foreach (['base_config_id'=>'integer','sort'=>'integer','is_visible'=>'boolean','is_active'=>'integer'] as $column=>$type) {
        if (!$schema->hasColumn('symbol_config',$column)) $db->statement("ALTER TABLE symbol_config ADD COLUMN {$column} {$type}");
    }
    if (!$schema->hasColumn('symbol_tag','base_tag_id')) $db->statement('ALTER TABLE symbol_tag ADD COLUMN base_tag_id integer');
    if (!$schema->hasColumn('symbol_config_tag','is_attached')) $db->statement('ALTER TABLE symbol_config_tag ADD COLUMN is_attached boolean NOT NULL DEFAULT true');
    $db->statement('CREATE UNIQUE INDEX IF NOT EXISTS fixture_symbol_scope ON symbol_config(home_site_id,agent_id,symbol)');
    $db->statement('CREATE UNIQUE INDEX IF NOT EXISTS fixture_tag_base ON symbol_tag(home_site_id,agent_id,base_tag_id)');
    $db->statement('CREATE UNIQUE INDEX IF NOT EXISTS fixture_relation_scope ON symbol_config_tag(home_site_id,agent_id,symbol_config_id,symbol_tag_id)');
}
