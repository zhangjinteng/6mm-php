<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FeedbackService
{
    use HasCmsSourceScope;

    private function db(): \Illuminate\Database\Connection { return DB::connection('pgsql'); }

    /** Reuse the same content-space restriction for list, details and attachments. */
    private function query(): Builder
    {
        $query = DB::connection('pgsql')->table('cms.feedback as f')
            ->join('cms.spaces as s', function ($join): void {
                $join->on('s.id', '=', 'f.space_id')->on('s.home_site_id', '=', 'f.home_site_id');
            })
            ->where('s.space_type', 'site')->where('f.status', 'submitted')->whereNull('f.deleted_at');
        return $this->cmsSourceScope ? $this->cmsSourceScope->apply($query, 'f.space_id') : $query;
    }

    public function listing(array $input): array
    {
        $p = Validator::make($input, [
            'page_no' => 'sometimes|integer|min:1|max:1000000',
            'page_size' => 'sometimes|integer|min:1|max:100',
            'code' => 'nullable|string|max:64', 'uid' => 'nullable|string|max:32',
            'source_name' => 'nullable|string|max:160',
            'source_type' => 'nullable|in:merchant,white_label,platform',
            'scene_id' => 'nullable|integer|min:1', 'issue_category_id' => 'nullable|integer|min:1',
            'submitted_from' => 'nullable|date', 'submitted_to' => 'nullable|date|after_or_equal:submitted_from',
        ])->validate();
        $query = $this->query();
        foreach (['code' => 'code', 'source_name' => 'source_name_snapshot'] as $key => $column) {
            if (isset($p[$key]) && trim($p[$key]) !== '') {
                $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($p[$key])).'%';
                $query->where('f.'.$column, 'ilike', $pattern);
            }
        }
        foreach (['uid' => 'public_user_id_snapshot', 'source_type' => 'source_type', 'scene_id' => 'scene_id', 'issue_category_id' => 'issue_category_id'] as $key => $column) {
            if (isset($p[$key]) && $p[$key] !== '') $query->where('f.'.$column, $p[$key]);
        }
        if (!empty($p['submitted_from'])) $query->where('f.submitted_at', '>=', $p['submitted_from']);
        if (!empty($p['submitted_to'])) $query->where('f.submitted_at', '<=', $p['submitted_to']);
        $count = (clone $query)->count();
        $size = (int) ($p['page_size'] ?? 20);
        $rows = $query->select('f.*')->orderByDesc('f.submitted_at')->orderByDesc('f.id')
            ->offset(((int) ($p['page_no'] ?? 1) - 1) * $size)->limit($size)->get();
        return ['lists' => $rows->map(fn ($row): array => $this->present((array) $row))->all(), 'count' => $count];
    }

    public function metadata(): array
    {
        // Submitted snapshots remain searchable even after categories are renamed/disabled.
        $scenes = $this->query()->select('f.scene_id as id', 'f.scene_name_snapshot as name')->distinct()->orderBy('name')->get();
        $issues = $this->query()->select('f.issue_category_id as id', 'f.scene_id', 'f.issue_category_name_snapshot as name')->distinct()->orderBy('name')->get();
        $normalize = static function ($rows): array {
            return $rows->map(static function ($row): array {
                $row = (array) $row;
                $row['id'] = (string) $row['id'];
                if (isset($row['scene_id'])) $row['scene_id'] = (string) $row['scene_id'];
                return $row;
            })->unique('id')->values()->all();
        };
        return ['cms_source_options' => $this->cmsSourceOptions(), 'scenes' => $normalize($scenes), 'issues' => $normalize($issues)];
    }

    public function detail(int $id): array
    {
        $row = $this->query()->where('f.id', $id)->select('f.*')->first();
        if (!$row) throw ValidationException::withMessages(['id' => '反馈不存在或不可查看']);
        $result = $this->present((array) $row);
        $result['attachments'] = DB::connection('pgsql')->table('cms.feedback_attachments')->where('feedback_id', $id)
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'original_name', 'mime_type', 'byte_size', 'width', 'height'])
            ->map(static function ($a): array { $a = (array) $a; $a['id'] = (string) $a['id']; return $a; })->all();
        return $result;
    }

    public function attachment(int $feedbackId, int $attachmentId): array
    {
        if (!$this->query()->where('f.id', $feedbackId)->exists()) {
            throw ValidationException::withMessages(['id' => '反馈不存在或不可查看']);
        }
        $a = DB::connection('pgsql')->table('cms.feedback_attachments')->where('feedback_id', $feedbackId)->where('id', $attachmentId)->first();
        if (!$a) throw ValidationException::withMessages(['attachment_id' => '附件不存在']);
        $key = (string) $a->storage_key;
        if (!preg_match('~\A[a-zA-Z0-9][a-zA-Z0-9_./-]*\z~', $key) || preg_match('~(^|/)\.\.?(/|$)~', $key)) {
            throw ValidationException::withMessages(['attachment_id' => '附件存储路径不合法']);
        }
        $diskName = (string) config('cms_feedback.attachment_disk', 'cms_feedback_private');
        if ($diskName === 'public') throw new \RuntimeException('Feedback requires private storage');
        $disk = Storage::disk($diskName);
        if (!$disk->exists($key) || $disk->size($key) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['attachment_id' => '附件不存在或超过预览大小限制']);
        }
        $bytes = $disk->get($key);
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] > 8192 || $info[1] > 8192) {
            throw ValidationException::withMessages(['attachment_id' => '仅支持有效的 JPG、PNG、WebP 图片附件']);
        }
        return ['data_url' => 'data:'.$mime.';base64,'.base64_encode($bytes)];
    }

    private function present(array $row): array
    {
        $result = [];
        foreach (['id', 'code', 'home_site_id', 'public_user_id_snapshot', 'username_snapshot', 'nickname_snapshot',
            'source_type', 'source_agent_id', 'source_name_snapshot', 'scene_id', 'issue_category_id',
            'scene_name_snapshot', 'issue_category_name_snapshot', 'content', 'contact_type', 'submitted_at'] as $key) {
            $value = $row[$key] ?? null;
            $result[$key] = $value !== null && ($key === 'id' || str_ends_with($key, '_id')) ? (string) $value : $value;
        }
        $result['contact_display'] = $this->maskedContact($row['contact_value_enc'] ?? null);
        return $this->cmsSourceRow($result + ['space_id' => (string) $row['space_id']]);
    }

    private function maskedContact(?string $encrypted): string
    {
        if (!$encrypted) return '未填写';
        try { $value = Crypt::decryptString($encrypted); }
        catch (\Throwable $e) { return '无法解密（请核对加密配置）'; }
        $at = mb_strpos($value, '@');
        if ($at !== false) return mb_substr($value, 0, min(1, $at)).'***'.mb_substr($value, $at);
        if (mb_strlen($value) < 7) return '***';
        return mb_substr($value, 0, 2).'****'.mb_substr($value, -2);
    }
}
