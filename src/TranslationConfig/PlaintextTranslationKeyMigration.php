<?php

declare(strict_types=1);

namespace SixMm\Shared\TranslationConfig;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use SixMm\Shared\Cms\TranslationSettingsSchema;
use RuntimeException;

/** Deployment-only: historical ciphertext is never decrypted by normal settings/runtime code. */
final class PlaintextTranslationKeyMigration
{
    public static function run(ConnectionInterface $db): int
    {
        try {
            return $db->transaction(function () use ($db): int {
            TranslationSettingsSchema::upgrade($db);
            if ($db->getDriverName() === 'pgsql') $db->statement('LOCK TABLE cms.translation_settings IN ACCESS EXCLUSIVE MODE');
            $count = 0;
            foreach ($db->table('cms.translation_settings')->whereNotNull('api_key_ciphertext')->orderBy('id')->lockForUpdate()->get() as $row) {
                try {
                    $key = (string) config('cms_translation.encryption_key');
                    if (str_starts_with($key, 'base64:')) $key = base64_decode(substr($key, 7), true) ?: '';
                    if (strlen($key) !== 32) throw new RuntimeException();
                    $plain = (new Encrypter($key, 'AES-256-CBC'))->decryptString($row->api_key_ciphertext);
                    if (trim($plain) === '') throw new RuntimeException();
                } catch (\Throwable) {
                    throw new RuntimeException('翻译配置 '.$row->id.' 的旧密钥转换失败，事务已回滚；请保留并检查原 CMS_TRANSLATION_ENCRYPTION_KEY');
                }
                if ($row->api_key !== null && !hash_equals((string) $row->api_key, $plain)) {
                    throw new RuntimeException('翻译配置 '.$row->id.' 的明文与旧密钥不一致，事务已回滚');
                }
                $db->table('cms.translation_settings')->where('id', $row->id)->update([
                    'api_key'=>$plain, 'api_key_ciphertext'=>null, 'credential_ref'=>'database']);
                $saved = $db->table('cms.translation_settings')->where('id', $row->id)->first();
                if ($saved->api_key_ciphertext !== null || !hash_equals($plain, (string) $saved->api_key)) {
                    throw new RuntimeException('翻译配置 '.$row->id.' 的密钥转换校验失败，事务已回滚');
                }
                unset($plain, $key);
                ++$count;
            }
            return $count;
            });
        } catch (QueryException) {
            throw new RuntimeException('翻译密钥转换写入失败，事务已回滚；请检查数据库结构和权限');
        }
    }
}
