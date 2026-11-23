<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/** The only email-brand mutation boundary shared by both administration hosts. */
final class SiteEmailPublisher
{
    public static function changeFromInput(array $input, string $rawJson = ''): array
    {
        $change = ['expectedVersion' => $input['expected_version']];
        foreach (['site_name' => 'siteName', 'logo_url' => 'logoUrl', 'default_locale' => 'defaultLocale'] as $column => $field) {
            if (array_key_exists($column, $input)) $change[$field] = $input[$column];
        }
        // Laravel converts empty strings to null; JSON distinguishes clear from omit/null.
        $raw = json_decode($rawJson, true);
        if (is_array($raw) && array_key_exists('logo_url', $raw) && $raw['logo_url'] === '') $change['logoUrl'] = '';
        return $change;
    }

    public function __construct(private ConnectionInterface $db, private array $resourceHosts = [],
        private bool $enabledSitesOnly = false, private ?string $schema = null, private bool $consumersReady = true)
    {
        if ($schema !== null && !preg_match('/^[a-z_][a-z0-9_]*$/D', $schema)) throw new \InvalidArgumentException('Invalid schema');
        $this->resourceHosts = array_values(array_unique(array_map(static fn ($host) => strtolower(trim($host)), $resourceHosts)));
        foreach ($this->resourceHosts as $host) {
            if ($host === '' || str_contains($host, '*') || (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))) {
                throw new \InvalidArgumentException('Invalid email resource allowlist');
            }
        }
    }

    /** Both admin hosts already configure the same public image bed used by website uploads. */
    public static function forWebsiteBrand(ConnectionInterface $db, bool $enabledOnly = false, ?string $schema = null): self
    {
        $host = parse_url((string)config('imgbed.public_base'), PHP_URL_HOST);
        return new self($db, $host ? [$host] : [], $enabledOnly, $schema, (bool)config('site_email.consumers_v5_ready', false));
    }

    private function table(string $name)
    {
        $prefix = $this->schema ?? ($this->db->getDriverName() === 'pgsql' ? 'control_plane' : '');
        return $this->db->table(($prefix === '' ? '' : $prefix.'.').$name);
    }
    private function fail(string $message): never { throw new EmailConfigException($message); }
    private function writer(): ConfigSnapshotWriter { return new ConfigSnapshotWriter($this->table(...), $this->fail(...)); }
    private function references(): PublishedEmailReference { return new PublishedEmailReference($this->table(...), $this->fail(...)); }

    private function site(int $tenant, int $site, bool $lock = false): object
    {
        if ($tenant < 1 || $site < 1) $this->fail('站点归属参数无效');
        $owner = $this->table('wl_tenants')->where('tenant_id', $tenant)->first();
        $query = $this->table('wl_sites')->where('site_id', $site)->where('tenant_id', $tenant)
            ->when($this->enabledSitesOnly, static fn ($q) => $q->where('status', 'enabled'));
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$owner || !$row || ($this->enabledSitesOnly && $owner->status !== 'enabled')) $this->fail('站点不属于当前白标、已停用或不存在');
        return $row;
    }

    private function decoded(string $raw): array
    {
        try { $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { $this->fail('已有邮件配置 JSON 无效'); }
        if (!is_array($value)) $this->fail('已有邮件配置格式无效');
        return $value;
    }

    private function compact(int $version, array $brand, array $items): string
    {
        $manifest = ['version' => $version, 'siteName' => $brand['site_name'], 'defaultLocale' => $brand['default_locale']];
        if ($brand['logo_url'] !== '') $manifest['logoUrl'] = $brand['logo_url'];
        $manifest['items'] = array_map(static fn ($item) => ['purpose' => $item['template']['purpose'],
            'locale' => $item['template']['locale'], 'sha256' => $item['sha256']], $items);
        $raw = EmailTemplateRules::json($manifest);
        if (strlen($raw) > 131072) $this->fail('邮件发布清单超过容量限制');
        return $raw;
    }

    private function encodeItems(array $templates): array
    {
        $items = [];
        foreach ($templates as $body) {
            if (!is_array($body)) $this->fail('已有邮件模板格式无效');
            $raw = EmailTemplateRules::json($body);
            $items[] = ['template' => $body, 'payload' => $raw, 'sha256' => hash('sha256', $raw)];
        }
        usort($items, static fn ($a, $b) => [$a['template']['purpose'] ?? '', $a['template']['locale'] ?? ''] <=> [$b['template']['purpose'] ?? '', $b['template']['locale'] ?? '']);
        return $items;
    }

    /** Existing rows bodies are copied as raw bytes, never regenerated from the editor. */
    private function state(object $site, ?object $snapshot, bool $lock = false): array
    {
        $id = (int)$site->site_id;
        $configQuery = $this->table('wl_site_email_configs')->where('site_id', $id);
        $config = ($lock ? $configQuery->lockForUpdate() : $configQuery)->first();
        if ($config) {
            if (!$snapshot || $this->references()->schemaVersion($snapshot) !== 5) $this->fail('邮件源配置与已发布站点格式不一致');
            $ref = $snapshot->emailTemplateRef;
            $published = $this->references()->validateRows($id, $ref);
            $manifest = $this->decoded($published['manifest']);
            $brand = ['site_name' => $config->site_name, 'logo_url' => $config->logo_url, 'default_locale' => $config->default_locale];
            if ((int)$config->version !== $ref->version || $config->site_name !== $ref->siteName
                || $config->default_locale !== $ref->defaultLocale || $config->logo_url !== ($manifest['logoUrl'] ?? '')) {
                $this->fail('邮件品牌源配置与已发布版本不一致');
            }
            $sourceQuery = $this->table('wl_site_email_template_items')->where('site_id', $id)->orderBy('purpose')->orderBy('locale')->limit(257);
            $source = ($lock ? $sourceQuery->lockForUpdate() : $sourceQuery)->get();
            if ($source->count() !== count($published['items'])) $this->fail('邮件正文源配置与已发布版本不一致');
            $byIdentity = [];
            foreach ($published['items'] as $item) $byIdentity[$item['template']['purpose']."\0".$item['template']['locale']] = $item['template'];
            foreach ($source as $row) {
                $body = $byIdentity[$row->purpose."\0".$row->locale] ?? null;
                if (!$body) $this->fail('邮件正文源配置与已发布版本不一致');
                if ($row->purpose !== $body['purpose'] || $row->locale !== $body['locale'] || $row->subject !== $body['subject']
                    || $row->text_body !== $body['textBody'] || $row->html_body !== ($body['htmlBody'] ?? '')) $this->fail('邮件正文源配置与已发布版本不一致');
            }
            return ['version' => (int)$config->version, 'brand' => $brand, 'items' => $published['items'],
                'before' => $published['manifest'], 'source' => 'rows'];
        }
        if ($snapshot && $this->references()->schemaVersion($snapshot) === 5) $this->fail('已发布分行邮件配置缺少源记录，请先核对数据');
        $legacyQuery = $this->table('wl_site_email_templates')->where('site_id', $id);
        $legacy = ($lock ? $legacyQuery->lockForUpdate() : $legacyQuery)->first();
        if (!$legacy) {
            if (isset($snapshot->emailTemplateRef) || $this->table('wl_site_email_template_versions')->where('site_id', $id)->exists()) {
                $this->fail('已有邮件模板缺少源配置');
            }
            return ['version' => 0, 'brand' => ['site_name' => $site->site_name, 'logo_url' => '', 'default_locale' => 'zh-CN'],
                'items' => [], 'before' => null, 'source' => 'default'];
        }
        $entry = $this->table('wl_site_email_template_versions')->where('site_id', $id)->where('template_version', $legacy->version)->first();
        if (!$entry || !hash_equals($entry->payload_sha256, hash('sha256', $entry->payload))) $this->fail('已有邮件模板发布版本缺失或摘要错误');
        $body = $this->decoded($entry->payload);
        if (($body['version'] ?? null) !== (int)$legacy->version || ($body['siteName'] ?? null) !== $legacy->site_name
            || ($body['defaultLocale'] ?? null) !== $legacy->default_locale || !is_array($body['templates'] ?? null)
            || $body['templates'] != $this->decoded($legacy->templates)) $this->fail('旧邮件正文源配置与发布版本不一致');
        $ref = $snapshot->emailTemplateRef ?? null;
        if ($snapshot && (!$ref || $ref->version !== (int)$legacy->version || $ref->sha256 !== $entry->payload_sha256
            || $ref->siteName !== $legacy->site_name || $ref->defaultLocale !== $legacy->default_locale)) $this->fail('旧邮件引用与发布版本不一致');
        $brand = ['site_name' => $legacy->site_name, 'logo_url' => $body['logoUrl'] ?? '', 'default_locale' => $legacy->default_locale];
        $items = $this->encodeItems($body['templates']);
        return ['version' => (int)$legacy->version, 'brand' => $brand, 'items' => $items,
            'before' => $this->compact((int)$legacy->version, $brand, $items), 'source' => 'legacy'];
    }

    public function detail(int $tenant, int $site): array
    {
        return $this->db->transaction(function () use ($tenant, $site): array {
            if ($this->db->getDriverName() === 'pgsql' && $this->db->transactionLevel() === 1) {
                $this->db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            $row = $this->site($tenant, $site);
            [$publication, $snapshot] = $this->writer()->latest($tenant, $site);
            $state = $this->state($row, $snapshot);
            return ['site_id' => (string)$site, 'version' => (string)$state['version'], 'publication_version' => (string)$publication,
                'source' => $state['source'], 'resource_hosts' => $this->resourceHosts, 'can_publish' => $this->consumersReady] + $state['brand'];
        });
    }

    /** Called only inside an already locked base-site/account publication transaction. */
    public function prepare(int $tenant, int $site, array $change, string $requestId = ''): ?EmailPublication
    {
        if ($this->db->transactionLevel() < 1) throw new \LogicException('Email preparation requires a transaction');
        if (!$this->consumersReady) $this->fail('邮件 v5 发布尚未启用，请先确认消费者和数据库已就绪');
        if (array_diff(array_keys($change), ['expectedVersion', 'siteName', 'logoUrl', 'defaultLocale'])) $this->fail('只允许修改邮件品牌名称、Logo 和默认语言');
        $expected = $change['expectedVersion'] ?? null;
        if ((!is_int($expected) && !is_string($expected)) || !preg_match('/^(0|[1-9][0-9]{0,18})$/D', (string)$expected)
            || (string)(int)$expected !== (string)$expected || (int)$expected === PHP_INT_MAX) $this->fail('邮件版本参数无效');
        $row = $this->site($tenant, $site, true);
        [$publication, $snapshot] = $this->writer()->latest($tenant, $site);
        $state = $this->state($row, $snapshot, true);
        if ((int)$expected !== $state['version']) $this->fail('配置已被修改，请刷新后重试');
        $brand = $state['brand'];
        foreach (['siteName' => 'site_name', 'logoUrl' => 'logo_url', 'defaultLocale' => 'default_locale'] as $input => $column) {
            if (!array_key_exists($input, $change) || $change[$input] === null) continue;
            if (!is_string($change[$input])) $this->fail('邮件品牌字段必须是字符串');
            $value = $input === 'logoUrl' ? $change[$input] : trim($change[$input]);
            if ($value === '' && $input !== 'logoUrl') continue;
            $brand[$column] = $input === 'defaultLocale' ? EmailTemplateRules::locale($value) : $value;
        }
        if (trim($brand['site_name']) === '' || mb_strlen($brand['site_name']) > 128 || preg_match('/[\r\n\x00]/', $brand['site_name'])) $this->fail('品牌名称不能为空且最多 128 个字符');
        if (EmailTemplateRules::locale($brand['default_locale']) !== $brand['default_locale']) $this->fail('默认语言不是规范语言标签');
        EmailTemplateRules::url($brand['logo_url'], $this->resourceHosts);
        // Fill a missing template set, never replace existing bodies with a preset.
        $items = $state['items'] ?: $this->encodeItems(EmailTemplateRules::whiteDefaults());
        $sourceChanges = [];
        foreach ($items as &$item) {
            $before = $item['template'];
            $html = EmailHeaderLayout::html($before['htmlBody'] ?? '', $brand['site_name'], $state['brand']['site_name']);
            if ($html === ($before['htmlBody'] ?? '')) continue;
            $body = $before; $body['htmlBody'] = $html;
            $raw = EmailTemplateRules::json($body);
            $item = ['template' => $body, 'payload' => $raw, 'sha256' => hash('sha256', $raw)];
            if ($state['source'] === 'rows') $sourceChanges[] = ['before' => $before, 'after' => $body];
        }
        unset($item);
        if (count($items) > 256) $this->fail('邮件模板最多 256 条');
        $identities = []; $bytes = 0;
        foreach ($items as $item) {
            EmailTemplateRules::template($item['template'], $this->resourceHosts, $brand['logo_url'], true);
            $key = $item['template']['purpose']."\0".$item['template']['locale'];
            if (isset($identities[$key])) $this->fail('邮件模板用途和语言重复');
            $identities[$key] = true;
            $bytes += strlen($item['payload']);
            if (strlen($item['payload']) > 262144 || $bytes > 4194304) $this->fail('邮件模板超过容量限制');
        }
        if ($state['source'] === 'rows' && $brand === $state['brand'] && !$sourceChanges) return null;
        return new EmailPublication($tenant, $site, $publication, $state['version'], $brand, $items,
            $this->compact($state['version'] + 1, $brand, $items), $state['before'], $state['source'] !== 'rows', $requestId ?: (string)Str::uuid(), $sourceChanges);
    }

    public function assertPublishedSources(int $site, ?object $snapshot): void
    {
        if (!$snapshot) return; // First publication is validated by ConfigSnapshotWriter.
        $domains = $this->table('wl_site_domains')->where('site_id', $site)->orderBy('domain')->pluck('domain')->all();
        $index = $this->table('wl_site_config_domains')->where('site_id', $site)->orderBy('domain')->pluck('domain')->all();
        $published = $snapshot->domains; sort($published, SORT_STRING);
        $modules = $this->table('wl_site_modules')->where('site_id', $site)->where('enabled', true)->orderBy('module_code')->pluck('module_code')->all();
        $entitlements = $snapshot->entitlements; sort($entitlements, SORT_STRING);
        if ($domains !== $published || $index !== $published || $modules !== $entitlements) $this->fail('站点域名、索引或模块与发布快照不一致，请先核对配置');
        $siteRow = $this->table('wl_sites')->where('site_id', $site)->first();
        $tenant = $this->table('wl_tenants')->where('tenant_id', $siteRow->tenant_id)->first();
        $status = $siteRow->status === 'enabled' && $tenant?->status === 'enabled' ? 'enabled' : 'disabled';
        if ($status !== $snapshot->status || (int)$siteRow->entitlement_version !== $snapshot->entitlementVersion) {
            $this->fail('站点可用状态或授权版本与发布快照不一致，请先核对配置');
        }
    }

    public function publish(int $tenant, int $site, array $change, int $operator, string $actor, string $reason, ?callable $audit = null): array
    {
        if ($operator < 1) throw new \InvalidArgumentException('Invalid email operator');
        return $this->db->transaction(function () use ($tenant, $site, $change, $operator, $actor, $reason, $audit): array {
            $writer = $this->writer(); $writer->lock();
            $this->site($tenant, $site, true);
            [$publication, $snapshot] = $writer->latest($tenant, $site);
            $this->assertPublishedSources($site, $snapshot);
            $pending = $this->prepare($tenant, $site, $change);
            if (!$pending) return ['changed' => false, 'detail' => $this->detail($tenant, $site)];
            $result = $writer->append($tenant, $site, $publication, $operator, $actor, $reason, $pending);
            if ($audit) $audit(['site_id' => (string)$site, 'tenant_id' => (string)$tenant,
                'mail_version' => (string)($pending->expectedMailVersion + 1), 'publication_version' => $result['config_version']], \Carbon\CarbonImmutable::now());
            return $result + ['detail' => $this->detail($tenant, $site)];
        }, 3);
    }

    public function initializeIfMissing(int $tenant, int $site, int $operator, string $actor, string $reason): array
    {
        return $this->db->transaction(function () use ($tenant, $site, $operator, $actor, $reason): array {
            $this->writer()->lock(); $row = $this->site($tenant, $site, true);
            if ($this->table('wl_site_email_configs')->where('site_id', $site)->exists()) return ['initialized' => false];
            [, $snapshot] = $this->writer()->latest($tenant, $site);
            $state = $this->state($row, $snapshot);
            return ['initialized' => true] + $this->publish($tenant, $site, ['expectedVersion' => $state['version']], $operator, $actor, $reason);
        }, 3);
    }

    private function inheritedBrand(int $tenant, int $site): array
    {
        $assets = new \SixMm\Shared\TenantBrand\SiteBrandAssets($this->db, $this->schema);
        $logo = (string)$assets->read((string)$site)['logo_url'];
        if ($logo !== '' && $this->db->getDriverName() === 'pgsql') {
            $media = new \SixMm\Shared\Media\MediaRegistry();
            $ids = $media->idsForUrl($logo, 'logo_url');
            if (!$ids) $this->fail('网站 Logo 未在图床登记，请重新上传后发布');
            $media->checkMediaReferences('platform_admin', 'white_label', $tenant, 'brand', 'site', (string)$site,
                'logo_url', 'published', $ids);
        }
        return ['siteName' => (string)$this->table('wl_tenants')->where('tenant_id', $tenant)->value('tenant_name'),
            'logoUrl' => $logo, 'defaultLocale' => 'zh-CN'];
    }

    /** Explicit reconciliation / rename; the publication lock protects both source reads and version selection. */
    public function syncWebsiteBrand(int $tenant, int $site, int $operator, string $actor): array
    {
        return $this->db->transaction(function () use ($tenant, $site, $operator, $actor): array {
            $writer = $this->writer(); $writer->lock();
            $row = $this->site($tenant, $site, true);
            [, $snapshot] = $writer->latest($tenant, $site);
            $state = $this->state($row, $snapshot);
            $change = $this->inheritedBrand($tenant, $site);
            if ($state['source'] === 'rows' && $state['brand'] === ['site_name' => $change['siteName'],
                'logo_url' => $change['logoUrl'], 'default_locale' => 'zh-CN']) return ['initialized' => false, 'changed' => false];
            return ['initialized' => $state['source'] !== 'rows'] + $this->publish($tenant, $site,
                ['expectedVersion' => $state['version']] + $change, $operator, $actor, '邮件品牌同步网站标识与白标名称');
        }, 3);
    }

    /** Base-site bootstrap uses the same pending write without creating a legacy row first. */
    public function prepareInitialization(int $tenant, int $site): ?EmailPublication
    {
        if ($this->db->transactionLevel() < 1) throw new \LogicException('Email initialization requires a transaction');
        $row = $this->site($tenant, $site, true);
        [, $snapshot] = $this->writer()->latest($tenant, $site);
        $state = $this->state($row, $snapshot);
        $change = $this->inheritedBrand($tenant, $site);
        return $this->prepare($tenant, $site, ['expectedVersion' => $state['version']] + $change);
    }
}
