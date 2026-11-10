<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

use Carbon\CarbonImmutable;
use Closure;

/** Latest committed payload and delivery state per resource. Historical table name is retained. */
final class ThemeRedisOutbox implements ThemeSnapshotReplication, AgreementTemplateReplication
{
    public const TABLE = 'wl_site_theme_redis_outbox';
    public const TYPES = ['theme', 'agreement_template'];

    public function __construct(private ThemeRedisPublisher $publisher, private bool $enabled = true, private ?Closure $warning = null) {}

    private function requireTransaction(Closure $table): void
    {
        if ($table(self::TABLE)->getConnection()->transactionLevel() < 1) {
            throw new \LogicException('Configuration Outbox requires the source transaction');
        }
    }

    public function enqueue(Closure $table, int $site, bool $deliverAfterCommit = true): void
    {
        if (!$this->enabled) return;
        $this->requireTransaction($table);
        $owner = $table('wl_sites')->where('site_id', $site)->first();
        $latest = $table('wl_site_config_themes')->where('site_id', $site)->orderByDesc('config_version')->first();
        if (!$owner || (int)$owner->tenant_id < 1 || !$latest) throw new \RuntimeException('Theme source missing for replication');
        $this->stage($table, [
            'resource_type'=>'theme', 'resource_id'=>$site, 'locale'=>'',
            'tenant_id'=>$owner->tenant_id, 'site_id'=>$site,
            'config_version'=>$latest->config_version, 'schema_version'=>$latest->schema_version,
            'payload'=>$latest->payload, 'payload_sha256'=>$latest->payload_sha256,
            'previous_config_version'=>$latest->previous_config_version,
        ], $deliverAfterCommit);
    }

    public function enqueueTemplate(Closure $table, int $template, bool $deliverAfterCommit = true): void
    {
        if (!$this->enabled) return;
        $this->requireTransaction($table);
        // Same row lock as the authorized editor; site-level agreements cannot enter a public template key.
        $row = $table('wl_agreements')->where('id', $template)->where('scope_type', 'platform')->lockForUpdate()->first();
        if (!$row) throw new \RuntimeException('Platform agreement template missing');
        $payload = AgreementTemplateSnapshot::payload($row);
        $hash = hash('sha256', $payload);
        $identity = ['resource_type'=>'agreement_template', 'resource_id'=>$template, 'locale'=>$row->locale];
        $old = $table(self::TABLE)->where($identity)->lockForUpdate()->first();
        if ($old && ((int)$old->schema_version !== 1 || !hash_equals($old->payload_sha256, hash('sha256', $old->payload)))) {
            throw new \RuntimeException('Stored template snapshot checksum mismatch');
        }
        $same = $old && $old->payload === $payload;
        $previous = $same ? $old->previous_config_version : ($old->config_version ?? null);
        if ($same) $version = (int)$old->config_version;
        else {
            $db = $table(self::TABLE)->getConnection();
            $version = $db->getDriverName() === 'sqlite'
                ? (int)$table(self::TABLE)->max('config_version') + 1
                : (int)$db->selectOne("SELECT nextval('control_plane.wl_snapshot_version_seq'::regclass) AS version")->version;
            if ($version <= (int)($old->config_version ?? 0)) throw new \RuntimeException('Configuration version sequence invalid');
        }
        $this->stage($table, $identity + [
            'tenant_id'=>null, 'site_id'=>null, 'config_version'=>$version, 'schema_version'=>1,
            'payload'=>$payload, 'payload_sha256'=>$hash, 'previous_config_version'=>$previous,
        ], $deliverAfterCommit);
    }

    private function stage(Closure $table, array $snapshot, bool $deliverAfterCommit): void
    {
        $identity = array_intersect_key($snapshot, array_flip(['resource_type','resource_id','locale']));
        $old = $table(self::TABLE)->where($identity)->lockForUpdate()->first();
        if ($old && ((int)$old->config_version > (int)$snapshot['config_version']
            || ((int)$old->config_version === (int)$snapshot['config_version'] && $old->payload !== $snapshot['payload']))) {
            throw new \RuntimeException('Configuration snapshot version conflict');
        }
        if (!hash_equals($snapshot['payload_sha256'], hash('sha256', $snapshot['payload']))) {
            throw new \RuntimeException('Configuration snapshot checksum mismatch');
        }
        $now = CarbonImmutable::now('UTC');
        $table(self::TABLE)->updateOrInsert($identity, $snapshot + [
            'synced_version'=>null, 'attempts'=>0, 'next_attempt_at'=>$now,
            'last_error_code'=>null, 'synced_at'=>null, 'updated_at'=>$now,
        ]);
        if (!$deliverAfterCommit) return;
        $table(self::TABLE)->getConnection()->afterCommit(function () use ($table, $identity): void {
            try { $this->drain($table, 1, null, $identity['resource_type'], (int)$identity['resource_id'], $identity['locale']); }
            catch (\Throwable $e) { $this->warn($identity); }
        });
    }

    public function drain(Closure $table, int $limit = 100, ?int $site = null, ?string $type = null, ?int $id = null, ?string $locale = null): array
    {
        if ($table(self::TABLE)->getConnection()->transactionLevel() !== 0) {
            throw new \LogicException('Redis delivery cannot run inside a database transaction');
        }
        if ($limit < 1 || $limit > 1000 || ($site !== null && $site < 1) || ($id !== null && $id < 1)
            || ($type !== null && !in_array($type, self::TYPES, true))
            || ($site !== null && $type !== null && $type !== 'theme')
            || ($locale !== null && $locale !== '' && !AgreementTemplateSnapshot::validLocale($locale))) {
            throw new \InvalidArgumentException('Invalid configuration delivery filter');
        }
        $stats = ['processed'=>0, 'synced'=>0, 'failed'=>0];
        if (!$this->enabled) return $stats;
        $jobs = $table(self::TABLE)->whereNull('synced_version')->where('next_attempt_at', '<=', CarbonImmutable::now('UTC'))
            ->when($site !== null, fn ($q) => $q->where('resource_type', 'theme')->where('site_id', $site))
            ->when($type !== null, fn ($q) => $q->where('resource_type', $type))
            ->when($id !== null, fn ($q) => $q->where('resource_id', $id))
            ->when($locale !== null, fn ($q) => $q->where('locale', $locale))
            ->orderBy('next_attempt_at')->orderBy('resource_type')->orderBy('resource_id')->orderBy('locale')->limit($limit)->get();
        foreach ($jobs as $job) {
            ++$stats['processed'];
            $identity = ['resource_type'=>$job->resource_type, 'resource_id'=>$job->resource_id, 'locale'=>$job->locale];
            $target = fn () => $table(self::TABLE)->where($identity)->where('config_version', $job->config_version)
                ->where('payload_sha256', $job->payload_sha256)->whereNull('synced_version');
            try {
                // Deliver the exact committed payload; never reconstruct from mutable drafts during retry.
                $this->publisher->publish($job);
                $target()->update(['synced_version'=>$job->config_version, 'synced_at'=>CarbonImmutable::now('UTC'), 'last_error_code'=>null]);
                ++$stats['synced'];
            } catch (\Throwable $e) {
                ++$stats['failed'];
                $attempts = min(1000000, (int)$job->attempts + 1);
                $target()->update(['attempts'=>$attempts, 'last_error_code'=>'config_redis_delivery_failed',
                    'next_attempt_at'=>CarbonImmutable::now('UTC')->addSeconds(min(300, 5 * (2 ** min(6, $attempts - 1))))]);
                $this->warn($identity);
            }
        }
        return $stats;
    }

    /** Backward-compatible theme repair. Existing history is not regenerated or modified. */
    public function rebuild(Closure $table, int $limit = 100, ?int $site = null, int $after = 0): array
    {
        if (!$this->enabled) return [];
        $this->validateRebuild($table, $limit, $site, $after);
        $sites = $table('wl_site_config_themes')->select('site_id')->distinct()->where('site_id', '>', $after)
            ->when($site !== null, fn ($q) => $q->where('site_id', $site))->orderBy('site_id')->limit($limit)->pluck('site_id')->all();
        foreach ($sites as $id) $table(self::TABLE)->getConnection()->transaction(function () use ($table, $id): void {
            $owner = $table('wl_sites')->where('site_id', $id)->value('tenant_id');
            if ($owner === null) throw new \RuntimeException('Theme site missing');
            $table('wl_tenants')->where('tenant_id', $owner)->lockForUpdate()->first();
            $table('wl_sites')->where('site_id', $id)->lockForUpdate()->first();
            $this->enqueue($table, (int)$id, false);
        });
        return array_map('intval', $sites);
    }

    public function rebuildTemplates(Closure $table, int $limit = 100, ?int $template = null, int $after = 0, ?string $locale = null): array
    {
        if (!$this->enabled) return [];
        $this->validateRebuild($table, $limit, $template, $after);
        if ($locale !== null && !AgreementTemplateSnapshot::validLocale($locale)) throw new \InvalidArgumentException('Invalid template locale');
        $ids = $table('wl_agreements')->where('scope_type', 'platform')->where('published_version', '>', 0)->where('id', '>', $after)
            ->when($template !== null, fn ($q) => $q->where('id', $template))
            ->when($locale !== null, fn ($q) => $q->where('locale', $locale))->orderBy('id')->limit($limit)->pluck('id')->all();
        foreach ($ids as $id) $table(self::TABLE)->getConnection()->transaction(fn () => $this->enqueueTemplate($table, (int)$id, false));
        return array_map('intval', $ids);
    }

    private function validateRebuild(Closure $table, int $limit, ?int $id, int $after): void
    {
        if ($limit < 1 || $limit > 1000 || $after < 0 || ($id !== null && $id < 1)) throw new \InvalidArgumentException('Invalid configuration rebuild batch');
        if ($table(self::TABLE)->getConnection()->transactionLevel() !== 0) throw new \LogicException('Configuration rebuild must own its transaction');
    }

    private function warn(array $identity): void
    {
        try { if ($this->warning) ($this->warning)($identity + ['code'=>'config_redis_delivery_failed']); }
        catch (\Throwable $ignored) { /* Logging must never fail a committed save. */ }
    }
}
