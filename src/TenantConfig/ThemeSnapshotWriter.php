<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

use Closure;
use Illuminate\Container\Container;
use SixMm\Shared\TenantChannels\ChannelConfigValidator;
use SixMm\Shared\TenantCompliance\ComplianceConfigStore;
use SixMm\Shared\TenantCustomerService\CustomerService;
use SixMm\Shared\TenantSeo\SeoTranslations;

/** One trusted, transactional writer for both hosts. Never accepts a browser-provided payload. */
final class ThemeSnapshotWriter
{
    public function __construct(private Closure $table, private ?ThemeSnapshotReplication $replication = null) {}

    private function replicate(int $site): void
    {
        $replication = $this->replication;
        $container = Container::getInstance();
        if (!$replication && $container->bound(ThemeSnapshotReplication::class)) {
            $replication = $container->make(ThemeSnapshotReplication::class);
        }
        $replication?->enqueue($this->table, $site);
    }

    private function rows(string $name) { return ($this->table)($name); }
    private function siteRows(string $name, int $site) { return $this->rows($name)->where('site_id', $site); }
    private function object(?string $json): array
    {
        if ($json === null || $json === '') return [];
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new \UnexpectedValueException('Invalid theme source JSON');
        return $value;
    }
    private function nullable(mixed $value): ?string { return $value === null || trim((string)$value) === '' ? null : trim((string)$value); }

    /** The caller must authorize this tenant and acquire its source locks before changing configuration. */
    public function sync(int $tenant, ?int $site, int $operator, string $actor, string $reason, ?int $publicationVersion = null): array
    {
        $db = $this->rows('wl_tenants')->getConnection();
        if ($db->transactionLevel() < 1 || $tenant < 1 || $operator < 1 || !trim($actor) || mb_strlen($actor) > 128 || !trim($reason)) {
            throw new \LogicException('Theme publication requires a valid transactional identity');
        }
        $owner = $this->rows('wl_tenants')->where('tenant_id', $tenant)->lockForUpdate()->first();
        $siteRow = $this->rows('wl_sites')->where('tenant_id', $tenant)
            ->when($site !== null, fn ($q) => $q->where('site_id', $site))->orderBy('site_id')->lockForUpdate()->first();
        if (!$owner || !$siteRow) throw new \RuntimeException('白标站点不存在或不属于该白标');
        $site = (int)$siteRow->site_id;
        $payload = json_encode($this->payload($owner, $siteRow), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($payload) > 1048576) throw new \RuntimeException('站点主题配置超过容量限制');
        $hash = hash('sha256', $payload);
        $latest = $this->siteRows('wl_site_config_themes', $site)->orderByDesc('config_version')->first();
        if ($latest) {
            $old = json_decode($latest->payload, false, 512, JSON_THROW_ON_ERROR);
            if ((int)$latest->schema_version !== 1 || !hash_equals($latest->payload_sha256, hash('sha256', $latest->payload))
                || ($old->siteId ?? null) !== (string)$site) throw new \RuntimeException('站点主题快照校验失败');
            if ($latest->payload === $payload) {
                $this->replicate($site);
                return ['changed' => false, 'site_id' => (string)$site, 'config_version' => (string)$latest->config_version];
            }
        }
        $previous = (int)($latest->config_version ?? 0);
        $version = $publicationVersion ?? 0;
        if ($version <= $previous) {
            $version = $db->getDriverName() === 'sqlite'
                ? max((int)$this->rows('wl_site_config_themes')->max('config_version'), $previous) + 1
                : (int)$db->selectOne("SELECT nextval('control_plane.wl_snapshot_version_seq'::regclass) AS version")->version;
        }
        if ($version <= $previous) throw new \RuntimeException('主题发布版本序列异常');
        $this->rows('wl_site_config_themes')->insert(['site_id' => $site, 'config_version' => $version, 'schema_version' => 1,
            'payload' => $payload, 'payload_sha256' => $hash, 'previous_config_version' => $previous ?: null,
            'published_by' => $actor, 'publish_reason' => $reason, 'published_at' => \Carbon\CarbonImmutable::now()]);
        $this->replicate($site);
        return ['changed' => true, 'site_id' => (string)$site, 'config_version' => (string)$version];
    }

    private function payload(object $tenant, object $site): array
    {
        $id = (int)$site->site_id;
        $domains = $this->siteRows('wl_site_domains', $id)->orderByDesc('is_primary')->orderBy('domain')->get();
        $brand = $domains->first();
        $modules = $this->siteRows('wl_site_modules', $id)->get()->keyBy('module_code');
        $flag = static fn (string $code, string $type): bool => isset($modules[$code])
            && $modules[$code]->module_type === $type && (bool)$modules[$code]->enabled;
        $features = [];
        foreach (['tradingRanking'=>'trade_ranking','news'=>'news','academy'=>'academy','help'=>'help_center',
            'announcements'=>'announcement','feedback'=>'feedback'] as $key=>$code) $features[$key] = $flag($code, 'feature');
        $seo = $this->siteRows('wl_site_seo_configs', $id)->first();
        $copies = SeoTranslations::home($seo?->translations ?? null);
        $home = [];
        ksort($copies, SORT_STRING);
        foreach ($copies as $locale=>$copy) $home[$locale] = ['title'=>(string)($copy['title'] ?? ''), 'description'=>(string)($copy['description'] ?? '')];
        $primary = $domains->first(fn ($row) => (bool)$row->is_primary)?->domain;
        $verification = $seo && $seo->verification_domain === $primary ? $this->nullable($seo->google_verification_token) : null;
        $channel = $this->rows('wl_channel_configs')->where('tenant_id', $tenant->tenant_id)->first();
        $customer = $this->object($channel?->customer_service ?? null);
        $connected = $flag(CustomerService::MODULE, 'business')
            && CustomerService::isProvider($customer['provider'] ?? null)
            && ($customer['status'] ?? null) === 'bound'
            && CustomerService::validAppKey($customer['appKey'] ?? null);
        $support = ['provider'=>$connected ? '6mm' : null, 'publicWidgetId'=>$connected ? $customer['appKey'] : null];
        $social = ChannelConfigValidator::supported($this->object($channel?->social_links ?? null), ChannelConfigValidator::SOCIAL_KEYS);
        $downloads = ChannelConfigValidator::supported($this->object($channel?->app_downloads ?? null), ChannelConfigValidator::DOWNLOAD_KEYS);
        $links = [];
        foreach ($social as $platform=>$url) $links[] = compact('platform','url');
        $profile = $this->rows('partner_account_profiles')->where('home_site_id', $id)->whereNull('agent_id')->first();
        $agreements = $this->siteRows('wl_agreements', $id)->where('scope_type', 'site')->where('locale', 'en')->get()->keyBy('agreement_type');
        $documents = [];
        foreach (array_keys(ComplianceConfigStore::TYPES) as $type) {
            $row = $agreements->get($type);
            $url = $row && (int)$row->published_version > 0 && ComplianceConfigStore::validUrl((string)$row->published_url)
                ? $row->published_url : null;
            $documents[] = ['type'=>$type,'enabled'=>$row ? (bool)$row->is_enabled : true,
                'usePlatformTemplate'=>!$row || $row->source_mode === 'platform',
                'source'=>['kind'=>'url','url'=>$url]];
        }
        $template = $this->nullable($brand?->template_id ?? null);
        if ($template !== null && !in_array($template, ['template-1','template-2'], true)) throw new \RuntimeException('不支持的站点模板');
        if (!in_array($tenant->status, ['enabled','disabled'], true) || !in_array($site->status, ['enabled','disabled'], true)) throw new \RuntimeException('站点状态无效');
        return ['siteId'=>(string)$id,'name'=>$site->site_name,
            'bindings'=>$domains->map(fn ($d)=>['domain'=>$d->domain,'isPrimary'=>(bool)$d->is_primary])->all(),
            'access'=>['siteAvailability'=>$tenant->status === 'enabled' && $site->status === 'enabled' ? 'active' : 'inactive',
                'modules'=>['contract'=>$flag('contract','business')?'enabled':'disabled','prediction'=>$flag('seconds','business')?'enabled':'disabled']],
            'config'=>['templateId'=>$template,'brand'=>['logoUrl'=>$this->nullable($brand?->logo_url ?? null),'faviconUrl'=>$this->nullable($brand?->favicon_url ?? null)],
                'seo'=>['indexingRequested'=>(bool)($seo?->indexing_enabled ?? false),'homeByLocale'=>(object)$home,
                    'ogImageUrl'=>$this->nullable($seo?->og_image_url ?? null),'googleSiteVerification'=>$verification],
                'features'=>$features,'channels'=>['support'=>$support,'social'=>$links,'downloads'=>['appStore'=>$downloads['app_store'] ?? null,
                    'googlePlay'=>$downloads['google_play'] ?? null,'apk'=>$downloads['apk'] ?? null]],
                'supportEmail'=>$this->nullable($profile?->support_email ?? null),
                'legal'=>['entity'=>['WHITE_LABEL_NAME'=>$this->nullable($tenant->tenant_name ?? null),
                    'LEGAL_ENTITY_NAME'=>$this->nullable($profile?->legal_entity_name ?? null),
                    'REGISTRATION_NO'=>$this->nullable($profile?->registration_number ?? null),
                    'REGISTERED_ADDRESS'=>$this->nullable($profile?->registered_address ?? null),
                    'EFFECTIVE_DATE'=>$this->nullable($profile?->effective_date ?? null),
                    'SUPPORT_EMAIL'=>$this->nullable($profile?->support_email ?? null)],'documents'=>$documents]]];
    }
}
