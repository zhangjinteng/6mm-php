<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

use Closure;
use Illuminate\Support\Str;

/** Trusted pending write prepared only after source, version and resource validation. */
final class EmailPublication
{
    public function __construct(
        public readonly int $tenantId, public readonly int $siteId, public readonly int $expectedConfigVersion,
        public readonly int $expectedMailVersion, public readonly array $brand, public readonly array $items,
        public readonly string $manifest, public readonly ?string $beforeManifest, public readonly bool $initializeSource,
        public readonly string $requestId,
    ) {}

    public function reference(): array
    {
        return ['storage' => 'rows', 'version' => $this->expectedMailVersion + 1, 'siteName' => $this->brand['site_name'],
            'defaultLocale' => $this->brand['default_locale'], 'sha256' => hash('sha256', $this->manifest)];
    }

    public function persist(Closure $table, int $publication, int $operator, string $actor, string $reason): void
    {
        $timestamp = \Carbon\CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
        $version = $this->expectedMailVersion + 1;
        $source = $table('wl_site_email_configs')->where('site_id', $this->siteId);
        $values = $this->brand + ['version' => $version, 'updated_by' => $operator, 'updated_at' => $timestamp];
        if ($this->initializeSource) {
            $table('wl_site_email_configs')->insert($values + ['site_id' => $this->siteId, 'created_at' => $timestamp]);
            foreach ($this->items as $item) {
                $body = $item['template'];
                $table('wl_site_email_template_items')->insert(['site_id' => $this->siteId, 'purpose' => $body['purpose'],
                    'locale' => $body['locale'], 'subject' => $body['subject'], 'text_body' => $body['textBody'], 'html_body' => $body['htmlBody'] ?? '']);
            }
        } elseif ($source->where('version', $this->expectedMailVersion)->update($values) !== 1) {
            throw new EmailConfigException('配置已被修改，请刷新后重试');
        }
        $table('wl_site_email_template_versions')->insert(['site_id' => $this->siteId, 'template_version' => $version,
            'payload' => $this->manifest, 'payload_sha256' => hash('sha256', $this->manifest), 'published_snapshot_version' => $publication]);
        foreach ($this->items as $item) $table('wl_site_email_template_item_versions')->insert([
            'site_id' => $this->siteId, 'template_version' => $version, 'purpose' => $item['template']['purpose'], 'locale' => $item['template']['locale'],
            'payload' => $item['payload'], 'payload_sha256' => $item['sha256'],
        ]);
        $table('wl_site_email_template_audits')->insert(['site_id' => $this->siteId, 'template_version' => $version,
            'snapshot_version' => $publication, 'operator_id' => $operator, 'reason' => $reason,
            'source_service' => explode(':', $actor)[0], 'request_id' => $this->requestId ?: (string)Str::uuid(),
            'action' => $this->expectedMailVersion === 0 ? 'create' : 'update',
            'before_templates' => $this->beforeManifest, 'after_templates' => $this->manifest]);
    }
}
