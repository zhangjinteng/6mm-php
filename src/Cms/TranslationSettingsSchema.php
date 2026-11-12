<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use Illuminate\Database\ConnectionInterface;

/** Idempotent shared schema upgrade; run from the host's explicit migration only. */
final class TranslationSettingsSchema
{
    public static function upgrade(ConnectionInterface $db): void
    {
        $db->transaction(function () use ($db): void {
            $pg = $db->getDriverName() === 'pgsql';
            $columns = ['source_locale'=>"varchar(16) NOT NULL DEFAULT 'zh-CN'",
                'target_locales'=>($pg ? 'jsonb' : 'text').' NULL', 'revision'=>'bigint NOT NULL DEFAULT 0',
                'verify_status'=>"varchar(20) NOT NULL DEFAULT 'pending'",
                'verified_at'=>($pg ? 'timestamptz(6)' : 'text').' NULL', 'last_error'=>'text NULL'];
            $existing = $pg ? [] : array_column($db->select('PRAGMA cms.table_info(translation_settings)'), 'name');
            foreach ($columns as $name=>$type) {
                if (!$pg && in_array($name, $existing, true)) continue;
                $db->statement('ALTER TABLE cms.translation_settings ADD COLUMN '.($pg ? 'IF NOT EXISTS ' : '').$name.' '.$type);
            }
            if ($pg) {
                $db->statement(<<<'SQL'
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='cms_translation_settings_scope_values_ck' AND conrelid='cms.translation_settings'::regclass) THEN
        ALTER TABLE cms.translation_settings ADD CONSTRAINT cms_translation_settings_scope_values_ck
            CHECK (revision >= 0 AND btrim(source_locale) <> ''
                AND (target_locales IS NULL OR jsonb_typeof(target_locales) = 'array')
                AND verify_status IN ('pending','valid','invalid'));
    END IF;
END $$
SQL);
                foreach (['source_locale'=>'翻译源语言', 'target_locales'=>'目标语言 JSON 数组；NULL 仅用于迁移期间沿用内容空间',
                    'revision'=>'翻译配置乐观锁版本', 'verify_status'=>'密钥验证状态', 'verified_at'=>'密钥验证时间', 'last_error'=>'脱敏后的连接测试错误'] as $column=>$comment) {
                    $db->statement("COMMENT ON COLUMN cms.translation_settings.$column IS '$comment'");
                }
            }
            self::backfillSpaces($db);
        });
    }

    private static function backfillSpaces(ConnectionInterface $db): void
    {
        // Existing shared legacy settings are cloned per authorized content space.
        foreach ($db->table('cms.spaces')->orderBy('id')->get() as $space) {
            $old = $space->translation_setting_id ? $db->table('cms.translation_settings')->where('id', $space->translation_setting_id)->first() : null;
            if (!$old) continue;
            $key = 'content-space-'.$space->id;
            $row = $db->table('cms.translation_settings')->where('config_key', $key)->first();
            if ($row && $row->target_locales !== null) {
                if ((string) $space->translation_setting_id !== (string) $row->id) {
                    $db->table('cms.spaces')->where('id', $space->id)->update(['translation_setting_id'=>$row->id]);
                }
                continue;
            }
            $source = $space->source_locale ?: 'zh-CN';
            $locales = array_values(array_diff(TranslationSettingsStore::locales($space->enabled_locales), [$source]));
            $values = ['source_locale'=>$source, 'target_locales'=>json_encode($locales, JSON_THROW_ON_ERROR)];
            if (!$row) {
                $copy = (array) $old;
                unset($copy['id']);
                $copy['config_key'] = $key;
                $id = $db->table('cms.translation_settings')->insertGetId(array_replace($copy, $values));
            } else {
                $id = $row->id;
                $db->table('cms.translation_settings')->where('id', $id)->update($values);
            }
            $db->table('cms.spaces')->where('id', $space->id)->update(['translation_setting_id'=>$id]);
        }
    }
}
