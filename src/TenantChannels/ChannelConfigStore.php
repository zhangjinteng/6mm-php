<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantChannels;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/** Hosts authorize tenant/actor IDs and supply their own transactional audit writer. */
final class ChannelConfigStore
{
    public function __construct(private ConnectionInterface $db, private ?string $schema = null)
    {
        if ($schema !== null && !preg_match('/^[a-z_][a-z0-9_]*$/D', $schema)) {
            throw new \InvalidArgumentException('Invalid channel schema');
        }
    }

    private function table(string $name): Builder
    {
        $schema = $this->schema ?? ($this->db->getDriverName() === 'pgsql' ? 'control_plane' : '');
        return $this->db->table(($schema !== '' ? $schema . '.' : '') . $name);
    }

    private function tenant(string $id, bool $lock = false): object
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id) || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0)) {
            throw new ChannelConfigException('tenant_id', '白标不存在');
        }
        $query = $this->table('wl_tenants')->where('tenant_id', $id);
        if ($lock) $query->lockForUpdate();
        return $query->first(['tenant_id', 'tenant_name']) ?? throw new ChannelConfigException('tenant_id', '白标不存在');
    }

    private function decode(?object $row, string $field): array
    {
        if (!$row) return [];
        $value = json_decode($row->{$field}, false, 512, JSON_THROW_ON_ERROR);
        if (!$value instanceof \stdClass) throw new \UnexpectedValueException('Channel config must be a JSON object');
        return (array) $value;
    }

    private function format(object $tenant, ?object $row): array
    {
        return ['tenant_id' => (string) $tenant->tenant_id, 'tenant_name' => $tenant->tenant_name,
            'social_links' => (object) ChannelConfigValidator::supported($this->decode($row, 'social_links'), ChannelConfigValidator::SOCIAL_KEYS),
            'app_downloads' => (object) ChannelConfigValidator::supported($this->decode($row, 'app_downloads'), ChannelConfigValidator::DOWNLOAD_KEYS),
            'expected_updated_at' => $row?->updated_at];
    }

    public function detail(string $tenantId): array
    {
        $tenant = $this->tenant($tenantId);
        return $this->format($tenant, $this->table('wl_channel_configs')->where('tenant_id', $tenantId)->first());
    }

    /** Partial sections are intentional: a social-only host must not overwrite hidden configuration. */
    public function save(string $tenantId, ?string $expectedUpdatedAt, array $sections, int $actorId, ?callable $audit = null, string $actorType = 'platform'): array
    {
        if ($actorId <= 0) throw new \InvalidArgumentException('Invalid channel operator');
        if (!in_array($actorType, ['platform', 'white_label'], true) || ($actorType === 'white_label' && $audit === null)) {
            throw new \InvalidArgumentException('Invalid channel audit identity');
        }
        if ($sections === [] || array_diff(array_keys($sections), ['social_links', 'app_downloads', 'customer_service']) !== []) {
            throw new ChannelConfigException('sections', '不支持的渠道配置字段');
        }
        return $this->db->transaction(function () use ($tenantId, $expectedUpdatedAt, $sections, $actorId, $audit, $actorType): array {
            if ($this->db->getDriverName() === 'pgsql') {
                $this->db->statement("SET LOCAL lock_timeout = '5s'");
                $this->db->statement("SET LOCAL statement_timeout = '15s'");
            }
            $tenant = $this->tenant($tenantId, true);
            $row = $this->table('wl_channel_configs')->where('tenant_id', $tenantId)->lockForUpdate()->first();
            if ($row?->updated_at !== $expectedUpdatedAt) {
                throw new ChannelConfigException('expected_updated_at', '配置已被其他操作员修改，请重新加载后再保存');
            }
            $before = $after = $encoded = [];
            foreach ($sections as $field => $links) {
                if (!is_array($links)) throw new ChannelConfigException($field, '渠道配置必须是对象');
                $existing = $this->decode($row, $field);
                if ($field === 'customer_service') {
                    // Preserve the Platform adapter's existing fallback-email-only capability.
                    if (array_diff(array_keys($links), ['fallback_email']) !== []) throw new ChannelConfigException($field, '客服配置字段不可修改');
                    if (!empty($existing['provider']) && $existing['provider'] !== 'sixmm') throw new ChannelConfigException($field, '已有其他客服配置，不能自动覆盖');
                    $email = $links['fallback_email'] ?? '';
                    if (!is_string($email) || mb_strlen($email) > 254 || (trim($email) !== '' && !filter_var(trim($email), FILTER_VALIDATE_EMAIL))) throw new ChannelConfigException($field . '.fallback_email', '请输入有效邮箱');
                    $before[$field] = ['fallback_email' => $existing['fallback_email'] ?? ''];
                    $after[$field] = ['fallback_email' => trim($email)];
                    $merged = array_replace($existing, $after[$field]);
                } else {
                    $keys = $field === 'social_links' ? ChannelConfigValidator::SOCIAL_KEYS : ChannelConfigValidator::DOWNLOAD_KEYS;
                    $before[$field] = ChannelConfigValidator::supported($existing, $keys);
                    $after[$field] = ChannelConfigValidator::section($links, $field);
                    $merged = array_diff_key($existing, array_flip($keys)) + $after[$field];
                }
                $encoded[$field] = json_encode((object) $merged, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
            if ($before === $after) {
                $this->syncTheme($tenantId, $actorId, $actorType);
                return ['changed' => false, 'detail' => $this->format($tenant, $row)];
            }
            $now = CarbonImmutable::now();
            if ($row && $now->lessThanOrEqualTo(CarbonImmutable::parse($row->updated_at))) $now = CarbonImmutable::parse($row->updated_at)->addMicrosecond();
            $timestamp = $now->format('Y-m-d H:i:s.uP');
            // updated_by references Platform's admin table, not wl_tenant_account.
            // White-label attribution lives in the host's mandatory transactional audit log.
            $data = $encoded + ['updated_by' => $actorType === 'platform' ? $actorId : null, 'updated_at' => $timestamp];
            if ($row) $this->table('wl_channel_configs')->where('tenant_id', $tenantId)->update($data);
            else $this->table('wl_channel_configs')->insert($data + ['tenant_id' => $tenantId, 'created_at' => $timestamp]);
            $this->syncTheme($tenantId, $actorId, $actorType);
            if ($audit !== null) $audit($before, $after, $timestamp);
            return ['changed' => true, 'detail' => $this->detail($tenantId)];
        }, 3);
    }

    private function syncTheme(string $tenant, int $operator, string $actorType): void
    {
        (new \SixMm\Shared\TenantConfig\ThemeSnapshotWriter($this->table(...)))->sync(
            (int)$tenant, null, $operator,
            ($actorType === 'platform' ? 'platform-admin:' : 'white-label-admin:').$operator, '保存渠道配置');
    }
}
