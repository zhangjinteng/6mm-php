<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/fixtures/site_themes.php';

use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseTransactionsManager;
use SixMm\Shared\TenantConfig\ThemeRedisOutbox;
use SixMm\Shared\TenantConfig\ThemeRedisOutboxSchema;
use SixMm\Shared\TenantConfig\ThemeRedisPublisher;
use SixMm\Shared\TenantConfig\ThemeSnapshotWriter;

$checks = 0;
$check = static function (bool $ok) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException('Theme Redis check failed #'.$checks); };
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
$db->setTransactionManager(new DatabaseTransactionsManager());
$db->statement('CREATE TABLE wl_tenants (tenant_id integer primary key, status text)');
$db->statement('CREATE TABLE wl_sites (site_id integer primary key, tenant_id integer, site_name text, status text)');
themeFixture($db);
ThemeRedisOutboxSchema::create($db);
ThemeRedisOutboxSchema::create($db); // idempotent migration in either host
$db->statement('PRAGMA foreign_keys=ON');
$db->table('wl_tenants')->insert(['tenant_id' => 1, 'status' => 'enabled']);
$db->table('wl_sites')->insert(['site_id' => 910024, 'tenant_id' => 1, 'site_name' => 'Test', 'status' => 'enabled']);
$table = fn (string $name) => $db->table($name);
$redis = new class($db) {
    public array $hashes = [];
    public int $calls = 0;
    public bool $fail = false;
    public bool $unacknowledged = false;
    public ?Closure $duringPublish = null;
    public function __construct(private $db) {}
    public function eval(string $script, int $keys, string $key, string ...$values): int|false
    {
        if ($this->db->transactionLevel() !== 0) throw new LogicException('Uncommitted Redis write!');
        ++$this->calls;
        if ($this->fail) throw new RuntimeException('driver accidentally includes widget-secret-and-password');
        if ($this->unacknowledged) return false;
        $this->hashes[$key] = array_combine(['site_id','config_version','schema_version','payload','payload_sha256','previous_config_version'], $values);
        if ($this->duringPublish) { $hook = $this->duringPublish; $this->duringPublish = null; $hook(); }
        return 1;
    }
};
$logs = [];
$publisher = new ThemeRedisPublisher(fn () => $redis);
$outbox = new ThemeRedisOutbox($publisher, true, static function ($safe) use (&$logs): void { $logs[] = $safe; });
$writer = new ThemeSnapshotWriter($table, $outbox);
$sync = fn () => $db->transaction(fn () => $writer->sync(1,910024,7,'test:7','fixture'));
$key = 'wl:site:config:theme:910024';
$db->transaction(function () use ($db, $writer, $redis, $check): void {
    $writer->sync(1,910024,7,'test:7','first');
    $check($redis->calls === 0);
    $check($db->table(ThemeRedisOutbox::TABLE)->sole()->synced_version === null);
});
$check($redis->calls === 1 && isset($redis->hashes[$key]));
$row = $db->table('wl_site_config_themes')->sole();
$check($redis->hashes[$key]['payload'] === $row->payload && $redis->hashes[$key]['payload_sha256'] === $row->payload_sha256);
$check($redis->hashes[$key]['previous_config_version'] === '');
$check((int)$db->table(ThemeRedisOutbox::TABLE)->sole()->synced_version === (int)$row->config_version);
$db->transaction(function () use ($db, $writer, $redis, $check): void {
    $db->transaction(fn () => $writer->sync(1,910024,7,'test:7','inner'));
    $check($redis->calls === 1); // root, not savepoint commit
});
$check($redis->calls === 2);
$calls = $redis->calls;
try { $db->transaction(function () use ($db, $writer): void {
    $db->table('wl_sites')->update(['site_name' => 'rollback']);
    $db->transaction(fn () => $writer->sync(1,910024,7,'test:7','rollback'));
    throw new RuntimeException('rollback fixture');
}); } catch (RuntimeException $e) { $check($e->getMessage() === 'rollback fixture'); }
$check($redis->calls === $calls && $db->table('wl_site_config_themes')->count() === 1);
$check($db->table('wl_sites')->value('site_name') === 'Test');
// Also discard callbacks belonging to a rolled-back savepoint, while root commits.
$db->transaction(function () use ($db, $writer): void {
    try { $db->transaction(function () use ($writer): void {
        $writer->sync(1,910024,7,'test:7','savepoint rollback'); throw new RuntimeException('savepoint');
    }); } catch (RuntimeException $e) {}
});
$check($redis->calls === $calls);
unset($redis->hashes[$key]);
$check($sync()['changed'] === false && isset($redis->hashes[$key]));
$check($db->table('wl_site_config_themes')->count() === 1);
// Outbox failure must roll back source and theme together, without Redis IO.
$calls = $redis->calls;
$db->statement("CREATE TRIGGER deny_outbox BEFORE UPDATE ON wl_site_theme_redis_outbox BEGIN SELECT RAISE(ABORT,'fixture'); END");
try { $db->transaction(function () use ($db, $writer): void {
    $db->table('wl_sites')->update(['site_name' => 'must roll back']);
    $writer->sync(1,910024,7,'test:7','outbox failure');
}); throw new LogicException('Outbox failure swallowed'); } catch (\Illuminate\Database\QueryException $e) { $check(true); }
$db->statement('DROP TRIGGER deny_outbox');
$check($redis->calls === $calls && $db->table('wl_sites')->value('site_name') === 'Test');
$check($db->table('wl_site_config_themes')->count() === 1);
// Post-commit Redis failure is pending, not a false rollback or a payload-bearing exception.
$redis->fail = true;
$db->table('wl_sites')->update(['site_name' => 'committed']);
$check($sync()['changed']);
$job = $db->table(ThemeRedisOutbox::TABLE)->sole();
$check($job->synced_version === null && $job->attempts === 1 && $job->last_error_code === 'config_redis_delivery_failed');
$check($db->table('wl_sites')->value('site_name') === 'committed' && $db->table('wl_site_config_themes')->count() === 2);
$check(!str_contains(json_encode($logs), 'widget-secret') && !str_contains(json_encode($job), 'password'));
$check($outbox->drain($table)['processed'] === 0); // backoff
$redis->fail = false;
$db->table(ThemeRedisOutbox::TABLE)->update(['next_attempt_at' => CarbonImmutable::now('UTC')->subSecond()]);
$check($outbox->drain($table)['synced'] === 1);
$check($outbox->drain($table)['processed'] === 0);
$check(json_decode($redis->hashes[$key]['payload'])->name === 'committed');
// Rebuild re-enqueues successful rows after cache loss, but leaves delivery to the caller.
unset($redis->hashes[$key]); $calls = $redis->calls;
$check($outbox->rebuild($table, 10) === [910024] && $redis->calls === $calls);
$check($outbox->drain($table)['synced'] === 1 && isset($redis->hashes[$key]));
$check($outbox->rebuild($table, 10, null, 910024) === []);
$check((new ThemeRedisPublisher(fn () => $redis, 'custom:themes'))->key(910024) === 'custom:themes:910024');
try { new ThemeRedisPublisher(fn () => $redis, 'bad prefix'); throw new LogicException('Unsafe prefix'); }
catch (InvalidArgumentException $e) { $check(true); }
$redis->unacknowledged = true;
$sync();
$check($db->table(ThemeRedisOutbox::TABLE)->sole()->synced_version === null);
$redis->unacknowledged = false;
$db->table(ThemeRedisOutbox::TABLE)->update(['next_attempt_at' => CarbonImmutable::now('UTC')->subSecond()]);
$check($outbox->drain($table)['synced'] === 1);
// A newer enqueue between Redis acknowledgment and Outbox update must not be marked synced by an old worker.
$redis->duringPublish = function () use ($db, $table, $outbox): void {
    $db->transaction(function () use ($db, $table, $outbox): void {
        $db->table('wl_sites')->update(['site_name' => 'RaceNew']);
        (new ThemeSnapshotWriter($table))->sync(1,910024,7,'test:7','new concurrent target');
        $outbox->enqueue($table, 910024, false);
    });
};
$sync();
$check($db->table(ThemeRedisOutbox::TABLE)->sole()->synced_version === null);
$check($outbox->drain($table)['synced'] === 1 && json_decode($redis->hashes[$key]['payload'])->name === 'RaceNew');
// Disabled installation must not need an Outbox table or contact Redis.
$disabled = new ThemeSnapshotWriter($table, new ThemeRedisOutbox($publisher, false));
$calls = $redis->calls;
$db->transaction(fn () => $disabled->sync(1,910024,7,'test:7','disabled'));
$check($redis->calls === $calls);
$db->statement('ALTER TABLE wl_site_theme_redis_outbox RENAME TO fixture_outbox_backup');
try { $db->transaction(function () use ($db, $writer): void {
    $db->table('wl_sites')->update(['site_name' => 'missing outbox must roll back']);
    $writer->sync(1,910024,7,'test:7','missing outbox');
}); throw new LogicException('Missing Outbox ignored'); } catch (\Illuminate\Database\QueryException $e) { $check(true); }
$db->statement('ALTER TABLE fixture_outbox_backup RENAME TO wl_site_theme_redis_outbox');
$check($redis->calls === $calls && $db->table('wl_sites')->value('site_name') === 'RaceNew');
echo "Theme Redis Outbox: {$checks} checks passed\n";
