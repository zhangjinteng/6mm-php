<?php

declare(strict_types=1);

namespace SixMm\Shared\TranslationConfig;

use Illuminate\Database\ConnectionInterface;
use SixMm\Shared\Cms\{GoogleTranslationClient, TranslationCatalog, TranslationSettingsStore};

/** Merchant scope adapter; storage, credential handling and validation are shared with CMS/SEO. */
final class MerchantTranslationSettings
{
    private TranslationSettingsStore $store;
    public function __construct(ConnectionInterface $db) { $this->store = new TranslationSettingsStore($db); }

    public function settings(int $agentId): array
    {
        $key = TranslationSettingsStore::merchantKey($agentId);
        return $this->store->settings($this->store->row($key), $key, 'merchant', 'zh-CN',
            array_values(array_diff(array_keys(TranslationCatalog::LOCALES), ['zh-CN'])));
    }

    public function runtime(int $agentId): array
    {
        return $this->store->runtime($this->store->row(TranslationSettingsStore::merchantKey($agentId)));
    }

    public function test(int $agentId, #[\SensitiveParameter] array $p, GoogleTranslationClient $google): array
    {
        $row = $this->store->row(TranslationSettingsStore::merchantKey($agentId));
        $this->store->checkRevision($row, $p['space_revision']);
        if (!$row && trim((string) ($p['api_key'] ?? '')) === '') throw new \RuntimeException('GOOGLE_CREDENTIAL_MISSING');
        $google->withApiKey($this->store->key($row, $p))->translate('连接测试', 'zh-CN', 'en', false, 10);
        return ['connected'=>true];
    }

    public function save(int $agentId, int $actorId, #[\SensitiveParameter] array $p, GoogleTranslationClient $google): array
    {
        $key = TranslationSettingsStore::merchantKey($agentId);
        $row = $this->store->row($key);
        if ((!$row || $row->api_key_ciphertext === null) && $p['is_enabled'] && trim((string) ($p['api_key'] ?? '')) === '') {
            throw new \RuntimeException('GOOGLE_CREDENTIAL_MISSING');
        }
        $values = $this->store->prepare($row, $p, 'zh-CN', $google);
        if ($values['api_key_ciphertext'] === null) $values['credential_ref'] = 'merchant-unconfigured';
        $this->store->persist($key, $values, $p['space_revision'], ['type'=>'agent', 'id'=>$actorId]);
        return $this->settings($agentId);
    }
}
