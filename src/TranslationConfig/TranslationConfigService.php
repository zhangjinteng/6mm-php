<?php

declare(strict_types=1);

namespace SixMm\Shared\TranslationConfig;

use Illuminate\Database\ConnectionInterface;
use SixMm\Shared\Cms\{TranslationCredential, TranslationSettingsStore};

/** Legacy HTTP contract adapter. All reads/writes use the same CMS store as the unified UI. */
final class TranslationConfigService
{
    private TranslationSettingsStore $store;

    public function __construct(private ConnectionInterface $connection, TranslationConfigCipher $legacyCipher,
        private TranslationConfigVerifier $verifier)
    {
        // Parameter retained for constructor compatibility, never used to encrypt new settings.
        $this->store = new TranslationSettingsStore($connection);
    }

    public function get(int $agentId): array
    {
        $row = $this->store->row(TranslationSettingsStore::merchantKey($agentId));
        $settings = (new MerchantTranslationSettings($this->connection))->settings($agentId);
        return ['provider'=>'google', 'api_version'=>'v2', 'api_key_mask'=>$settings['api_key_mask'],
            'api_key_set'=>$settings['credential_configured'], 'concurrency'=>$settings['concurrency_limit'],
            'is_enabled'=>$row ? $settings['is_enabled'] : true, 'verify_status'=>$row->verify_status ?? 'pending',
            'verified_at'=>$row->verified_at ?? null, 'last_error'=>$row->last_error ?? null];
    }

    public function save(int $agentId, int $actorId, #[\SensitiveParameter] array $input): array
    {
        $key = TranslationSettingsStore::merchantKey($agentId);
        $row = $this->store->row($key);
        $concurrency = filter_var($input['concurrency'] ?? null, FILTER_VALIDATE_INT);
        $enabled = filter_var($input['is_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($actorId <= 0 || ($input['api_version'] ?? 'v2') !== 'v2' || $concurrency === false
            || $concurrency < 1 || $concurrency > 10 || $enabled === null) {
            throw TranslationConfigException::because(TranslationConfigException::INVALID_PARAMETERS);
        }
        $candidate = trim((string) ($input['api_key'] ?? ''));
        if ((!$row || $row->api_key_ciphertext === null) && $candidate === '') {
            throw TranslationConfigException::because(TranslationConfigException::API_KEY_REQUIRED);
        }
        $settings = (new MerchantTranslationSettings($this->connection))->settings($agentId);
        $p = ['api_key'=>$candidate, 'is_enabled'=>$enabled, 'concurrency_limit'=>$concurrency,
            'space_revision'=>$settings['space_revision'], 'target_locales'=>$settings['target_locales'],
            'request_timeout_seconds'=>$settings['request_timeout_seconds'], 'max_attempts'=>$settings['max_attempts']];
        $values = $this->store->prepare($row, $p, $settings['source_locale']);
        if ($candidate === '') {
            $values['verify_status'] = $row->verify_status;
            $values['verified_at'] = $row->verified_at;
            $values['last_error'] = $row->last_error;
        }
        $this->store->persist($key, $values, $p['space_revision'], ['type'=>'agent', 'id'=>$actorId]);
        return $this->get($agentId);
    }

    public function test(int $agentId, #[\SensitiveParameter] ?string $candidateApiKey = null): array
    {
        $key = TranslationSettingsStore::merchantKey($agentId);
        $candidate = trim((string) $candidateApiKey);
        $saved = $candidate === '';
        $row = $saved ? $this->store->row($key) : null;
        if ($saved) {
            if (!$row || $row->api_key_ciphertext === null) throw TranslationConfigException::because(TranslationConfigException::API_KEY_REQUIRED);
            try { $candidate = (new TranslationCredential())->resolve($row); }
            catch (\Throwable) { throw TranslationConfigException::because(TranslationConfigException::DECRYPT_FAILED); }
        }
        try { $valid = $this->verifier->verify($candidate)->isValid(); }
        catch (\Throwable) { $valid = false; }
        $result = ['valid'=>$valid, 'verify_status'=>$valid ? 'valid' : 'invalid',
            'verified_at'=>$valid ? date(DATE_ATOM) : null, 'last_error'=>$valid ? null : 'GOOGLE_TRANSLATION_TEST_FAILED'];
        if ($saved) {
            // A test of an old key must not mark a concurrently replaced key as valid.
            $this->connection->table('cms.translation_settings')->where('id', $row->id)
                ->where('revision', $row->revision)->where('api_key_ciphertext', $row->api_key_ciphertext)
                ->update(['verify_status'=>$result['verify_status'], 'verified_at'=>$result['verified_at'], 'last_error'=>$result['last_error']]);
        }
        return $result;
    }
}
