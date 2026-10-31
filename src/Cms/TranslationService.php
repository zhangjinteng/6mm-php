<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TranslationService
{
    private Connection $db;
    private ?array $allowedSpaceIds;

    public function __construct(?Connection $db = null, ?array $allowedSpaceIds = null)
    {
        $this->db = $db ?? DB::connection('pgsql');
        $this->allowedSpaceIds = $allowedSpaceIds;
    }

    public function connection(): Connection
    {
        return $this->db;
    }

    public function metadata(?int $spaceId): array
    {
        $spaces = $this->db->table('cms.spaces as s')->leftJoin('control_plane.wl_sites as ws', 'ws.site_id', '=', 's.home_site_id')->leftJoin('control_plane.wl_tenants as wt', 'wt.tenant_id', '=', 'ws.tenant_id')->where(function (Builder $q): void {
            $q->where('s.space_type', 'site')->orWhere(function (Builder $q): void {
                $q->where('s.space_type', 'template')->where('s.template_key', 'default');
            });
        })->when($this->allowedSpaceIds !== null, fn (Builder $q) => $q->whereIn('s.id', $this->allowedSpaceIds)->where('s.space_type', 'site'))
            ->orderByRaw("CASE WHEN s.space_type='template' THEN 0 ELSE 1 END")->orderBy('s.id')->get([
            's.id',
            's.space_type',
            's.name',
            's.is_enabled',
            'wt.tenant_name',
        ])->map(static fn (object $s): array => [
            'id' => (string) $s->id,
            'label' => $s->space_type === 'template' ? '平台内容' : ($s->tenant_name ?: $s->name) . ' · ' . $s->id,
            'is_enabled' => (bool) $s->is_enabled,
        ])->all();
        if ($spaceId === null && $spaces) {
            $spaceId = (int) $spaces[0]['id'];
        }
        $space = $spaceId === null ? null : $this->space($spaceId);
        $targets = $space ? $this->targets($space) : [];
        $modules = [];
        $total = 0;
        $done = 0;
        $languageDone = array_fill_keys($targets, 0);
        foreach (TranslationCatalog::MODULES as $module => [$label, $icon]) {
            foreach (['content', 'category'] as $kind) {
                $spec = TranslationCatalog::spec($module, $kind);
                $count = $space ? $this->entities($spec, $spaceId, $module)->count() : 0;
                $stats = $space ? $this->stats($spec, $spaceId, $module, $targets) : ['fields' => 0, 'locales' => []];
                $expected = $stats['fields'] * count($targets);
                $completed = array_sum($stats['locales']);
                $modules[] = [
                    'key' => $module . ':' . $kind,
                    'module' => $module,
                    'kind' => $kind,
                    'label' => $kind === 'category' ? '分类' : $label,
                    'icon' => $icon,
                    'count' => $count,
                    'total' => $expected,
                    'done' => $completed,
                    'available' => true,
                ];
                $total += $expected;
                $done += $completed;
                foreach ($targets as $locale) {
                    $languageDone[$locale] += $stats['locales'][$locale] ?? 0;
                }
            }
        }
        foreach (['popup' => '弹窗公告', 'top_banner' => '顶部通知', 'maintenance' => '全屏维护'] as $type => $label) {
            $modules[] = [
                'key' => $type,
                'module' => $type,
                'kind' => 'display',
                'label' => $label,
                'icon' => 'info',
                'count' => 0,
                'total' => 0,
                'done' => 0,
                'available' => false,
                'reason' => '公告展示模块尚未接入，暂不参与翻译统计',
            ];
        }
        $perLanguage = count($targets) ? intdiv($total, count($targets)) : 0;
        return [
            'spaces' => $spaces,
            'space_id' => $space ? (string) $space->id : null,
            'space_revision' => $space ? (string) $space->revision : null,
            'space_enabled' => $space ? (bool) $space->is_enabled : false,
            'source_locale' => $space->source_locale ?? 'zh-CN',
            'target_locales' => $targets,
            'locales' => array_map(static fn (string $code, array $v): array => [
                'code' => $code,
                'label' => $v[0],
                'native' => $v[1],
                'flag' => $v[2],
                'done' => $languageDone[$code] ?? 0,
                'total' => in_array($code, $targets, true) ? $perLanguage : 0,
            ], array_keys(TranslationCatalog::LOCALES), TranslationCatalog::LOCALES),
            'modules' => $modules,
            'done' => $done,
            'total' => $total,
        ];
    }

    public function listing(array $p): array
    {
        $spec = TranslationCatalog::spec($p['module'], $p['kind']);
        $space = $this->space((int) $p['space_id']);
        $q = $this->entities($spec, (int) $space->id, $p['module']);
        if (!empty($p['keyword'])) {
            $q->where($p['kind'] === 'category' ? 'name' : 'title', 'ILIKE', '%' . $p['keyword'] . '%');
        }
        $count = (clone $q)->count();
        $page = (int) ($p['page_no'] ?? 1);
        $size = 30;
        $rows = $q->orderBy('sort_order')->orderByDesc('id')->forPage($page, $size)->get()->all();
        $translations = $this->translationRows($spec, (int) $space->id, array_column($rows, 'id'));
        return [
            'lists' => array_map(fn (object $row): array => $this->summary($spec, $row, $translations, $this->targets($space)), $rows),
            'count' => $count,
            'page_size' => $size,
        ];
    }

    public function detail(array $p): array
    {
        $spec = TranslationCatalog::spec($p['module'], $p['kind']);
        $space = $this->space((int) $p['space_id']);
        $row = $this->entity($p, $spec);
        $targets = $this->targets($space);
        $translations = $this->translationRows($spec, (int) $space->id, [$row->id]);
        $fields = [];
        foreach ($spec['fields'] as $field) {
            $source = (string) ($row->{$field} ?? '');
            $hash = TranslationCatalog::sourceHash($source);
            $values = [];
            foreach ($targets as $locale) {
                $t = $translations[$row->id][$field][$locale] ?? null;
                $values[] = [
                    'locale' => $locale,
                    'text' => $t->{$spec['text']} ?? '',
                    'row_version' => $t ? (string) $t->row_version : '0',
                    'status' => !$t ? 'missing' : ($t->source_hash === $hash ? $t->status : 'stale'),
                    'method' => $t->translation_method ?? null,
                ];
            }
            $fields[] = [
                'key' => $field,
                'label' => TranslationCatalog::FIELD_LABELS[$field],
                'source' => $source,
                'source_hash' => $hash,
                'max_length' => TranslationCatalog::LIMITS[$field],
                'translatable' => trim($source) !== '',
                'translations' => $values,
            ];
        }
        return $this->summary($spec, $row, $translations, $targets) + ['fields' => $fields, 'space_revision' => (string) $space->revision, 'source_locale' => $space->source_locale];
    }

    public function save(array $p, array $actor): array
    {
        $spec = TranslationCatalog::spec($p['module'], $p['kind']);
        return $this->db->transaction(function () use ($p, $actor, $spec): array {
            $space = $this->space((int) $p['space_id'], true);
            $this->writable($space);
            $row = $this->entity($p, $spec, true);
            $seen = [];
            foreach ($p['items'] as $item) {
                $field = $item['field_name'];
                $locale = $item['locale'];
                if (isset($seen[$field . ':' . $locale])) {
                    $this->fail('同一语言字段不能重复提交');
                }
                $seen[$field . ':' . $locale] = true;
                $this->checkTarget($space, $spec, $field, $locale);
                $source = (string) ($row->{$field} ?? '');
                if (TranslationCatalog::sourceHash($source) !== $item['source_hash']) {
                    $this->fail('原文已变化，请重新加载后再保存');
                }
                $q = $this->targetQuery($spec, (int) $space->id, (int) $row->id, $field, $locale);
                $old = (clone $q)->lockForUpdate()->first();
                if ((string) ($old->row_version ?? 0) !== (string) $item['row_version']) {
                    $this->fail('译文已被其他人或任务修改，请重新加载');
                }
                $text = (string) ($item['text'] ?? '');
                if (trim($text) === '') {
                    // Keep a versioned tombstone so delete/recreate cannot pass an old optimistic-lock token.
                    // Category/content schemas prohibit blank translations, so explicit clearing is not allowed.
                    $this->fail('译文不能为空；未翻译的语言请保持未填写');
                }
                $text = $this->normalize($field, $text);
                if (trim($source) === '') {
                    $this->fail('原文字段为空，不能保存对应译文');
                }
                $data = [
                    $spec['text'] => $text,
                    'source_hash' => $item['source_hash'],
                    'status' => 'ready',
                    'translation_method' => 'manual',
                    'row_version' => (int) ($old->row_version ?? 0) + 1,
                ] + $this->audit($actor);
                if ($old) {
                    $q->update($data);
                } else {
                    $this->db->table('cms.' . $spec['translations'])->insert($data + ['space_id' => $space->id, $spec['fk'] => $row->id, 'locale' => $locale] + ($spec['fk'] === 'content_id' ? ['field_name' => $field] : []) + $this->audit($actor, true));
                }
            }
            $this->bump($space, $actor);
            return $this->detail($p);
        });
    }

    public function settings(int $spaceId): array
    {
        $space = $this->space($spaceId);
        $setting = $space->translation_setting_id ? $this->db->table('cms.translation_settings')->where('id', $space->translation_setting_id)->first() : null;
        return [
            'space_id' => (string) $space->id,
            'space_revision' => (string) $space->revision,
            'target_locales' => $this->targets($space),
            'provider' => 'google',
            'api_version' => 'v2',
            'credential_ref' => config('cms_translation.credential_ref'),
            'credential_configured' => (new GoogleTranslationClient())->configured(),
            'supported' => !$setting || $setting->provider === 'google' && $setting->api_version === 'v2',
            'is_enabled' => (bool) ($setting->is_enabled ?? false) && $setting->provider === 'google' && $setting->api_version === 'v2',
            'concurrency_limit' => (int) ($setting->concurrency_limit ?? 3),
            'request_timeout_seconds' => (int) ($setting->request_timeout_seconds ?? 30),
            'max_attempts' => (int) ($setting->max_attempts ?? 3),
        ];
    }

    public function saveSettings(array $p, array $actor, GoogleTranslationClient $google): array
    {
        $before = $this->space((int) $p['space_id']);
        $this->writable($before);
        if ((string) $before->revision !== (string) $p['space_revision']) {
            $this->fail('空间配置已变化，请重新加载配置');
        }
        if (in_array($before->source_locale, $p['target_locales'], true)) {
            $this->fail('目标语言不能包含源语言');
        }
        if ($p['is_enabled']) {
            $google->translate('连接测试', 'zh-CN', 'en', false, (int) $p['request_timeout_seconds']);
        }
        return $this->db->transaction(function () use ($p, $actor): array {
            $space = $this->space((int) $p['space_id'], true);
            $this->writable($space);
            if ((string) $space->revision !== (string) $p['space_revision']) {
                $this->fail('空间配置已变化，请重新加载配置');
            }
            if (in_array($space->source_locale, $p['target_locales'], true)) {
                $this->fail('目标语言不能包含源语言');
            }
            // Each space owns its settings; do not silently mutate another space's shared config.
            $key = 'content-space-' . $space->id;
            $old = $this->db->table('cms.translation_settings')->where('config_key', $key)->lockForUpdate()->first();
            $data = [
                'provider' => 'google',
                'api_version' => 'v2',
                'credential_ref' => config('cms_translation.credential_ref'),
                'project_id' => null,
                'is_enabled' => $p['is_enabled'],
                'concurrency_limit' => $p['concurrency_limit'],
                'request_timeout_seconds' => $p['request_timeout_seconds'],
                'max_attempts' => $p['max_attempts'],
            ] + $this->audit($actor);
            if ($old) {
                $id = $old->id;
                $this->db->table('cms.translation_settings')->where('id', $id)->update($data);
            } else {
                $id = $this->db->table('cms.translation_settings')->insertGetId($data + ['config_key' => $key] + $this->audit($actor, true));
            }
            $this->db->table('cms.spaces')->where('id', $space->id)->update([
                'enabled_locales' => $this->pgArray(array_merge([$space->source_locale], $p['target_locales'])),
                'translation_setting_id' => $id,
            ]);
            $this->bump($space, $actor);
            return $this->settings((int) $space->id);
        });
    }

    public function space(int $id, bool $lock = false): object
    {
        if ($this->allowedSpaceIds !== null && !in_array($id, $this->allowedSpaceIds, true)) $this->fail('内容空间不存在');
        $q = $this->db->table('cms.spaces')->where('id', $id)->where(function (Builder $q): void {
            $q->where('space_type', 'site')->orWhere(function (Builder $q): void {
                $q->where('space_type', 'template')->where('template_key', 'default');
            });
        })->when($this->allowedSpaceIds !== null, fn (Builder $q) => $q->where('space_type', 'site'))->selectRaw('*, array_to_json(enabled_locales)::text as locales_json');
        $s = ($lock ? $q->lockForUpdate() : $q)->first();
        if (!$s) {
            $this->fail('内容空间不存在');
        }
        return $s;
    }

    public function targets(object $space): array
    {
        return array_values(array_filter(json_decode($space->locales_json, true) ?: [], static fn (string $locale): bool => $locale !== $space->source_locale && isset(TranslationCatalog::LOCALES[$locale])));
    }

    public function entities(array $spec, int $space, string $module): Builder
    {
        return $this->db->table('cms.' . $spec['table'])->where('space_id', $space)->where('module', $module)->whereNull('deleted_at');
    }

    public function entity(array $p, array $spec, bool $lock = false): object
    {
        $q = $this->entities($spec, (int) $p['space_id'], $p['module'])->where('id', $p['id']);
        $row = ($lock ? $q->lockForUpdate() : $q)->first();
        if (!$row) {
            $this->fail('翻译对象不存在或不属于当前空间');
        }
        return $row;
    }

    public function targetQuery(array $spec, int $space, int $id, string $field, string $locale): Builder
    {
        $q = $this->db->table('cms.' . $spec['translations'])->where('space_id', $space)->where($spec['fk'], $id)->where('locale', $locale);
        return $spec['fk'] === 'content_id' ? $q->where('field_name', $field) : $q;
    }

    public function checkTarget(object $space, array $spec, string $field, string $locale): void
    {
        if (!in_array($field, $spec['fields'], true) || !in_array($locale, $this->targets($space), true)) {
            $this->fail('字段或目标语言未启用');
        }
    }

    public function normalize(string $field, string $text): string
    {
        if (mb_strlen($text) > TranslationCatalog::LIMITS[$field]) {
            $this->fail('译文超过字段长度限制');
        }
        $text = $field === 'body_html' ? (new AcademyService())->sanitize($text) : trim($text);
        if (trim($text) === '') {
            $this->fail('译文清理后不能为空');
        }
        return $text;
    }

    public function writable(object $space): void
    {
        if (!$space->is_enabled) {
            $this->fail('内容空间已停用，不能修改译文或发起翻译');
        }
    }

    public function audit(array $actor, bool $create = false): array
    {
        $a = [
            'updated_at' => now(),
            'updated_by_type' => $actor['type'] ?? 'platform',
            'updated_by_id' => $actor['id'],
            'updated_by_name' => $actor['name'],
        ];
        return $create ? $a + ['created_at' => now(), 'created_by_type' => $actor['type'] ?? 'platform', 'created_by_id' => $actor['id']] : $a;
    }

    public function bump(object $space, array $actor): void
    {
        $this->db->table('cms.spaces')->where('id', $space->id)->update(['revision' => (int) $space->revision + 1] + $this->audit($actor));
    }

    public function pgArray(array $values): string
    {
        return '{' . implode(',', $values) . '}';
    }

    public function fail(string $message): void
    {
        throw ValidationException::withMessages(['translation' => $message]);
    }

    public function translationRows(array $spec, int $space, array $ids): array
    {
        $result = [];
        if (!$ids) {
            return $result;
        }
        foreach ($this->db->table('cms.' . $spec['translations'])->where('space_id', $space)->whereIn($spec['fk'], $ids)->get() as $t) {
            $result[$t->{$spec['fk']}][$t->field_name ?? 'name'][$t->locale] = $t;
        }
        return $result;
    }

    private function summary(array $spec, object $row, array $translations, array $targets): array
    {
        $done = 0;
        $total = 0;
        foreach ($spec['fields'] as $field) {
            $source = (string) ($row->{$field} ?? '');
            if (trim($source) === '') {
                continue;
            }
            foreach ($targets as $locale) {
                $total++;
                $t = $translations[$row->id][$field][$locale] ?? null;
                if ($t && $t->status === 'ready' && $t->source_hash === TranslationCatalog::sourceHash($source)) {
                    $done++;
                }
            }
        }
        return [
            'id' => (string) $row->id,
            'space_id' => (string) $row->space_id,
            'code' => $row->code,
            'title' => $row->title ?? $row->name,
            'publish_status' => $row->publish_status ?? null,
            'done' => $done,
            'total' => $total,
        ];
    }

    private function stats(array $spec, int $space, string $module, array $locales): array
    {
        $values = implode(',', array_map(static fn (string $f): string => "('{$f}', e.{$f}::text)", $spec['fields']));
        $sql = "SELECT e.id, e.space_id, v.field_name, v.source FROM cms.{$spec['table']} e\n            CROSS JOIN LATERAL (VALUES {$values}) v(field_name, source)\n            WHERE e.space_id = ? AND e.module = ? AND e.deleted_at IS NULL AND btrim(coalesce(v.source, '')) <> ''";
        $fields = (int) $this->db->selectOne("SELECT count(*) as n FROM ({$sql}) f", [$space, $module])->n;
        if (!$locales || !$fields) {
            return ['fields' => $fields, 'locales' => []];
        }
        $marks = implode(',', array_fill(0, count($locales), '?'));
        $join = $spec['fk'] === 'content_id' ? 'AND t.field_name=f.field_name' : '';
        $rows = $this->db->select("SELECT t.locale,count(*) as n FROM ({$sql}) f\n            JOIN cms.{$spec['translations']} t ON t.space_id=f.space_id AND t.{$spec['fk']}=f.id {$join}\n            WHERE t.locale IN ({$marks}) AND t.status='ready'\n            AND t.source_hash=encode(sha256(convert_to(f.source,'UTF8')),'hex') GROUP BY t.locale", array_merge([$space, $module], $locales));
        return ['fields' => $fields, 'locales' => array_column($rows, 'n', 'locale')];
    }
}
