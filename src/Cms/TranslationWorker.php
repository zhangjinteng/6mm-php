<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Throwable;

class TranslationWorker
{
    private TranslationService $s;
    private GoogleTranslationClient $google;

    public function __construct(?TranslationService $service = null, ?GoogleTranslationClient $google = null)
    {
        $this->s = $service ?? new TranslationService();
        $this->google = $google ?? new GoogleTranslationClient();
    }

    public function run(int $limit, int $seconds): array
    {
        $processed = 0;
        $start = microtime(true);
        while ($processed < $limit && microtime(true) - $start < $seconds) {
            $item = $this->claim();
            if (!$item) {
                break;
            }
            $error = null;
            $text = null;
            try {
                $text = $this->google->translate($item->source_text_snapshot, $item->source_locale, $item->target_locale, $item->field_name === 'body_html', (int) $item->request_timeout_seconds);
            } catch (Throwable $e) {
                $error = preg_match('/^(GOOGLE_[A-Z0-9_]+|SOURCE_TOO_LONG)$/D', $e->getMessage()) ? $e->getMessage() : 'TRANSLATION_FAILED';
            }
            $this->finish($item, $text, $error);
            $processed++;
        }
        return ['processed' => $processed];
    }

    public function claim(): ?object
    {
        $db = $this->s->connection();
        $candidates = $db->table('cms.translation_job_items as i')->join('cms.translation_jobs as j', 'j.id', '=', 'i.job_id')->whereIn('j.status', ['queued', 'running'])->where(function (Builder $q): void {
            $q->where('i.status', 'queued')->orWhere(function (Builder $q): void {
                $q->where('i.status', 'failed')->whereNotNull('i.next_attempt_at')->where('i.next_attempt_at', '<=', now());
            })->orWhere(function (Builder $q): void {
                $q->where('i.status', 'running')->where('i.lease_until', '<=', now());
            });
        })->orderBy('i.id')->limit(50)->get(['i.id', 'i.space_id', 'i.job_id']);
        foreach ($candidates as $candidate) {
            $claimed = $db->transaction(function () use ($db, $candidate): ?object {
                $space = $this->s->space((int) $candidate->space_id, true);
                $job = $db->table('cms.translation_jobs')->where('space_id', $space->id)->where('id', $candidate->job_id)->first();
                if (!$job || !in_array($job->status, ['queued', 'running'], true)) {
                    return null;
                }
                $item = $db->table('cms.translation_job_items')->where('id', $candidate->id)->lock('FOR UPDATE SKIP LOCKED')->first();
                if (!$item || in_array($item->status, ['succeeded', 'skipped', 'cancelled'], true)) {
                    return null;
                }
                if ($item->status === 'running' && CarbonImmutable::parse($item->lease_until)->isFuture()) {
                    return null;
                }
                if ($item->status === 'failed' && (!$item->next_attempt_at || CarbonImmutable::parse($item->next_attempt_at)->isFuture())) {
                    return null;
                }
                $setting = $db->table('cms.translation_settings')->where('id', $job->setting_id)->first();
                // Operator-requested jobs remain valid when automatic translation is disabled.
                if (!$space->is_enabled || !$setting || $space->source_locale !== $job->source_locale || !in_array($item->target_locale, $this->s->targets($space), true) || $setting->provider !== 'google' || $setting->api_version !== 'v2' || $setting->credential_ref !== config('cms_translation.credential_ref')) {
                    $db->table('cms.translation_job_items')->where('id', $item->id)->update([
                        'status' => 'skipped',
                        'error_code' => 'CONFIGURATION_CHANGED',
                        'finished_at' => now(),
                        'updated_at' => now(),
                        'lease_until' => null,
                        'worker_token' => null,
                        'next_attempt_at' => null,
                    ]);
                    $this->aggregate((int) $job->id);
                    return null;
                }
                if ($item->attempt_count >= $setting->max_attempts) {
                    $db->table('cms.translation_job_items')->where('id', $item->id)->update([
                        'status' => 'failed',
                        'error_code' => 'ATTEMPTS_EXHAUSTED',
                        'finished_at' => now(),
                        'updated_at' => now(),
                        'lease_until' => null,
                        'worker_token' => null,
                        'next_attempt_at' => null,
                    ]);
                    $this->aggregate((int) $job->id);
                    return null;
                }
                $kind = $item->category_id ? 'category' : 'content';
                $id = $item->category_id ?: $item->content_id;
                $entity = $db->table('cms.' . ($kind === 'category' ? 'categories' : 'contents'))->where('space_id', $space->id)->where('id', $id)->whereNull('deleted_at')->first();
                $unchanged = $entity && isset(TranslationCatalog::MODULES[$entity->module]);
                if ($unchanged) {
                    $spec = TranslationCatalog::spec($entity->module, $kind);
                    $unchanged = in_array($item->field_name, $spec['fields'], true) && TranslationCatalog::sourceHash((string) ($entity->{$item->field_name} ?? '')) === $item->source_hash;
                    $target = $this->s->targetQuery($spec, (int) $space->id, (int) $id, $item->field_name, $item->target_locale)->first();
                    $unchanged = $unchanged && (string) ($target->row_version ?? 0) === (string) $item->expected_target_version;
                }
                if (!$unchanged) {
                    $db->table('cms.translation_job_items')->where('id', $item->id)->update([
                        'status' => 'skipped',
                        'error_code' => 'SOURCE_OR_TARGET_CHANGED',
                        'finished_at' => now(),
                        'updated_at' => now(),
                        'lease_until' => null,
                        'worker_token' => null,
                        'next_attempt_at' => null,
                    ]);
                    $this->aggregate((int) $job->id);
                    return null;
                }
                $running = $db->table('cms.translation_job_items as i')->join('cms.translation_jobs as j', 'j.id', '=', 'i.job_id')->where('j.setting_id', $setting->id)->where('i.status', 'running')->where('i.lease_until', '>', now())->count();
                if ($running >= $setting->concurrency_limit) {
                    return null;
                }
                $token = (string) Str::uuid();
                $db->table('cms.translation_job_items')->where('id', $item->id)->update([
                    'status' => 'running',
                    'attempt_count' => $item->attempt_count + 1,
                    'lease_until' => now()->addSeconds($setting->request_timeout_seconds + 30),
                    'worker_token' => $token,
                    'next_attempt_at' => null,
                    'updated_at' => now(),
                ]);
                $db->table('cms.translation_jobs')->where('id', $job->id)->update(['status' => 'running', 'started_at' => $job->started_at ?: now(), 'updated_at' => now()]);
                $item->worker_token = $token;
                $item->attempt_count++;
                $item->source_locale = $job->source_locale;
                $item->request_timeout_seconds = $setting->request_timeout_seconds;
                $item->max_attempts = $setting->max_attempts;
                return $item;
            });
            if ($claimed) {
                return $claimed;
            }
        }
        return null;
    }

    public function finish(object $item, ?string $text, ?string $error): void
    {
        $db = $this->s->connection();
        $db->transaction(function () use ($db, $item, $text, $error): void {
            $space = $this->s->space((int) $item->space_id, true);
            $locked = $db->table('cms.translation_job_items')->where('id', $item->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'running' || $locked->worker_token !== $item->worker_token || CarbonImmutable::parse($locked->lease_until)->isPast()) {
                return;
            }
            $actor = ['id' => null, 'name' => 'Google Translate', 'type' => 'system'];
            $result = [
                'worker_token' => null,
                'lease_until' => null,
                'updated_at' => now(),
                'next_attempt_at' => null,
            ];
            if ($error !== null) {
                $retryable = $error === 'GOOGLE_CONNECTION_FAILED' || $error === 'GOOGLE_HTTP_429' || preg_match('/^GOOGLE_HTTP_5\d\d$/D', $error);
                $retry = $retryable && $item->attempt_count < $item->max_attempts;
                $result += ['status' => 'failed', 'error_code' => $error, 'finished_at' => $retry ? null : now()];
                if ($retry) {
                    $result['next_attempt_at'] = now()->addSeconds(min(300, 15 * 2 ** ($item->attempt_count - 1)));
                }
            } else {
                $kind = $item->category_id ? 'category' : 'content';
                $table = $kind === 'category' ? 'categories' : 'contents';
                $id = $item->category_id ?: $item->content_id;
                $entity = $db->table('cms.' . $table)->where('space_id', $space->id)->where('id', $id)->whereNull('deleted_at')->first();
                $job = $db->table('cms.translation_jobs')->where('id', $item->job_id)->first();
                $setting = $job ? $db->table('cms.translation_settings')->where('id', $job->setting_id)->first() : null;
                $safe = $entity && $space->is_enabled && $space->source_locale === $item->source_locale && in_array($item->target_locale, $this->s->targets($space), true) && $setting && (string) $space->translation_setting_id === (string) $setting->id && isset(TranslationCatalog::MODULES[$entity->module]);
                if ($safe) {
                    $spec = TranslationCatalog::spec($entity->module, $kind);
                    $safe = in_array($item->field_name, $spec['fields'], true) && TranslationCatalog::sourceHash((string) ($entity->{$item->field_name} ?? '')) === $item->source_hash;
                    $q = $this->s->targetQuery($spec, (int) $space->id, (int) $id, $item->field_name, $item->target_locale);
                    $old = (clone $q)->lockForUpdate()->first();
                    $safe = $safe && (string) ($old->row_version ?? 0) === (string) $item->expected_target_version;
                }
                if (!$safe) {
                    $result += ['status' => 'skipped', 'error_code' => 'SOURCE_OR_TARGET_CHANGED', 'finished_at' => now()];
                } else {
                    try {
                        $clean = $this->s->normalize($item->field_name, (string) $text);
                    } catch (Throwable $e) {
                        $clean = null;
                    }
                    if ($clean === null) {
                        $result += ['status' => 'failed', 'error_code' => 'INVALID_TRANSLATION', 'finished_at' => now()];
                    } else {
                        $data = [
                            $spec['text'] => $clean,
                            'source_hash' => $item->source_hash,
                            'status' => 'ready',
                            'translation_method' => 'machine',
                            'row_version' => (int) ($old->row_version ?? 0) + 1,
                        ] + $this->s->audit($actor);
                        if ($old) {
                            $q->update($data);
                        } else {
                            $db->table('cms.' . $spec['translations'])->insert($data + ['space_id' => $space->id, $spec['fk'] => $id, 'locale' => $item->target_locale] + ($kind === 'content' ? ['field_name' => $item->field_name] : []) + $this->s->audit($actor, true));
                        }
                        $this->s->bump($space, $actor);
                        $result += ['status' => 'succeeded', 'finished_at' => now(), 'error_code' => null];
                    }
                }
            }
            $db->table('cms.translation_job_items')->where('id', $item->id)->update($result);
            $this->aggregate((int) $item->job_id);
        });
    }

    private function aggregate(int $jobId): void
    {
        $db = $this->s->connection();
        $rows = $db->table('cms.translation_job_items')->where('job_id', $jobId)->get(['status', 'next_attempt_at']);
        $pending = $rows->contains(static fn (object $i): bool => in_array($i->status, ['queued', 'running'], true) || $i->status === 'failed' && $i->next_attempt_at !== null);
        $failed = $rows->where('status', 'failed')->count();
        $succeeded = $rows->where('status', 'succeeded')->count();
        $skipped = $rows->where('status', 'skipped')->count();
        $status = $pending ? 'running' : ($failed || $skipped ? $succeeded ? 'partial_failed' : 'failed' : 'succeeded');
        $db->table('cms.translation_jobs')->where('id', $jobId)->where('status', '<>', 'cancelled')->update(['status' => $status, 'updated_at' => now(), 'finished_at' => $pending ? null : now()]);
    }
}
