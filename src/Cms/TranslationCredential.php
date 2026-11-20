<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use RuntimeException;

/** Plaintext database credentials. Historical decryption belongs only in explicit migrations. */
final class TranslationCredential
{
    public static function publicKeyActive(): bool
    {
        return trim((string) config('cms_translation.google_api_key')) !== '';
    }

    public function resolve(#[\SensitiveParameter] ?object $setting): string
    {
        if (self::publicKeyActive()) return trim((string) config('cms_translation.google_api_key'));
        $stored = $setting->api_key ?? null;
        if ($stored !== null) {
            $key = (string) $stored;
        } else {
            // Never use ciphertext as a Google key or borrow the environment key before conversion.
            if (($setting->api_key_ciphertext ?? null) !== null) throw new RuntimeException('GOOGLE_CREDENTIAL_MIGRATION_REQUIRED');
            throw new RuntimeException('GOOGLE_CREDENTIAL_MISSING');
        }
        if (trim($key) === '') throw new RuntimeException('GOOGLE_CREDENTIAL_MISSING');
        return $key;
    }

    public function status(#[\SensitiveParameter] ?object $setting): array
    {
        if (self::publicKeyActive()) return ['credential_configured'=>true, 'api_key_mask'=>null,
            'credential_source'=>'env', 'credential_error'=>null, 'credential_locked'=>true];
        $database = ($setting->api_key ?? null) !== null || ($setting->api_key_ciphertext ?? null) !== null;
        try {
            $key = $this->resolve($setting);
            return ['credential_configured' => true, 'api_key_mask' => '••••' . (strlen($key) > 8 ? substr($key, -4) : ''),
                'credential_source' => $database ? 'database' : 'env', 'credential_error' => null];
        } catch (RuntimeException $e) {
            return ['credential_configured' => false, 'api_key_mask' => null,
                'credential_source' => $database ? 'database' : 'env', 'credential_error' => $e->getMessage()];
        }
    }
}
