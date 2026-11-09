<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantSeo;

use SixMm\Shared\Cms\TranslationCatalog;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\ValidationException;

final class SeoConfigStore
{
    public function __construct(private ConnectionInterface $db, private bool $enabledSitesOnly = false, private ?string $schema = null)
    {
        if ($schema !== null && !preg_match('/^[a-z_][a-z0-9_]*$/D', $schema)) throw new \InvalidArgumentException('Invalid SEO schema');
    }

    private function name(string $name): string
    {
        return ($this->schema !== null ? $this->schema . '.' : ($this->db->getDriverName() === 'pgsql' ? 'control_plane.' : '')) . $name;
    }

    private function table(string $name): Builder { return $this->db->table($this->name($name)); }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['seo' => $message]);
    }

    private function query(): Builder
    {
        return $this->table('wl_sites as s')
            ->join($this->name('wl_tenants').' as t', 't.tenant_id', '=', 's.tenant_id')
            ->leftJoin($this->name('wl_site_seo_configs').' as c', 'c.site_id', '=', 's.site_id')
            ->when($this->enabledSitesOnly, fn ($q) => $q->where('s.status', 'enabled')->where('t.status', 'enabled'))
            ->select(['s.site_id', 's.site_name', 's.status as site_status', 't.tenant_id', 't.tenant_name',
                't.status as tenant_status', 'c.indexing_enabled', 'c.default_locale', 'c.translations',
                'c.google_verification_token', 'c.verification_domain', 'c.version', 'c.updated_at'])
            ->selectSub($this->table('wl_site_domains')->select('domain')->whereColumn('site_id', 's.site_id')
                ->where('is_primary', true)->limit(1), 'primary_domain');
    }

    private function normalized(object $row, bool $detail = false): array
    {
        $translations = $row->translations ? json_decode($row->translations, true, 512, JSON_THROW_ON_ERROR) : [];
        $complete = array_filter($translations, fn ($copy) => trim($copy['title'] ?? '') !== '' && trim($copy['description'] ?? '') !== '');
        $enabled = (bool) $row->indexing_enabled;
        $defaultLocale = $row->default_locale ?? 'zh-CN';
        $reason = match (true) {
            $row->tenant_status !== 'enabled' => '白标未启用',
            $row->site_status !== 'enabled' => '站点未启用',
            !$row->primary_domain => '未配置主域名',
            !isset($complete[$defaultLocale]) => '默认语言文案未完整配置',
            default => null,
        };
        $data = ['tenant_id' => (string) $row->tenant_id, 'tenant_name' => $row->tenant_name,
            'site_id' => (string) $row->site_id, 'site_name' => $row->site_name,
            'primary_domain' => $row->primary_domain, 'indexing_enabled' => $enabled,
            'effective_indexing_enabled' => $enabled && $reason === null,
            'blocking_reason' => $reason, 'configured_locales' => count($complete),
            'total_locales' => count(TranslationCatalog::LOCALES), 'updated_at' => $row->updated_at];
        if (!$detail) return $data;
        return $data + ['default_locale' => $defaultLocale, 'translations' => (object) $translations,
            'og_image_url' => $row->og_image_url,
            'google_verification_token' => $row->google_verification_token,
            'verification_domain' => $row->verification_domain,
            'verification_domain_matches' => $row->google_verification_token !== null && $row->primary_domain !== null
                && $row->verification_domain === $row->primary_domain,
            'version' => (int) ($row->version ?? 0),
            'locales' => collect(TranslationCatalog::LOCALES)->map(fn ($locale, $code) => [
                'value' => $code, 'label' => $locale[0].' · '.$code,
            ])->values()->all()];
    }


    private function id(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,18}$/D', (string) $value)
            || (strlen((string) $value) === 19 && strcmp((string) $value, '9223372036854775807') > 0)) $this->reject('白标或站点参数不正确');
        return (string) $value;
    }

    /** Used only after the host has derived the tenant from authentication. Never accepts an arbitrary browser tenant. */
    public function sites(string $tenantId): array
    {
        $this->id($tenantId);
        return $this->table('wl_sites as s')->join($this->name('wl_tenants').' as t', 't.tenant_id', '=', 's.tenant_id')
            ->where('s.tenant_id', $tenantId)
            ->when($this->enabledSitesOnly, fn ($q) => $q->where('s.status', 'enabled')->where('t.status', 'enabled'))
            ->orderBy('s.site_id')->get(['s.site_id', 's.site_name'])
            ->map(fn ($site) => ['site_id' => (string) $site->site_id, 'site_name' => $site->site_name])->all();
    }

    public static function rules(string $action): array
    {
        $scope = ['tenant_id' => 'required|integer|min:1|max:9223372036854775807', 'site_id' => 'required|integer|min:1|max:9223372036854775807'];
        return match ($action) {
            'detail', 'translationContext' => $scope,
            'translate' => $scope + ['source_locale' => 'required|string|max:35', 'target_locale' => 'required|string|max:35',
                'fields' => 'required|array:title,description|min:1|max:2',
                'fields.title' => 'sometimes|required|string|max:200', 'fields.description' => 'sometimes|required|string|max:1000'],
            'save' => $scope + ['expected_version' => 'required|integer|min:0|max:9223372036854775806',
                'indexing_enabled' => ['required', static function ($key, $value, $fail): void { if (!is_bool($value)) $fail('收录开关必须为布尔值'); }],
                'default_locale' => 'required|string|max:35', 'translations' => 'present|array|max:20',
                'translations.*' => 'array:title,description', 'translations.*.title' => 'present|nullable|string|max:200',
                'translations.*.description' => 'present|nullable|string|max:1000',
                'og_image_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url', 'regex:~^https://[^/@?#\s]+(?:[/?#][^\s]*)?$~iD'],
                'google_verification_token' => ['present', 'nullable', 'string', 'max:512', 'regex:/\\A[A-Za-z0-9_-]+\\z/'],
                'verification_domain' => 'present|nullable|string|max:253'],
        };
    }

    public function listing(array $input): array
    {
        $query = $this->query();
        $keyword = trim($input['keyword'] ?? '');
        if ($keyword !== '') {
            $escaped = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
            $query->where(function (Builder $q) use ($keyword, $escaped): void {
                $q->whereRaw("t.tenant_name LIKE ? ESCAPE '!'", [$escaped]);
                if (ctype_digit($keyword) && strlen($keyword) < 19) $q->orWhere('t.tenant_id', $keyword);
            });
        }
        $count = (clone $query)->count();
        $rows = $query->orderBy('t.tenant_id')->orderBy('s.site_id')
            ->forPage($input['page_no'] ?? 1, $input['page_size'] ?? 20)->get();
        return ['lists' => $rows->map(fn ($row) => $this->normalized($row))->all(), 'count' => $count];
    }

    public function detail(array $input): array
    {
        foreach (['tenant_id', 'site_id'] as $key) $this->id($input[$key] ?? null);
        $row = $this->query()->addSelect('c.og_image_url')->where('t.tenant_id', $input['tenant_id'])->where('s.site_id', $input['site_id'])->first();
        if (!$row) $this->reject('白标站点不存在或不属于该白标');
        return $this->normalized($row, true);
    }

    public function save(array $input, int $operatorId, string $actorType, callable $audit): array
    {
        if ($operatorId <= 0 || !in_array($actorType, ['platform_admin', 'tenant_admin'], true)) throw new \InvalidArgumentException('Invalid SEO actor');
        $input = \Illuminate\Support\Facades\Validator::make($input, self::rules('save'))->validate();
        foreach (['tenant_id', 'site_id'] as $key) $this->id($input[$key]);
        if (!isset(TranslationCatalog::LOCALES[$input['default_locale']])) $this->reject('不支持的默认语言');
        $translations = [];
        foreach ($input['translations'] as $locale => $copy) {
            if (!isset(TranslationCatalog::LOCALES[$locale])) $this->reject('包含不支持的文案语言');
            $title = trim($copy['title'] ?? '');
            $description = trim($copy['description'] ?? '');
            if ($title !== '' || $description !== '') $translations[$locale] = compact('title', 'description');
        }
        ksort($translations);
        $default = $translations[$input['default_locale']] ?? [];
        if ($input['indexing_enabled'] && (($default['title'] ?? '') === '' || ($default['description'] ?? '') === '')) {
            $this->reject('允许收录前，请填写默认语言的首页标题和描述');
        }
        return $this->db->transaction(function () use ($input, $operatorId, $actorType, $audit, $translations): array {
            if ($this->db->getDriverName() === 'pgsql') {
                $this->db->statement("SET LOCAL lock_timeout = '5s'");
                $this->db->statement("SET LOCAL statement_timeout = '15s'");
            }
            // Lock the parent even before a config exists, serializing concurrent first saves.
            $tenant = $this->table('wl_tenants')->where('tenant_id', $input['tenant_id'])->lockForUpdate()->first();
            $site = $this->table('wl_sites')->where('site_id', $input['site_id'])->where('tenant_id', $input['tenant_id'])->lockForUpdate()->first();
            if (!$tenant || !$site) $this->reject('白标站点不存在或不属于该白标');
            $before = $this->detail($input);
            if ($before['version'] !== (int) $input['expected_version']) $this->reject('配置已被其他操作员修改，请重新打开配置后再保存');
            $token = $input['google_verification_token'] === '' ? null : $input['google_verification_token'];
            $domain = $token !== null ? strtolower(trim($input['verification_domain'] ?? '')) : null;
            if ($token !== null && (!$before['primary_domain'] || $domain !== $before['primary_domain'])) {
                $this->reject('Google 验证令牌必须绑定当前主域名，请清除旧令牌或重新配置');
            }
            $values = ['indexing_enabled' => $input['indexing_enabled'], 'default_locale' => $input['default_locale'],
                'translations' => (object) $translations, 'google_verification_token' => $token, 'verification_domain' => $domain];
            // Older clients do not send this site-wide setting; only an explicit null clears it.
            if (array_key_exists('og_image_url', $input)) $values['og_image_url'] = $input['og_image_url'] ?: null;
            $same = true;
            foreach ($values as $key => $value) {
                if ($before[$key] != $value) $same = false;
            }
            if ($same) return ['changed' => false, 'version' => $before['version']];
            $now = \Carbon\CarbonImmutable::now()->format('Y-m-d H:i:s.uP');
            $version = $before['version'] + 1;
            $values['translations'] = json_encode((object) $translations, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $values += ['version' => $version, 'updated_by' => $operatorId, 'updated_by_type' => $actorType, 'updated_at' => $now];
            if ($before['version'] === 0) {
                $this->table('wl_site_seo_configs')->insert($values + ['site_id' => $site->site_id,
                    'created_by' => $operatorId, 'created_by_type' => $actorType, 'created_at' => $now]);
            } else {
                $this->table('wl_site_seo_configs')->where('site_id', $site->site_id)->update($values);
            }
            $audit([
                'tenant_id' => (string) $tenant->tenant_id, 'site_id' => (string) $site->site_id,
                'actor_type' => $actorType, 'previous_version' => $before['version'], 'version' => $version,
                'indexing_enabled' => $input['indexing_enabled'], 'default_locale' => $input['default_locale'],
                'locales' => array_keys($translations), 'verification_configured' => $token !== null,
            ], $now);
            return ['changed' => true, 'version' => $version];
        }, 3);
    }
}
