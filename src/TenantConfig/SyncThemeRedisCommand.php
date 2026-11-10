<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

use Illuminate\Console\Command;

final class SyncThemeRedisCommand extends Command
{
    protected $signature = 'themes:sync-redis {--type= : theme or agreement_template; retry defaults to both} {--site= : Only this theme site ID} {--template= : Only this platform template ID} {--locale= : Template locale} {--limit=100 : Batch size, 1-1000} {--rebuild : Re-enqueue committed snapshots; defaults to theme} {--after=0 : Resource ID cursor for a rebuild batch}';
    protected $description = 'Sync committed themes and published platform templates to Redis; retry or repair cache loss';

    public function handle(ThemeRedisOutbox $outbox): int
    {
        $site = $this->option('site');
        $template = $this->option('template');
        $locale = $this->option('locale');
        $type = $this->option('type');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $after = filter_var($this->option('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $validId = static fn ($id) => $id === null || filter_var($id, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) !== false;
        if ($limit === false || $after === false || !$validId($site) || !$validId($template)
            || ($type !== null && !in_array($type, ThemeRedisOutbox::TYPES, true))
            || ($site !== null && ($template !== null || $locale !== null || ($type !== null && $type !== 'theme')))
            || (($template !== null || $locale !== null) && $type !== null && $type !== 'agreement_template')
            || ($locale !== null && !AgreementTemplateSnapshot::validLocale($locale))) {
            $this->error('Invalid resource type, identity, locale, limit or cursor.'); return 1;
        }
        if ($site !== null) $type = 'theme';
        if ($template !== null || $locale !== null) $type = 'agreement_template';
        if ($this->option('rebuild') && $type === null) $type = 'theme';
        $table = $this->laravel->make('theme_snapshot_redis.tables');
        try {
            if ($this->option('rebuild')) {
                $ids = $type === 'theme'
                    ? $outbox->rebuild($table, $limit, $site === null ? null : (int)$site, $after)
                    : $outbox->rebuildTemplates($table, $limit, $template === null ? null : (int)$template, $after, $locale);
                $this->info('Re-enqueued: '.count($ids).'; next --after='.($ids ? end($ids) : $after));
            }
            $result = $outbox->drain($table, $limit, $site === null ? null : (int)$site, $type, $template === null ? null : (int)$template, $locale);
            $this->info('Processed: '.$result['processed'].'; synced: '.$result['synced'].'; pending failures: '.$result['failed']);
            return $result['failed'] ? 1 : 0;
        } catch (\Throwable $e) {
            // Never print driver exceptions: they may contain SQL, config payloads or connection secrets.
            $this->error('Configuration Redis synchronization failed; check Outbox/schema/connection configuration.'); return 1;
        }
    }
}
