<?php

declare(strict_types=1);

namespace SixMm\Shared\TranslationConfig;

use Illuminate\Database\ConnectionInterface;
use SixMm\Shared\Cms\{TranslationCatalog, TranslationCredential, TranslationSettingsSchema, TranslationSettingsStore};
use RuntimeException;

/** Deployment-only. Re-encrypt, verify every row, then drop legacy storage atomically. */
final class LegacyMerchantTranslationMigration
{
    public static function run(ConnectionInterface $db, TranslationConfigCipher $legacyCipher): int
    {
        return $db->transaction(function () use ($db, $legacyCipher): int {
            TranslationSettingsSchema::upgrade($db);
            if (!$db->getSchemaBuilder()->hasTable('agent_translation_config')) return 0;
            if ($db->getDriverName() === 'pgsql') {
                // Prevent old processes from inserting/updating rows while verifying and dropping.
                $db->statement('LOCK TABLE agent_translation_config IN ACCESS EXCLUSIVE MODE');
            }
            $store = new TranslationSettingsStore($db);
            $credential = new TranslationCredential();
            $count = 0;
            foreach ($db->table('agent_translation_config')->orderBy('agent_id')->get() as $old) {
                $key = TranslationSettingsStore::merchantKey((int) $old->agent_id);
                $raw = (string) $old->api_key_ciphertext;
                try {
                    $plain = trim($raw) !== '' ? $legacyCipher->decrypt($raw) : null;
                    if ($plain !== null && trim($plain) === '') throw new RuntimeException();
                    $encrypted = $plain !== null ? $credential->encrypt($plain) : null;
                } catch (\Throwable) {
                    throw new RuntimeException('商户 '.$old->agent_id.' 翻译密钥迁移失败，旧表未删除；请检查旧 APP_KEY 和 CMS_TRANSLATION_ENCRYPTION_KEY');
                }
                $targets = ($old->target_locales ?? null) === null
                    ? array_values(array_diff(array_keys(TranslationCatalog::LOCALES), ['zh-CN']))
                    : TranslationSettingsStore::locales($old->target_locales);
                $type = ($old->updated_by ?? $old->created_by ?? null) ? 'agent' : 'system';
                $values = ['provider'=>$old->provider, 'api_version'=>$old->api_version,
                    'credential_ref'=>$encrypted !== null ? 'database' : 'merchant-unconfigured',
                    'api_key_ciphertext'=>$encrypted, 'is_enabled'=>$old->is_enabled,
                    'concurrency_limit'=>$old->concurrency, 'source_locale'=>'zh-CN',
                    'target_locales'=>json_encode($targets, JSON_THROW_ON_ERROR),
                    'request_timeout_seconds'=>$old->request_timeout_seconds ?? 30,
                    'max_attempts'=>$old->max_attempts ?? 3, 'revision'=>$old->revision ?? 0,
                    'verify_status'=>$old->verify_status ?? 'pending', 'verified_at'=>$old->verified_at ?? null,
                    // Do not carry potentially sensitive historical provider errors into the new table.
                    'last_error'=>null, 'created_at'=>$old->created_at, 'updated_at'=>$old->updated_at,
                    'created_by_type'=>($old->created_by ?? null) ? 'agent' : 'system', 'created_by_id'=>$old->created_by ?? null,
                    'updated_by_type'=>$type, 'updated_by_id'=>$old->updated_by ?? $old->created_by ?? null];
                $row = $store->row($key, true);
                if (!$row) {
                    $db->table('cms.translation_settings')->insert(['config_key'=>$key] + $values);
                    $row = $store->row($key, true);
                }
                // Never overwrite a new configuration on rerun or silently delete divergent legacy data.
                foreach (['provider','api_version','credential_ref','is_enabled','concurrency_limit','source_locale',
                    'request_timeout_seconds','max_attempts','revision','verify_status'] as $field) {
                    if ((string) $row->$field !== (string) $values[$field]) throw new RuntimeException('商户 '.$old->agent_id.' 新旧翻译配置冲突，旧表未删除');
                }
                if ($store->targets($row) !== $targets) throw new RuntimeException('商户 '.$old->agent_id.' 翻译语言校验失败，旧表未删除');
                try {
                    $same = $plain === null ? $row->api_key_ciphertext === null : hash_equals($plain, $credential->resolve($row));
                } catch (\Throwable) { $same = false; }
                if (!$same) throw new RuntimeException('商户 '.$old->agent_id.' 翻译密钥校验失败，旧表未删除');
                unset($plain, $encrypted, $raw);
                ++$count;
            }
            $db->getSchemaBuilder()->drop('agent_translation_config');
            return $count;
        });
    }
}
