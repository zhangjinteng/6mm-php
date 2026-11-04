<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use Illuminate\Encryption\Encrypter;
use RuntimeException;
use Throwable;

/** Dedicated CMS encryption domain. Never use either host's APP_KEY. */
final class TranslationCredential
{
    public function encrypt(#[\SensitiveParameter] string $key): string
    {
        return $this->cipher()->encryptString($key);
    }

    public function resolve(#[\SensitiveParameter] ?object $setting): string
    {
        $encrypted = $setting->api_key_ciphertext ?? null;
        if ($encrypted !== null) {
            try {
                $key = $this->cipher()->decryptString($encrypted);
            } catch (Throwable) {
                // A broken database credential must NEVER fall back to a different billing key.
                throw new RuntimeException('GOOGLE_CREDENTIAL_DECRYPT_FAILED');
            }
        } else {
            if ($setting && $setting->credential_ref !== config('cms_translation.credential_ref')) {
                throw new RuntimeException('GOOGLE_CREDENTIAL_UNSUPPORTED');
            }
            $key = (string) config('cms_translation.google_api_key');
        }
        if (trim($key) === '') throw new RuntimeException('GOOGLE_CREDENTIAL_MISSING');
        return $key;
    }

    public function status(?object $setting): array
    {
        $database = ($setting->api_key_ciphertext ?? null) !== null;
        try {
            $key = $this->resolve($setting);
            return ['credential_configured' => true, 'api_key_mask' => '••••' . (strlen($key) > 8 ? substr($key, -4) : ''),
                'credential_source' => $database ? 'database' : 'env', 'credential_error' => null];
        } catch (RuntimeException $e) {
            return ['credential_configured' => false, 'api_key_mask' => null,
                'credential_source' => $database ? 'database' : 'env', 'credential_error' => $e->getMessage()];
        }
    }

    private function cipher(): Encrypter
    {
        $key = (string) config('cms_translation.encryption_key');
        if (str_starts_with($key, 'base64:')) $key = base64_decode(substr($key, 7), true) ?: '';
        if (strlen($key) !== 32) throw new RuntimeException('GOOGLE_ENCRYPTION_KEY_MISSING');
        return new Encrypter($key, 'AES-256-CBC');
    }
}
