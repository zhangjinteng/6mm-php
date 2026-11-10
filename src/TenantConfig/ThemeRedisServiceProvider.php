<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\ServiceProvider;

/** Optional Laravel host integration; no database/Redis IO during boot. */
final class ThemeRedisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ThemeRedisOutbox::class, static function ($app): ThemeRedisOutbox {
            $settings = $app['config']->get('theme_snapshot_redis', []);
            $name = 'theme_snapshots';
            $connection = (new ConfigurationUrlParser())->parseConfiguration($settings['redis']);
            $driver = (string)$app['config']->get('database.redis.client', 'phpredis');
            // Do not inherit application-specific prefixes; both hosts publish the same physical key.
            $connection['prefix'] = '';
            $connection['options'] = ['prefix' => ''];
            // The locked Laravel 8 PhpRedis connector passes password directly to AUTH.
            if ($driver === 'phpredis' && !empty($connection['username']) && is_string($connection['password'] ?? null)) {
                $connection['password'] = [$connection['username'], $connection['password']];
                unset($connection['username']);
            }
            // A dedicated manager also works if the application's default Redis manager was already resolved.
            $redis = new RedisManager($app, $driver,
                ['options' => ['prefix' => ''], $name => $connection]);
            return new ThemeRedisOutbox(new ThemeRedisPublisher(static fn () => $redis->connection($name),
                (string)$settings['key_prefix'], (string)($settings['template_key_prefix'] ?? 'wl:config')), (bool)$settings['enabled'],
                static fn (array $safe) => Log::warning('Configuration Redis delivery pending', $safe));
        });
        $this->app->bind(ThemeSnapshotReplication::class, static fn ($app) => $app->make(ThemeRedisOutbox::class));
        $this->app->bind(AgreementTemplateReplication::class, static fn ($app) => $app->make(ThemeRedisOutbox::class));
        $this->app->bind('theme_snapshot_redis.tables', static function (): \Closure {
            $db = DB::connection();
            $prefix = $db->getDriverName() === 'pgsql' ? 'control_plane.' : '';
            return static fn (string $name) => $db->table($prefix.$name);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) $this->commands([SyncThemeRedisCommand::class]);
    }
}
