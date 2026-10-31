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

final class NewsService
{
    use HasCmsSourceScope;

    public static function identityRules(bool $edit): array
    {
        return [
            'id' => $edit ? 'required|integer|min:1' : 'prohibited',
            'row_version' => $edit ? 'required|integer|min:1' : 'prohibited',
            'space_id' => 'prohibited', 'module' => 'prohibited',
        ];
    }

    public static function categoryRules(bool $edit): array
    {
        return self::identityRules($edit) + [
            'name' => 'required|string|max:120', 'parent_id' => 'nullable|integer|min:1',
            'sort_order' => 'required|integer|between:0,2147483647', 'is_enabled' => 'required|boolean',
        ];
    }

    public static function articleRules(bool $edit): array
    {
        return self::identityRules($edit) + [
            'title' => 'required|string|max:255', 'category_id' => 'nullable|integer|min:1',
            'summary' => 'nullable|string|max:10000', 'body_html' => 'nullable|string|max:1000000',
            'seo_description' => 'nullable|string|max:2000', 'cover_url' => 'nullable|string|max:2048',
            'content_source' => 'nullable|string|max:160', 'author_name' => 'nullable|string|max:120',
            'sort_order' => 'required|integer|between:0,2147483647', 'is_enabled' => 'required|boolean',
            'publish_status' => 'required|in:draft,scheduled,published',
            'scheduled_at' => ['nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'],
        ];
    }

    private function db(): Connection
    {
        return DB::connection('pgsql');
    }

    private function space(): ?object
    {
        if ($this->cmsSourceScope?->selected !== null) return $this->cmsSourceScope->selected;
        return $this->db()->table('cms.spaces')->where('space_type', 'template')
            ->where('template_key', 'default')->first();
    }

    private function scoped(string $table, ?object $space): Builder
    {
        $query = $this->db()->table('cms.'.$table)->where('module', 'news')->whereNull('deleted_at');
        return $this->cmsSourceScope?->browse
            ? $this->cmsSourceScope->apply($query)
            : $query->where('space_id', $space->id ?? -1);
    }

    private function fail(string $message): void
    {
        throw ValidationException::withMessages(['news' => $message]);
    }

    private function audit(array $actor, bool $create = false): array
    {
        $data = ['updated_at' => now(), 'updated_by_type' => $actor['type'] ?? 'platform',
            'updated_by_id' => $actor['id'], 'updated_by_name' => $actor['name']];
        return $create ? $data + ['created_at' => now(), 'created_by_type' => $actor['type'] ?? 'platform',
            'created_by_id' => $actor['id']] : $data;
    }

    private function mutate(array $actor, callable $callback): array
    {
        return $this->db()->transaction(function () use ($actor, $callback): array {
            // Same lock as Academy: protects creation of the shared default template.
            // Lock ordering is always advisory lock -> space -> category/content.
            $this->db()->select('SELECT pg_advisory_xact_lock(60428, 1)');
            $space = $this->space();
            if (!$space) {
                $id = $this->db()->table('cms.spaces')->insertGetId([
                    'space_type' => 'template', 'template_key' => 'default', 'name' => '平台默认内容模板',
                ] + $this->audit($actor, true));
                $space = $this->db()->table('cms.spaces')->where('id', $id)->first();
            }
            $space = $this->db()->table('cms.spaces')->where('id', $space->id)->lockForUpdate()->first();
            if (!$space->is_enabled) {
                $this->fail('内容模板空间已停用');
            }
            $result = $callback($space);
            $this->db()->table('cms.spaces')->where('id', $space->id)->update([
                'revision' => $space->revision + 1,
            ] + $this->audit($actor));
            return $result;
        });
    }

    private function row(object $row): array
    {
        $data = (array) $row;
        foreach (['id', 'space_id', 'parent_id', 'category_id', 'row_version'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (string) $data[$key];
            }
        }
        foreach (['created_at', 'updated_at', 'scheduled_at', 'published_at', 'deleted_at'] as $key) {
            if (!empty($data[$key])) {
                $data[$key] = CarbonImmutable::parse($data[$key])->utc()->toISOString();
            }
        }
        return $this->cmsSourceRow($data);
    }

    public function metadata(): array
    {
        $space = $this->space();
        $categories = $this->scoped('categories', $space)
            ->select(['id', 'name', 'parent_id', 'is_enabled', 'sort_order'])
            ->orderBy('sort_order')->orderBy('id')->get()->keyBy('id');
        return [
            'source_locale' => $space->source_locale ?? 'zh-CN',
            'cms_source_options' => $this->cmsSourceOptions(),
            'categories' => $categories->map(function (object $category) use ($categories): array {
                $parent = $category->parent_id ? $categories->get($category->parent_id) : null;
                return $this->row($category) + [
                    'effective_enabled' => $category->is_enabled && (!$category->parent_id || ($parent && $parent->is_enabled)),
                ];
            })->values()->all(),
        ];
    }

    public function listing(string $kind, array $input): array
    {
        $table = $this->table($kind);
        $p = Validator::make($input, [
            'page_no' => 'sometimes|integer|between:1,1000000', 'page_size' => 'sometimes|integer|between:1,100',
            'code' => 'nullable|string|max:64', 'keyword' => 'nullable|string|max:255',
            'is_enabled' => 'nullable|boolean', 'category_id' => 'nullable|integer|min:1',
            'parent_id' => 'nullable|integer|min:1', 'publish_status' => 'nullable|in:draft,scheduled,published',
            'published_from' => 'nullable|date', 'published_to' => 'nullable|date|after_or_equal:published_from',
        ])->validate();
        $space = $this->space();
        $query = $this->scoped($table, $space);
        foreach (['code' => 'code', 'keyword' => $kind === 'articles' ? 'title' : 'name'] as $inputKey => $column) {
            if (!empty($p[$inputKey])) {
                $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($p[$inputKey]));
                $query->where($column, 'ilike', '%'.$term.'%');
            }
        }
        if (isset($p['is_enabled'])) {
            $query->where('is_enabled', (bool) $p['is_enabled']);
        }
        if ($kind === 'articles') {
            if (!empty($p['category_id'])) {
                $category = $this->category($space, (int) $p['category_id']);
                if (!empty($p['parent_id']) && (int) $category->parent_id !== (int) $p['parent_id']) {
                    $this->fail('一级与二级分类不匹配');
                }
                $query->whereIn('category_id', $this->categoryIds($space, $category));
            } elseif (!empty($p['parent_id'])) {
                $parent = $this->category($space, (int) $p['parent_id']);
                if ($parent->parent_id !== null) {
                    $this->fail('一级分类必须选择根分类');
                }
                $query->whereIn('category_id', $this->categoryIds($space, $parent));
            }
            if (!empty($p['publish_status'])) {
                $query->where('publish_status', $p['publish_status']);
            }
            if (!empty($p['published_from'])) {
                $query->whereRaw('coalesce(published_at, scheduled_at) >= ?', [$p['published_from']]);
            }
            if (!empty($p['published_to'])) {
                $query->whereRaw('coalesce(published_at, scheduled_at) <= ?', [$p['published_to']]);
            }
        } elseif (!empty($p['parent_id'])) {
            $this->category($space, (int) $p['parent_id']);
            $query->where('parent_id', $p['parent_id']);
        }
        $count = (clone $query)->count();
        // A single correlated count, not one additional query per category.
        if ($kind === 'categories') {
            $query->select('categories.*')->selectRaw("(SELECT count(*) FROM cms.contents c
                WHERE c.space_id = categories.space_id AND c.module = 'news' AND c.deleted_at IS NULL
                AND (c.category_id = categories.id OR c.category_id IN
                    (SELECT child.id FROM cms.categories child WHERE child.space_id = categories.space_id
                     AND child.module = 'news' AND child.parent_id = categories.id AND child.deleted_at IS NULL))) AS news_count");
        } else {
            $query->select(['id', 'space_id', 'code', 'category_id', 'title', 'sort_order', 'publish_status',
                'is_enabled', 'published_at', 'scheduled_at', 'created_by_type', 'created_by_id', 'updated_by_name', 'updated_at', 'row_version']);
        }
        return [
            'lists' => $query->orderBy('sort_order')->orderBy('id')
                ->forPage((int) ($p['page_no'] ?? 1), (int) ($p['page_size'] ?? 20))
                ->get()->map(fn (object $row): array => $this->row($row))->all(),
            'count' => $count,
        ];
    }

    private function table(string $kind): string
    {
        if (!in_array($kind, ['categories', 'articles'], true)) {
            $this->fail('不支持的新闻数据类型');
        }
        return $kind === 'categories' ? 'categories' : 'contents';
    }

    private function category(?object $space, int $id): object
    {
        $category = $this->scoped('categories', $space)->where('id', $id)->first();
        if (!$category) {
            $this->fail('分类不存在或不属于当前新闻模板');
        }
        return $category;
    }

    private function categoryIds(?object $space, object $category): array
    {
        $ids = [$category->id];
        if ($category->parent_id === null) {
            $ids = array_merge($ids, $this->scoped('categories', $space)->where('parent_id', $category->id)->pluck('id')->all());
        }
        return $ids;
    }

    public function detail(string $kind, int $id): array
    {
        $row = $this->scoped($this->table($kind), $this->space())->where('id', $id)->first();
        if (!$row) {
            $this->fail('记录不存在或不在当前新闻模板范围内');
        }
        return $this->row($row);
    }

    private function editRow(string $table, object $space, array $p): ?object
    {
        if (empty($p['id'])) {
            return null;
        }
        $row = $this->scoped($table, $space)->where('id', $p['id'])->lockForUpdate()->first();
        if (!$row) {
            $this->fail('记录不存在或不在当前新闻模板范围内');
        }
        if ((string) $row->row_version !== (string) $p['row_version']) {
            $this->fail('资料已被其他人修改，请重新打开后编辑');
        }
        return $row;
    }

    public function saveCategory(array $input, array $actor, bool $edit): array
    {
        $p = Validator::make($input, self::categoryRules($edit))->validate();
        if (trim($p['name']) === '') {
            $this->fail('分类名称不能为空');
        }
        return $this->mutate($actor, function (object $space) use ($p, $actor): array {
            $old = $this->editRow('categories', $space, $p);
            $parentId = $p['parent_id'] ?? null;
            if ($parentId !== null) {
                $parent = $this->category($space, (int) $parentId);
                if ($parent->parent_id !== null || ($old && (int) $old->id === (int) $parentId)) {
                    $this->fail('最多支持二级分类，父分类必须是其他一级分类');
                }
                if ($old && $this->scoped('categories', $space)->where('parent_id', $old->id)->exists()) {
                    $this->fail('带子分类的分类不能调整为二级分类');
                }
            }
            $data = ['name' => trim($p['name']), 'parent_id' => $parentId,
                'sort_order' => $p['sort_order'], 'is_enabled' => (bool) $p['is_enabled']];
            if ($old) {
                $this->scoped('categories', $space)->where('id', $old->id)
                    ->update($data + ['row_version' => $old->row_version + 1] + $this->audit($actor));
                if ($old->name !== $data['name']) {
                    $this->db()->table('cms.category_translations')->where('space_id', $space->id)->where('category_id', $old->id)
                        ->update(['status' => 'stale', 'row_version' => $this->db()->raw('row_version + 1')] + $this->audit($actor));
                }
                $id = $old->id;
            } else {
                $id = $this->db()->table('cms.categories')->insertGetId($data + [
                    'space_id' => $space->id, 'module' => 'news', 'code' => 'CAT'.strtoupper(Str::random(20)),
                ] + $this->audit($actor, true));
            }
            return $this->detail('categories', (int) $id);
        });
    }

    public function saveArticle(array $input, array $actor, bool $edit, bool $canPublish): array
    {
        $p = Validator::make($input, self::articleRules($edit))->validate();
        if (trim($p['title']) === '') {
            $this->fail('新闻标题不能为空');
        }
        if (!empty($p['cover_url']) && !AcademyService::safeUrl($p['cover_url'])) {
            $this->fail('封面地址必须为 HTTP(S) 或站内绝对路径');
        }
        // Reuse the installed, server-side CMS HTML allowlist; never trust editor HTML.
        $p['body_html'] = (new AcademyService())->sanitize((string) ($p['body_html'] ?? ''));
        return $this->mutate($actor, function (object $space) use ($p, $actor, $canPublish): array {
            $old = $this->editRow('contents', $space, $p);
            $this->checkPublishPermission($canPublish, $p['publish_status'], $old);
            $data = array_intersect_key($p, array_flip(['title', 'category_id', 'summary', 'body_html',
                'seo_description', 'cover_url', 'content_source', 'author_name', 'sort_order', 'is_enabled', 'publish_status']));
            $data['title'] = trim($data['title']);
            foreach (['category_id', 'summary', 'seo_description', 'cover_url', 'content_source', 'author_name'] as $field) {
                $data[$field] = $data[$field] ?? null;
            }
            $data['is_enabled'] = (bool) $data['is_enabled'];
            $data['scheduled_at'] = null;
            $data['published_at'] = null;
            if ($data['publish_status'] === 'scheduled') {
                if (empty($p['scheduled_at']) || !CarbonImmutable::parse($p['scheduled_at'])->isFuture()) {
                    $this->fail('计划发布时间必须晚于当前时间');
                }
                $data['scheduled_at'] = CarbonImmutable::parse($p['scheduled_at'])->utc();
            } elseif ($data['publish_status'] === 'published') {
                $data['published_at'] = $old && $old->publish_status === 'published' ? $old->published_at : now();
            }
            $this->validateArticle($space, $data);
            if ($old) {
                $this->scoped('contents', $space)->where('id', $old->id)
                    ->update($data + ['row_version' => $old->row_version + 1] + $this->audit($actor));
                foreach (['title', 'summary', 'body_html', 'seo_description'] as $field) {
                    if (($old->{$field} ?? '') !== ($data[$field] ?? '')) {
                        $this->db()->table('cms.content_translations')->where('space_id', $space->id)
                            ->where('content_id', $old->id)->where('field_name', $field)
                            ->update(['status' => 'stale', 'row_version' => $this->db()->raw('row_version + 1')] + $this->audit($actor));
                    }
                }
                $id = $old->id;
            } else {
                $id = $this->db()->table('cms.contents')->insertGetId($data + [
                    'space_id' => $space->id, 'module' => 'news', 'code' => 'NEWS'.strtoupper(Str::random(20)),
                ] + $this->audit($actor, true));
            }
            return $this->detail('articles', (int) $id);
        });
    }

    private function checkPublishPermission(bool $allowed, string $status, ?object $old): void
    {
        if (!$allowed && ($status !== 'draft' || ($old && $old->publish_status !== 'draft'))) {
            $this->fail('没有新闻发布权限，不能修改或删除已发布及定时发布的新闻');
        }
    }

    private function validateArticle(object $space, array $data): void
    {
        $publishing = $data['publish_status'] !== 'draft';
        if (empty($data['category_id'])) {
            if ($publishing) {
                $this->fail('发布新闻必须选择分类');
            }
        } else {
            $category = $this->category($space, (int) $data['category_id']);
            $parent = $category->parent_id ? $this->category($space, (int) $category->parent_id) : null;
            if ($publishing && (!$category->is_enabled || ($parent && !$parent->is_enabled))) {
                $this->fail('发布新闻的分类及父分类必须已启用');
            }
        }
        $text = trim(html_entity_decode(strip_tags($data['body_html']), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\xc2\xa0");
        if ($publishing && $text === '') {
            $this->fail('发布新闻需要填写正文');
        }
    }

    public function delete(string $kind, array $input, array $actor, bool $canPublish): array
    {
        $p = Validator::make($input, self::identityRules(true))->validate();
        $table = $this->table($kind);
        return $this->mutate($actor, function (object $space) use ($table, $p, $actor, $canPublish): array {
            $old = $this->editRow($table, $space, $p);
            if ($table === 'categories') {
                if ($this->scoped('categories', $space)->where('parent_id', $old->id)->exists()
                    || $this->scoped('contents', $space)->where('category_id', $old->id)->exists()) {
                    $this->fail('分类下仍有子分类或新闻，请先调整或删除关联记录');
                }
            } else {
                $this->checkPublishPermission($canPublish, $old->publish_status, $old);
            }
            $this->scoped($table, $space)->where('id', $old->id)->update([
                'deleted_at' => now(), 'is_enabled' => false, 'row_version' => $old->row_version + 1,
            ] + $this->audit($actor));
            return ['id' => (string) $old->id];
        });
    }

    public function publishDue(): array
    {
        $space = $this->space();
        $counts = ['published' => 0, 'skipped' => 0];
        if (!$space || !$space->is_enabled) {
            return $counts;
        }
        $actor = ['id' => null, 'name' => '新闻定时发布', 'type' => 'system'];
        $rows = $this->scoped('contents', $space)->where('publish_status', 'scheduled')
            ->where('scheduled_at', '<=', now())->select('id')->lazyById(100);
        foreach ($rows as $entry) {
            try {
                $result = $this->mutate($actor, function (object $lockedSpace) use ($entry, $actor): array {
                    $row = $this->scoped('contents', $lockedSpace)->where('id', $entry->id)
                        ->where('publish_status', 'scheduled')->where('scheduled_at', '<=', now())->lockForUpdate()->first();
                    if (!$row) {
                        return ['published' => false];
                    }
                    $this->validateArticle($lockedSpace, (array) $row);
                    $this->scoped('contents', $lockedSpace)->where('id', $row->id)->where('row_version', $row->row_version)
                        ->update(['publish_status' => 'published', 'published_at' => now(),
                            'row_version' => $row->row_version + 1] + $this->audit($actor));
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
