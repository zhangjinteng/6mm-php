<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Storage only: callers must authorize the server-resolved key/space before using this service. */
final class TranslationSettingsStore
{
    public function __construct(private ConnectionInterface $db) {}

    public static function merchantKey(int $agentId): string
    {
        if ($agentId <= 0) throw new \InvalidArgumentException('Invalid merchant scope');
        return 'merchant:'.$agentId;
    }

    public function row(string $key, bool $lock = false): ?object
    {
        $q = $this->db->table('cms.translation_settings')->where('config_key', $key);
        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    public function byId(?int $id): ?object
    {
        return $id ? $this->db->table('cms.translation_settings')->where('id', $id)->first() : null;
    }

    public static function locales(mixed $value): array
    {
        if (is_array($value)) return array_values($value);
        if ($value === null || $value === '') return [];
        $json = json_decode((string) $value, true);
        return is_array($json) ? array_values($json) : str_getcsv(trim((string) $value, '{}'));
    }

    public function targets(?object $row, array $fallback = []): array
    {
        return $row && ($row->target_locales ?? null) !== null ? self::locales($row->target_locales) : $fallback;
    }

    public function settings(#[\SensitiveParameter] ?object $row, string $identity, string $scope, string $source = 'zh-CN', array $targets = []): array
    {
        // Merchant keys must never silently borrow the deployment-wide Google credential.
        $status = $scope === 'merchant' && (!$row || $row->api_key_ciphertext === null)
            ? ['credential_configured'=>false, 'api_key_mask'=>null, 'credential_source'=>'database', 'credential_error'=>'GOOGLE_CREDENTIAL_MISSING']
            : (new TranslationCredential())->status($row);
        return ['space_id'=>$identity, 'space_revision'=>(string) ($row->revision ?? 0),
            'configuration_scope'=>$scope, 'source_locale'=>$row->source_locale ?? $source,
            'target_locales'=>$this->targets($row, $targets), 'provider'=>'google', 'api_version'=>'v2',
            'credential_ref'=>$row->credential_ref ?? 'database',
            'supported'=>!$row || $row->provider === 'google' && $row->api_version === 'v2',
            'is_enabled'=>(bool) ($row->is_enabled ?? false) && (!$row || $row->provider === 'google' && $row->api_version === 'v2'),
            'concurrency_limit'=>(int) ($row->concurrency_limit ?? 3),
            'request_timeout_seconds'=>(int) ($row->request_timeout_seconds ?? 30),
            'max_attempts'=>(int) ($row->max_attempts ?? 3)] + $status;
    }

    public function runtime(#[\SensitiveParameter] ?object $row, array $targets = []): array
    {
        if (!$row) throw new RuntimeException('GOOGLE_CREDENTIAL_MISSING');
        if (!$row->is_enabled || $row->provider !== 'google' || $row->api_version !== 'v2') throw new RuntimeException('TRANSLATION_CONFIG_DISABLED');
        return ['api_key'=>$this->key($row, []),
            'concurrency'=>max(1, min(20, (int) $row->concurrency_limit)),
            'timeout'=>max(1, min(120, (int) ($row->request_timeout_seconds ?? 30))),
            'max_attempts'=>max(1, min(10, (int) ($row->max_attempts ?? 3))),
            'target_locales'=>$this->targets($row, $targets)];
    }

    public function key(#[\SensitiveParameter] ?object $row, #[\SensitiveParameter] array $p): string
    {
        $candidate = trim((string) ($p['api_key'] ?? ''));
        if ($candidate !== '') return $candidate;
        if ($row && str_starts_with((string) ($row->config_key ?? ''), 'merchant:') && $row->api_key_ciphertext === null) {
            throw new RuntimeException('GOOGLE_CREDENTIAL_MISSING');
        }
        return (new TranslationCredential())->resolve($row);
    }

    public function checkRevision(?object $row, mixed $revision): void
    {
        if ((string) ($row->revision ?? 0) !== (string) $revision) $this->fail('翻译配置已变化，请重新加载配置');
    }

    /** Prepare/encrypt/verify outside write locks. persist() rechecks the revision in the transaction. */
    public function prepare(#[\SensitiveParameter] ?object $row, #[\SensitiveParameter] array $p, string $source, ?GoogleTranslationClient $google = null): array
    {
        $p = Validator::make($p, TranslationSettingsInput::rules())->validate();
        $this->checkRevision($row, $p['space_revision']);
        if (!isset(TranslationCatalog::LOCALES[$source]) || in_array($source, $p['target_locales'], true)) $this->fail('目标语言不能包含源语言');
        $credential = new TranslationCredential();
        $candidate = trim((string) ($p['api_key'] ?? ''));
        $encrypted = $candidate !== '' ? $credential->encrypt($candidate) : ($row->api_key_ciphertext ?? null);
        if ($google && ($candidate !== '' || $p['is_enabled'])) {
            $google->withApiKey($this->key($row, $p))->translate('连接测试', 'zh-CN', 'en', false, (int) $p['request_timeout_seconds']);
        }
        return ['provider'=>'google', 'api_version'=>'v2',
            'credential_ref'=>$encrypted !== null ? 'database' : config('cms_translation.credential_ref'),
            'api_key_ciphertext'=>$encrypted, 'project_id'=>null, 'is_enabled'=>$p['is_enabled'],
            'concurrency_limit'=>$p['concurrency_limit'], 'request_timeout_seconds'=>$p['request_timeout_seconds'],
            'max_attempts'=>$p['max_attempts'], 'source_locale'=>$source,
            'target_locales'=>json_encode(array_values($p['target_locales']), JSON_THROW_ON_ERROR),
            'verify_status'=>$google ? (($candidate !== '' || $p['is_enabled']) ? 'valid' : ($row->verify_status ?? 'pending')) : 'pending',
            'verified_at'=>$google && ($candidate !== '' || $p['is_enabled']) ? date(DATE_ATOM) : null,
            'last_error'=>null];
    }

    /** Unique insertion + row lock also serializes concurrent first saves; no external calls here. */
    public function persist(string $key, #[\SensitiveParameter] array $values, mixed $expectedRevision, array $actor): object
    {
        return $this->db->transaction(function () use ($key, $values, $expectedRevision, $actor): object {
            $now = date(DATE_ATOM);
            $audit = ['updated_at'=>$now, 'updated_by_type'=>$actor['type'] ?? 'platform',
                'updated_by_id'=>$actor['id'] ?? null, 'updated_by_name'=>$actor['name'] ?? null];
            $this->db->table('cms.translation_settings')->insertOrIgnore([
                'config_key'=>$key, 'provider'=>'google', 'api_version'=>'v2', 'credential_ref'=>'database',
                'is_enabled'=>false, 'created_at'=>$now, 'created_by_type'=>$audit['updated_by_type'],
                'created_by_id'=>$audit['updated_by_id']] + $audit);
            $row = $this->row($key, true);
            if (!$row) throw new RuntimeException('TRANSLATION_SETTINGS_INSERT_FAILED');
            $this->checkRevision($row, $expectedRevision);
            $this->db->table('cms.translation_settings')->where('id', $row->id)
                ->update($values + $audit + ['revision'=>(int) ($row->revision ?? 0) + 1]);
            return $this->row($key);
        });
    }

    private function fail(string $message): never { throw ValidationException::withMessages(['translation'=>$message]); }
}
