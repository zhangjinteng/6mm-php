<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use Illuminate\Database\Query\Builder;

final class TranslationJobs
{
    private TranslationService $service;

    public function __construct(?TranslationService $service = null)
    {
        $this->service = $service ?? new TranslationService();
    }

    public function create(array $p, array $actor): array
    {
        $s = $this->service;
        $db = $s->connection();
        return $db->transaction(function () use ($p, $actor, $s, $db): array {
            $space = $s->space((int) $p['space_id'], true);
            $s->writable($space);
            $old = $db->table('cms.translation_jobs')->where('space_id', $space->id)->where('request_key', $p['request_key'])->first();
            if ($old) {
                return $this->status((int) $space->id, (int) $old->id);
            }
            $setting = $space->translation_setting_id ? $db->table('cms.translation_settings')->where('id', $space->translation_setting_id)->first() : null;
            // These jobs are explicitly requested by an operator, independently of automatic translation.
            if (!$setting || $setting->provider !== 'google' || $setting->api_version !== 'v2' || $setting->credential_ref !== config('cms_translation.credential_ref') || !(new GoogleTranslationClient())->configured()) {
                $s->fail('请先配置 Google Translate 服务及服务端密钥');
            }
            if ($db->table('cms.translation_jobs')->where('space_id', $space->id)->whereIn('status', ['queued', 'running'])->exists()) {
                $s->fail('当前空间已有翻译任务，请等待完成或取消后再提交');
            }
            $spec = TranslationCatalog::spec($p['module'], $p['kind']);
            $locales = $p['target_locales'];
            foreach ($locales as $locale) {
                $s->checkTarget($space, $spec, $spec['fields'][0], $locale);
            }
            $fields = isset($p['field_name']) ? [$p['field_name']] : $spec['fields'];
            foreach ($fields as $field) {
                $s->checkTarget($space, $spec, $field, $locales[0]);
            }
            $q = $s->entities($spec, (int) $space->id, $p['module']);
            if (isset($p['id'])) {
                $q->where('id', $p['id']);
            }
            if (!empty($p['keyword'])) {
                $q->where($p['kind'] === 'category' ? 'name' : 'title', 'ILIKE', '%' . $p['keyword'] . '%');
            }
            $limit = (int) config('cms_translation.max_batch_records', 200);
            $rows = $q->orderBy('id')->limit($limit + 1)->get();
            if ($rows->count() > $limit) {
                $s->fail('单次最多翻译 ' . $limit . ' 条内容，请使用搜索缩小范围');
            }
            if ($rows->isEmpty()) {
                $s->fail('没有可翻译的内容');
            }
            $existing = $s->translationRows($spec, (int) $space->id, $rows->pluck('id')->all());
            $items = [];
            foreach ($rows as $row) {
                foreach ($fields as $field) {
                    $source = (string) ($row->{$field} ?? '');
                    if (trim($source) === '') {
                        continue;
                    }
                    foreach ($locales as $locale) {
                        $target = $existing[$row->id][$field][$locale] ?? null;
                        $hash = TranslationCatalog::sourceHash($source);
                        if ($target && $target->status === 'ready' && $target->source_hash === $hash) {
                            continue;
                        }
                        $items[] = [
                            'space_id' => $space->id,
                            $spec['fk'] => $row->id,
                            'field_name' => $field,
                            'target_locale' => $locale,
                            'source_text_snapshot' => $source,
                            'source_hash' => $hash,
                            'expected_target_version' => $target->row_version ?? 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
            }
            if (!$items) {
                $s->fail('所选范围已经翻译完成，无需重复翻译');
            }
            if (count($items) > (int) config('cms_translation.max_job_items', 5000)) {
                $s->fail('本次翻译字段过多，请缩小内容或语言范围');
            }
            $jobId = $db->table('cms.translation_jobs')->insertGetId([
                'space_id' => $space->id,
                'setting_id' => $setting->id,
                'request_key' => $p['request_key'],
                'source_locale' => $space->source_locale,
                'target_locales' => $s->pgArray($locales),
                'overwrite_policy' => 'missing_or_stale',
                'status' => 'queued',
            ] + $s->audit($actor, true));
            foreach (array_chunk($items, 100) as $chunk) {
                $db->table('cms.translation_job_items')->insert(array_map(static fn (array $item): array => $item + ['job_id' => $jobId], $chunk));
            }
            return $this->status((int) $space->id, (int) $jobId);
        });
    }

    public function status(int $spaceId, ?int $jobId = null): array
    {
        $s = $this->service;
        $s->space($spaceId);
        $db = $s->connection();
        $q = $db->table('cms.translation_jobs')->where('space_id', $spaceId);
        $row = $jobId ? $q->where('id', $jobId)->first() : $q->orderByDesc('id')->first();
        if (!$row) {
            return ['job' => null];
        }
        $counts = $db->table('cms.translation_job_items')->where('space_id', $spaceId)->where('job_id', $row->id)->selectRaw('status,count(*) as n')->groupBy('status')->pluck('n', 'status')->all();
        $errors = $db->table('cms.translation_job_items')->where('space_id', $spaceId)->where('job_id', $row->id)->whereIn('status', ['failed', 'skipped'])->orderByDesc('id')->limit(10)->get(['target_locale', 'field_name', 'error_code'])->all();
        return ['job' => [
            'id' => (string) $row->id,
            'space_id' => (string) $row->space_id,
            'status' => $row->status,
            'total' => array_sum($counts),
            'counts' => array_map('intval', $counts),
            'errors' => $errors,
            'created_at' => $row->created_at,
        ]];
    }

    public function cancel(int $spaceId, int $jobId, array $actor): array
    {
        $s = $this->service;
        $db = $s->connection();
        return $db->transaction(function () use ($s, $db, $spaceId, $jobId, $actor): array {
            $s->space($spaceId, true);
            $job = $db->table('cms.translation_jobs')->where('space_id', $spaceId)->where('id', $jobId)->lockForUpdate()->first();
            if (!$job) {
                $s->fail('翻译任务不存在');
            }
            if (in_array($job->status, ['queued', 'running'], true)) {
                $db->table('cms.translation_job_items')->where('space_id', $spaceId)->where('job_id', $jobId)->where(function (Builder $q): void {
                    $q->whereIn('status', ['queued', 'running'])->orWhere(function (Builder $q): void {
                        $q->where('status', 'failed')->whereNotNull('next_attempt_at');
                    });
                })->update([
                    'status' => 'cancelled',
                    'worker_token' => null,
                    'lease_until' => null,
                    'next_attempt_at' => null,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
                $db->table('cms.translation_jobs')->where('id', $jobId)->update(['status' => 'cancelled', 'finished_at' => now()] + $s->audit($actor));
            }
            return $this->status($spaceId, $jobId);
        });
    }
}
