<?php

declare(strict_types=1);

namespace SixMm\Shared\TranslationConfig;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;
use SixMm\Shared\Cms\GoogleTranslationClient;
use SixMm\Shared\Cms\TranslationCatalog;

/** Independent merchant storage with the same settings contract as CMS/SEO. */
final class MerchantTranslationSettings
{
    public function __construct(private ConnectionInterface $db, private TranslationConfigCipher $cipher) {}

    private function row(int $agentId): ?object
    {
        if ($agentId <= 0) throw new \InvalidArgumentException('Invalid merchant scope');
        return $this->db->table('agent_translation_config')->where('agent_id', $agentId)->first();
    }

    private function targets(?object $row): array
    {
        $stored = $row->target_locales ?? null;
        // Existing merchants retain all supported targets until their first settings save.
        return $stored === null ? array_values(array_diff(array_keys(TranslationCatalog::LOCALES), ['zh-CN']))
            : (is_array($stored) ? $stored : json_decode($stored, true, 512, JSON_THROW_ON_ERROR));
    }

    public function settings(int $agentId): array
    {
        $row = $this->row($agentId);
        $configured = false; $error = null;
        if (trim((string) ($row->api_key_ciphertext ?? '')) !== '') {
            try { $configured = trim($this->cipher->decrypt($row->api_key_ciphertext)) !== ''; }
            catch (\Throwable) { $error = 'GOOGLE_CREDENTIAL_DECRYPT_FAILED'; }
        }
        return ['space_id' => 'merchant:'.$agentId, 'space_revision' => (string) ($row->revision ?? 0),
            'source_locale' => 'zh-CN', 'configuration_scope' => 'merchant', 'target_locales' => $this->targets($row),
            'provider' => 'google', 'api_version' => 'v2', 'credential_ref' => 'database',
            'credential_configured' => $configured, 'credential_source' => 'database',
            'api_key_mask' => $row->api_key_mask ?? null, 'credential_error' => $error,
            'supported' => !$row || $row->provider === 'google' && $row->api_version === 'v2',
            'is_enabled' => (bool) ($row->is_enabled ?? false), 'concurrency_limit' => (int) ($row->concurrency ?? 3),
            'request_timeout_seconds' => (int) ($row->request_timeout_seconds ?? 30), 'max_attempts' => (int) ($row->max_attempts ?? 3)];
    }

    public function runtime(int $agentId): array
    {
        $row = $this->row($agentId);
        if (!$row) throw new \RuntimeException('GOOGLE_CREDENTIAL_MISSING');
        if (!$row->is_enabled || $row->provider !== 'google' || $row->api_version !== 'v2') throw new \RuntimeException('TRANSLATION_CONFIG_DISABLED');
        if (trim((string) $row->api_key_ciphertext) === '') throw new \RuntimeException('GOOGLE_CREDENTIAL_MISSING');
        try { $key = $this->cipher->decrypt($row->api_key_ciphertext); }
        catch (\Throwable) { throw new \RuntimeException('GOOGLE_CREDENTIAL_DECRYPT_FAILED'); }
        return ['api_key' => $key, 'concurrency' => (int) $row->concurrency,
            'timeout' => (int) ($row->request_timeout_seconds ?? 30), 'max_attempts' => (int) ($row->max_attempts ?? 3),
            'target_locales' => $this->targets($row)];
    }

    public function test(int $agentId, array $p, GoogleTranslationClient $google): array
    {
        $row = $this->row($agentId);
        $this->checkRevision($row, $p);
        $google->withApiKey($this->key($row, $p))->translate('连接测试', 'zh-CN', 'en', false, 10);
        return ['connected' => true];
    }

    public function save(int $agentId, int $actorId, array $p, GoogleTranslationClient $google): array
    {
        $existing = $this->row($agentId);
        $this->checkRevision($existing, $p);
        if (in_array('zh-CN', $p['target_locales'], true)) $this->fail('目标语言不能包含源语言');
        $candidate = trim((string) ($p['api_key'] ?? ''));
        $encrypted = $candidate !== '' ? $this->cipher->encrypt($candidate) : ($existing->api_key_ciphertext ?? '');
        if ($candidate !== '' || $p['is_enabled']) {
            $google->withApiKey($this->key($existing, $p))->translate('连接测试', 'zh-CN', 'en', false, (int) $p['request_timeout_seconds']);
        }
        return $this->db->transaction(function () use ($agentId, $actorId, $p, $candidate, $encrypted): array {
            $now = date(DATE_ATOM);
            // A unique placeholder + lock serializes concurrent first saves as well as updates.
            $this->db->table('agent_translation_config')->insertOrIgnore(['agent_id' => $agentId,
                'api_key_ciphertext' => '', 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now]);
            $row = $this->db->table('agent_translation_config')->where('agent_id', $agentId)->lockForUpdate()->first();
            $this->checkRevision($row, $p);
            $values = ['provider' => 'google', 'api_version' => 'v2', 'api_key_ciphertext' => $encrypted,
                'is_enabled' => $p['is_enabled'], 'concurrency' => $p['concurrency_limit'],
                'target_locales' => json_encode(array_values($p['target_locales']), JSON_THROW_ON_ERROR),
                'request_timeout_seconds' => $p['request_timeout_seconds'], 'max_attempts' => $p['max_attempts'],
                'revision' => (int) $row->revision + 1, 'updated_by' => $actorId, 'updated_at' => $now];
            if ($candidate !== '') $values['api_key_mask'] = '••••'.(strlen($candidate) > 8 ? substr($candidate, -4) : '');
            $this->db->table('agent_translation_config')->where('agent_id', $agentId)->update($values);
            return $this->settings($agentId);
        });
    }

    private function key(?object $row, array $p): string
    {
        $candidate = trim((string) ($p['api_key'] ?? ''));
        if ($candidate !== '') return $candidate;
        if (!$row || trim((string) $row->api_key_ciphertext) === '') $this->fail('请先配置 Google 翻译密钥');
        try { return $this->cipher->decrypt($row->api_key_ciphertext); }
        catch (\Throwable) { $this->fail('翻译密钥无法解密，请重新配置'); }
    }

    private function checkRevision(?object $row, array $p): void
    {
        if ((string) ($row->revision ?? 0) !== (string) $p['space_revision']) $this->fail('翻译配置已变化，请重新加载配置');
    }

    private function fail(string $message): never { throw ValidationException::withMessages(['translation' => $message]); }
}
