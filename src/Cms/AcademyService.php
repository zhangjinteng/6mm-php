<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AcademyService
{
    use HasCmsSourceScope;

    private function db(): Connection { return DB::connection('pgsql'); }

    private function space(): ?object
    {
        if ($this->cmsSourceScope?->selected !== null) return $this->cmsSourceScope->selected;
        return $this->db()->table('cms.spaces')->where('space_type', 'template')->where('template_key', 'default')->first();
    }

    private function scoped(string $table, ?object $space): Builder
    {
        $query = $this->db()->table('cms.'.$table)->where('module', 'academy')->whereNull('deleted_at');
        return $this->cmsSourceScope?->browse
            ? $this->cmsSourceScope->apply($query)
            : $query->where('space_id', $space->id ?? -1);
    }

    private function audit(array $actor, bool $create = false): array
    {
        $data = ['updated_at' => now(), 'updated_by_type' => $actor['type'] ?? 'platform',
            'updated_by_id' => $actor['id'], 'updated_by_name' => $actor['name']];
        return $create ? $data + ['created_at' => now(), 'created_by_type' => $actor['type'] ?? 'platform', 'created_by_id' => $actor['id']] : $data;
    }

    private function mutate(array $actor, callable $callback): array
    {
        return $this->db()->transaction(function () use ($actor, $callback) {
            // Serialize template changes, including its first creation and category/course edits.
            $this->db()->select('SELECT pg_advisory_xact_lock(60428, 1)');
            $space = $this->space();
            if (!$space) {
                $id = $this->db()->table('cms.spaces')->insertGetId([
                    'space_type' => 'template', 'template_key' => 'default', 'name' => '平台默认内容模板',
                ] + $this->audit($actor, true));
                $space = $this->db()->table('cms.spaces')->where('id', $id)->first();
            }
            $space = $this->db()->table('cms.spaces')->where('id', $space->id)->lockForUpdate()->first();
            if (!$space->is_enabled) $this->fail('内容模板空间已停用');
            $result = $callback($space);
            $this->db()->table('cms.spaces')->where('id', $space->id)->update([
                'revision' => $space->revision + 1,
            ] + $this->audit($actor));
            return $result;
        });
    }

    private function fail(string $message): void
    {
        throw ValidationException::withMessages(['academy' => $message]);
    }

    public function metadata(): array
    {
        $space = $this->space();
        return [
            'source_locale' => $space->source_locale ?? 'zh-CN',
            'cms_source_options' => $this->cmsSourceOptions(),
            'enabled_locales' => $space ? json_decode($this->db()->table('cms.spaces')->where('id', $space->id)->selectRaw('to_json(enabled_locales) AS locales')->value('locales'), true) : ['zh-CN'],
            'categories' => $this->scoped('categories', $space)->select(['id', 'name', 'is_enabled'])
                ->selectRaw('to_json(difficulty_levels) AS difficulty_levels')->orderBy('sort_order')->orderBy('id')->get()->map(fn ($r) => $this->row($r))->all(),
        ];
    }

    public function listing(string $kind, array $input): array
    {
        $p = Validator::make($input, [
            'page_no' => 'sometimes|integer|between:1,1000000', 'page_size' => 'sometimes|integer|between:1,100',
            'code' => 'nullable|string|max:64', 'keyword' => 'nullable|string|max:120',
            'difficulty_level' => 'nullable|integer|between:1,3', 'is_enabled' => 'nullable|boolean',
            'category_id' => 'nullable|integer|min:1', 'topic_id' => 'prohibited',
            'publish_status' => 'nullable|in:draft,scheduled,published',
            'published_from' => 'nullable|date', 'published_to' => 'nullable|date|after_or_equal:published_from',
        ])->validate();
        if (!in_array($kind, ['courses', 'categories'], true)) $this->fail('不支持的学院数据类型');
        $table = $kind === 'courses' ? 'contents' : 'categories';
        $query = $this->scoped($table, $this->space());
        if (!empty($p['code'])) $query->where('code', 'ilike', '%'.$this->escapeLike(trim($p['code'])).'%');
        if (!empty($p['keyword'])) $query->where($kind === 'courses' ? 'title' : 'name', 'ilike', '%'.$this->escapeLike(trim($p['keyword'])).'%');
        if (isset($p['is_enabled'])) $query->where('is_enabled', (bool) $p['is_enabled']);
        if (!empty($p['difficulty_level'])) {
            $kind === 'courses' ? $query->where('difficulty_level', $p['difficulty_level'])
                : $query->whereRaw('? = ANY(difficulty_levels)', [$p['difficulty_level']]);
        }
        if ($kind === 'courses') {
            if (!empty($p['publish_status'])) $query->where('publish_status', $p['publish_status']);
            if (!empty($p['category_id'])) $query->whereIn('id', $this->db()->table('cms.content_categories')->select('content_id')->where('category_id', $p['category_id'])->whereIn('space_id', $this->cmsSourceScope?->ids() ?? [$this->space()->id ?? -1]));
            if (!empty($p['published_from'])) $query->whereRaw('coalesce(published_at, scheduled_at) >= ?', [$p['published_from']]);
            if (!empty($p['published_to'])) $query->whereRaw('coalesce(published_at, scheduled_at) <= ?', [$p['published_to']]);
        }
        $count = (clone $query)->count();
        $query->select($table.'.*');
        if ($kind === 'categories') $query->selectRaw('to_json(difficulty_levels) AS difficulty_levels, (SELECT count(*) FROM cms.content_categories cc JOIN cms.contents c ON c.id = cc.content_id AND c.space_id = cc.space_id WHERE cc.space_id = categories.space_id AND cc.category_id = categories.id AND c.deleted_at IS NULL) AS course_count');
        if ($kind === 'courses') $query->selectRaw("(SELECT coalesce(json_agg(json_build_object('id', c.id::text, 'name', c.name) ORDER BY cc.sort_order, cc.category_id), '[]'::json) FROM cms.content_categories cc JOIN cms.categories c ON c.id = cc.category_id AND c.space_id = cc.space_id WHERE cc.content_id = contents.id AND cc.space_id = contents.space_id) AS categories");
        $rows = $query->orderBy('sort_order')->orderByDesc('id')->forPage((int) ($p['page_no'] ?? 1), (int) ($p['page_size'] ?? 20))->get();
        return ['lists' => $rows->map(function ($row) {
            $data = $this->row($row);
            unset($data['body_html']);
            return $data;
        })->all(), 'count' => $count];
    }

    private function escapeLike(string $text): string { return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text); }

    private function row(object $row): array
    {
        $data = (array) $row;
        foreach (['id', 'space_id', 'row_version'] as $key) if (isset($data[$key])) $data[$key] = (string) $data[$key];
        if (isset($data['difficulty_levels']) && is_string($data['difficulty_levels'])) $data['difficulty_levels'] = json_decode($data['difficulty_levels'], true);
        if (isset($data['categories']) && is_string($data['categories'])) $data['categories'] = json_decode($data['categories'], true);
        unset($data['category_id']);
        return $this->cmsSourceRow($data);
    }

    public function detail(string $kind, int $id): array
    {
        if (!in_array($kind, ['courses', 'categories'], true)) $this->fail('不支持的学院数据类型');
        $query = $this->scoped($kind === 'courses' ? 'contents' : $kind, $this->space())->where('id', $id);
        if ($kind === 'categories') $query->select('*')->selectRaw('to_json(difficulty_levels) AS difficulty_levels');
        $row = $query->first();
        if (!$row) $this->fail('记录不存在或不在当前内容模板范围内');
        $result = $this->row($row);
        if ($kind === 'courses') {
            $result['category_ids'] = $this->db()->table('cms.content_categories')->where('space_id', $row->space_id)->where('content_id', $id)->orderBy('sort_order')->orderBy('category_id')->pluck('category_id')->map(fn ($id) => (string) $id)->all();
        }
        return $result;
    }

    private function editRow(string $table, object $space, array $p): ?object
    {
        if (empty($p['id'])) return null;
        $row = $this->scoped($table, $space)->where('id', $p['id'])->lockForUpdate()->first();
        if (!$row) $this->fail('记录不存在或不在当前内容模板范围内');
        if ((string) $row->row_version !== (string) $p['row_version']) $this->fail('资料已被其他人修改，请重新打开后编辑');
        return $row;
    }

    private function identityRules(bool $edit): array
    {
        return ['id' => $edit ? 'required|integer|min:1' : 'prohibited',
            'row_version' => $edit ? 'required|integer|min:1' : 'prohibited'];
    }

    public function saveCategory(array $input, array $actor, bool $edit): array
    {
        $rules = $this->identityRules($edit) + ['name' => 'required|string|max:120',
            'sort_order' => 'required|integer|between:0,2147483647', 'is_enabled' => 'required|boolean',
            'difficulty_levels' => 'required|array|min:1|max:3', 'difficulty_levels.*' => 'required|integer|between:1,3|distinct'];
        $p = Validator::make($input, $rules)->validate();
        if (trim($p['name']) === '') $this->fail('名称不能为空');
        return $this->mutate($actor, function ($space) use ($p, $actor) {
            $old = $this->editRow('categories', $space, $p);
            $data = ['name' => trim($p['name']), 'sort_order' => $p['sort_order'], 'is_enabled' => (bool) $p['is_enabled']];
            if ($old && $this->scoped('contents', $space)->whereIn('id', $this->db()->table('cms.content_categories')->select('content_id')->where('space_id', $space->id)->where('category_id', $old->id))->whereNotNull('difficulty_level')->whereNotIn('difficulty_level', $p['difficulty_levels'])->exists()) {
                $this->fail('分类内仍有课程使用被移除的难度，请先调整课程');
            }
            $data['difficulty_levels'] = '{'.implode(',', array_map('intval', $p['difficulty_levels'])).'}';
            if ($old) {
                $this->scoped('categories', $space)->where('id', $old->id)->update($data + ['row_version' => $old->row_version + 1] + $this->audit($actor));
                if ($old->name !== $data['name']) {
                    $this->db()->table('cms.category_translations')->where('space_id', $space->id)->where('category_id', $old->id)
                        ->update(['status' => 'stale', 'row_version' => $this->db()->raw('row_version + 1')] + $this->audit($actor));
                }
                $id = $old->id;
            } else {
                $id = $this->db()->table('cms.categories')->insertGetId($data + ['space_id' => $space->id, 'module' => 'academy',
                    'code' => 'CAT'.strtoupper(Str::random(20))] + $this->audit($actor, true));
            }
            return $this->detail('categories', (int) $id);
        });
    }

    public function deleteCategory(array $input, array $actor): array
    {
        $p = Validator::make($input, $this->identityRules(true))->validate();
        return $this->mutate($actor, function ($space) use ($p, $actor) {
            $category = $this->editRow('categories', $space, $p);
            // Keep archived course references and the legacy compatibility anchor intact.
            $hasRelations = $this->db()->table('cms.content_categories')
                ->where('space_id', $space->id)->where('category_id', $category->id)->exists();
            $hasLegacyReferences = $this->db()->table('cms.contents')
                ->where('space_id', $space->id)->where('module', 'academy')
                ->where('category_id', $category->id)->exists();
            if ($hasRelations || $hasLegacyReferences) {
                $this->fail('分类仍有关联课程（含已删除课程），请先调整课程分类后再删除');
            }
            $this->scoped('categories', $space)->where('id', $category->id)->update([
                'deleted_at' => now(), 'row_version' => $category->row_version + 1,
            ] + $this->audit($actor));
            return ['id' => (string) $category->id];
        });
    }

    public function deleteCourse(array $input, array $actor, bool $canPublish): array
    {
        $params = Validator::make($input, $this->identityRules(true))->validate();
        return $this->mutate($actor, function (object $space) use ($params, $actor, $canPublish): array {
            $course = $this->editRow('contents', $space, $params);
            if (!$canPublish && $course->publish_status !== 'draft') {
                $this->fail('没有课程发布权限，不能删除已发布或定时发布的课程');
            }
            // Preserve translations and category relations for archived content.
            $this->scoped('contents', $space)->where('id', $course->id)->update([
                'deleted_at' => now(),
                'is_enabled' => false,
                'row_version' => $course->row_version + 1,
            ] + $this->audit($actor));
            return ['id' => (string) $course->id];
        });
    }

    public function saveCourse(array $input, array $actor, bool $edit, bool $canPublish): array
    {
        $p = Validator::make($input, $this->identityRules($edit) + [
            'title' => 'required|string|max:255', 'summary' => 'nullable|string|max:10000',
            'body_html' => 'nullable|string|max:1000000', 'seo_description' => 'nullable|string|max:2000',
            'cover_url' => 'nullable|string|max:2048', 'category_id' => 'prohibited', 'topic_ids' => 'prohibited',
            'category_ids' => 'present|array|max:50', 'category_ids.*' => 'integer|min:1|distinct',
            'difficulty_level' => 'nullable|integer|between:1,3', 'estimated_minutes' => 'nullable|integer|between:1,100000',
            'sort_order' => 'required|integer|between:0,2147483647', 'is_enabled' => 'required|boolean',
            'publish_status' => 'required|in:draft,scheduled,published', 'scheduled_at' => 'nullable|date',
        ])->validate();
        if (trim($p['title']) === '') $this->fail('课程标题不能为空');
        $p['category_ids'] = array_values($p['category_ids']);
        if (!empty($p['cover_url']) && !self::safeUrl($p['cover_url'])) $this->fail('封面地址必须为 HTTP(S) 或站内绝对路径');
        $p['body_html'] = $this->sanitize((string) ($p['body_html'] ?? ''));
        return $this->mutate($actor, function ($space) use ($p, $actor, $canPublish) {
            $old = $this->editRow('contents', $space, $p);
            if (!$canPublish && ($p['publish_status'] !== 'draft' || ($old && $old->publish_status !== 'draft'))) $this->fail('没有课程发布权限，不能修改已发布或定时发布的课程');
            $data = array_intersect_key($p, array_flip(['title', 'summary', 'body_html', 'seo_description', 'cover_url', 'difficulty_level', 'estimated_minutes', 'sort_order', 'is_enabled', 'publish_status']));
            $data['title'] = trim($data['title']);
            foreach (['summary', 'seo_description', 'cover_url', 'difficulty_level', 'estimated_minutes'] as $field) $data[$field] = $data[$field] ?? null;
            // Compatibility anchor only; all Academy classification uses the relation table.
            $data['category_id'] = $p['category_ids'][0] ?? null;
            $data['is_enabled'] = (bool) $data['is_enabled'];
            $data['scheduled_at'] = null;
            $data['published_at'] = null;
            if ($data['publish_status'] === 'scheduled') {
                if (empty($p['scheduled_at']) || !CarbonImmutable::parse($p['scheduled_at'])->isFuture()) $this->fail('计划发布时间必须晚于当前时间');
                $data['scheduled_at'] = CarbonImmutable::parse($p['scheduled_at'])->utc();
            } elseif ($data['publish_status'] === 'published') {
                $data['published_at'] = $old && $old->publish_status === 'published' ? $old->published_at : now();
            }
            $this->validateCourse($space, $data, $p['category_ids']);
            if ($old) {
                $this->scoped('contents', $space)->where('id', $old->id)->update($data + ['row_version' => $old->row_version + 1] + $this->audit($actor));
                foreach (['title', 'summary', 'body_html', 'seo_description'] as $field) {
                    if (($old->{$field} ?? '') !== ($data[$field] ?? '')) {
                        $this->db()->table('cms.content_translations')->where('space_id', $space->id)->where('content_id', $old->id)->where('field_name', $field)
                            ->update(['status' => 'stale', 'row_version' => $this->db()->raw('row_version + 1')] + $this->audit($actor));
                    }
                }
                $id = $old->id;
            } else {
                $id = $this->db()->table('cms.contents')->insertGetId($data + ['space_id' => $space->id, 'module' => 'academy',
                    'code' => 'COURSE'.strtoupper(Str::random(20))] + $this->audit($actor, true));
            }
            $this->db()->table('cms.content_categories')->where('space_id', $space->id)->where('content_id', $id)->delete();
            foreach ($p['category_ids'] as $sort => $categoryId) {
                $this->db()->table('cms.content_categories')->insert(['space_id' => $space->id, 'content_id' => $id,
                    'category_id' => $categoryId, 'module' => 'academy', 'sort_order' => $sort] + $this->audit($actor, true));
            }
            return $this->detail('courses', (int) $id);
        });
    }

    private function validateCourse(object $space, array $data, array $categoryIds): void
    {
        $publishing = $data['publish_status'] !== 'draft';
        if ($publishing && !$categoryIds) $this->fail('发布课程必须选择分类');
        $categories = $this->scoped('categories', $space)->whereIn('id', $categoryIds)
            ->select('*')->selectRaw('to_json(difficulty_levels) AS difficulty_levels')->get();
        if ($categories->count() !== count($categoryIds)) $this->fail('所选分类不存在或不属于当前学院模板');
        foreach ($categories as $category) {
            if ($publishing && !$category->is_enabled) $this->fail('发布课程必须选择已启用的分类，存在已停用分类');
            if (!empty($data['difficulty_level']) && !in_array((int) $data['difficulty_level'], json_decode($category->difficulty_levels, true), true)) $this->fail('课程难度不在所选分类的适用范围内');
        }
        if ($publishing && (empty($data['difficulty_level']) || empty($data['estimated_minutes']) || trim(html_entity_decode(strip_tags($data['body_html']), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\xc2\xa0") === '')) {
            $this->fail('发布课程需要填写正文、课程难度及预计课时');
        }
    }

    public static function safeUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x20\\\\]/', $url)) return false;
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) return true;
        return filter_var($url, FILTER_VALIDATE_URL) !== false && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true);
    }

    public function sanitize(string $html): string
    {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Allowed', 'p[style],br,strong,b,em,i,u,s,h1,h2,h3,ul,ol[start],li,blockquote,pre,code,a[href|title],img[src|alt|title|width|height],table,thead,tbody,tr,th[colspan|rowspan],td[colspan|rowspan],hr,span[style]');
        $config->set('CSS.AllowedProperties', ['color', 'background-color', 'text-align']);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        return (new \HTMLPurifier($config))->purify($html);
    }

    public function publishDue(): array
    {
        $space = $this->space();
        if (!$space || !$space->is_enabled) return ['published' => 0, 'skipped' => 0];
        // Page by ID so invalid scheduled entries cannot starve later valid courses.
        $rows = $this->scoped('contents', $space)->where('publish_status', 'scheduled')->where('scheduled_at', '<=', now())->select('id')->lazyById(100);
        $counts = ['published' => 0, 'skipped' => 0];
        foreach ($rows as $entry) {
            $id = $entry->id;
            try {
                $result = $this->mutate(['id' => null, 'name' => '课程定时发布', 'type' => 'system'], function ($lockedSpace) use ($id) {
                    $row = $this->scoped('contents', $lockedSpace)->where('id', $id)->where('publish_status', 'scheduled')->where('scheduled_at', '<=', now())->lockForUpdate()->first();
                    if (!$row) return ['published' => false];
                    $categories = $this->db()->table('cms.content_categories')->where('space_id', $lockedSpace->id)->where('content_id', $id)->pluck('category_id')->all();
                    $this->validateCourse($lockedSpace, (array) $row, $categories);
                    $this->scoped('contents', $lockedSpace)->where('id', $id)->update(['publish_status' => 'published', 'published_at' => now(), 'row_version' => $row->row_version + 1]
                        + $this->audit(['id' => null, 'name' => '课程定时发布', 'type' => 'system']));
                    return ['published' => true];
                });
                $counts[$result['published'] ? 'published' : 'skipped']++;
            } catch (ValidationException $e) {
                $counts['skipped']++;
            }
        }
        return $counts;
    }
}
