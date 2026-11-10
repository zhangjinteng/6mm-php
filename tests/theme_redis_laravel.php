<?php

declare(strict_types=1);

// No Kernel/.env/bootstrap of either host is loaded. Only an owned Unix-socket Redis and SQLite.
$host = getenv('THEME_REDIS_TEST_HOST');
$allowed = [dirname(__DIR__, 2).'/agent_admin', dirname(__DIR__, 3).'/platform/platform_admin'];
if (!in_array($host, $allowed, true)) throw new RuntimeException('Select one explicit local host source tree');
$socket = getenv('THEME_REDIS_TEST_SOCKET');
if (!$socket || !str_starts_with($socket, sys_get_temp_dir().'/') || !file_exists($socket)) throw new RuntimeException('Owned test socket required');
require $host.'/vendor/autoload.php';
$loader = new Composer\Autoload\ClassLoader();
$loader->addPsr4('SixMm\\Shared\\', dirname(__DIR__).'/src');
$loader->addPsr4('App\\', $host.'/app');
$loader->register(true);
require __DIR__.'/fixtures/site_themes.php';

use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use SixMm\Shared\TenantConfig\ThemeRedisOutbox;
use SixMm\Shared\TenantConfig\ThemeSnapshotReplication;
use SixMm\Shared\TenantConfig\ThemeSnapshotWriter;
use SixMm\Shared\TenantConfig\SyncThemeRedisCommand;
use SixMm\Shared\TenantConfig\AgreementTemplatePublication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

$checks = 0;
$check = static function (bool $ok) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException('Laravel theme check failed #'.$checks); };
$app = new Application($host);
$app->instance('config', new Repository(['app'=>['env'=>'testing'], 'database'=>['redis'=>['client'=>'phpredis','options'=>['prefix'=>'wrong-app-prefix:']]],
    'theme_snapshot_redis'=>['enabled'=>true, 'key_prefix'=>'isolated:laravel:theme', 'template_key_prefix'=>'isolated:laravel:template',
        'redis'=>['host'=>$socket,'port'=>0,'database'=>0,'timeout'=>1,'read_timeout'=>1,'prefix'=>'ignored:']]]));
$capsule = new Capsule($app);
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$app->instance('db', $capsule->getDatabaseManager());
Facade::setFacadeApplication($app);
$db = $capsule->getConnection();
$db->setTransactionManager(new DatabaseTransactionsManager());
$db->statement('CREATE TABLE wl_tenants (tenant_id integer primary key, status text, tenant_name text)');
$db->statement('CREATE TABLE wl_sites (site_id integer primary key, tenant_id integer, site_name text, status text)');
$db->statement('CREATE TABLE wl_agreements (id integer primary key, scope_type text, site_id integer, agreement_type text, code text, name text, locale text, is_enabled boolean, published_version integer, published_sections text, draft_sections text)');
themeFixture($db);
(require $host.'/database/migrations/2026_11_10_120000_create_wl_site_theme_redis_outbox.php')->up();
$db->table('wl_tenants')->insert(['tenant_id'=>1,'status'=>'enabled','tenant_name'=>'White label, not site name']);
$db->table('wl_sites')->insert(['site_id'=>910024,'tenant_id'=>1,'site_name'=>'Laravel fixture','status'=>'enabled']);
$app->register(\App\Providers\AppServiceProvider::class);
$check($app->bound(ThemeSnapshotReplication::class));
$table = $app->make('theme_snapshot_redis.tables');
$writer = new ThemeSnapshotWriter($table); // exactly the unchanged host call sites
$redis = new Redis(); $redis->connect($socket);
$key = 'isolated:laravel:theme:910024'; $redis->del($key);
$db->transaction(fn () => $writer->sync(1,910024,7,'test:7','host integration'));
$check($redis->hGet($key,'config_version') === '1');
$check(json_decode($redis->hGet($key,'payload'))->config->legal->entity->whiteLabelName==='White label, not site name');
$check($redis->hGet('wrong-app-prefix:'.$key,'payload') === false);
$check($db->table(ThemeRedisOutbox::TABLE)->value('synced_version') === 1);
$redis->del($key);
$command = new SyncThemeRedisCommand(); $command->setLaravel($app);
$output = new BufferedOutput();
$exit = $command->run(new ArrayInput(['--rebuild'=>true,'--site'=>'910024']), $output);
$check($exit === 0 && str_contains($output->fetch(), 'synced: 1'));
$check(json_decode($redis->hGet($key,'payload'))->name === 'Laravel fixture');
$redis->hSet($key, 'payload', 'fixture-corruption-not-to-log');
$output = new BufferedOutput();
$check($command->run(new ArrayInput(['--rebuild'=>true,'--site'=>'910024']), $output) === 1);
$check(!str_contains($output->fetch(), 'fixture-corruption-not-to-log'));
$check($db->table(ThemeRedisOutbox::TABLE)->value('synced_version') === null);
$redis->del($key);
$db->table(ThemeRedisOutbox::TABLE)->update(['next_attempt_at'=>\Carbon\CarbonImmutable::now('UTC')->subSecond()]);
$output = new BufferedOutput();
$check($command->run(new ArrayInput(['--site'=>'910024']), $output) === 0);
$check(json_decode($redis->hGet($key,'payload'))->name === 'Laravel fixture');
$output = new BufferedOutput();
$check($command->run(new ArrayInput(['--site'=>'not-a-site']), $output) === 1);
$check(!str_contains($output->fetch(),'Laravel fixture'));
$redis->del($key);
$db->table('wl_agreements')->insert(['id'=>910024,'scope_type'=>'platform','site_id'=>null,'agreement_type'=>'terms',
    'code'=>'TPL-LEGAL-001','name'=>'Terms','locale'=>'en','is_enabled'=>true,'published_version'=>2,
    'published_sections'=>'[{"key":"a","title":"Published","body_html":"<p>{{WHITE_LABEL_NAME}}</p>"}]',
    'draft_sections'=>'[{"key":"a","title":"Draft","body_html":"<p>DO-NOT-EXPOSE-DRAFT</p>"}]']);
$templateKey='isolated:laravel:template:910024:en'; $redis->del($templateKey);
$db->transaction(fn ()=>AgreementTemplatePublication::sync($table,910024));
$check($redis->hGet($templateKey,'template_id')==='910024' && $redis->hGet($templateKey,'locale')==='en');
$check($redis->hGet($templateKey,'site_id')===false);
$check(!str_contains($redis->hGet($templateKey,'payload'),'DO-NOT-EXPOSE-DRAFT'));
$check($db->table(ThemeRedisOutbox::TABLE)->count()===2);
$redis->del($templateKey);
$output=new BufferedOutput();
$check($command->run(new ArrayInput(['--rebuild'=>true,'--type'=>'agreement_template','--template'=>'910024','--locale'=>'en']),$output)===0);
$check(json_decode($redis->hGet($templateKey,'payload'))->publishedVersion==='2');
$output=new BufferedOutput();
$check($command->run(new ArrayInput(['--site'=>'910024','--template'=>'910024']),$output)===1);
$redis->del($templateKey);
echo basename(dirname($host))." host theme integration: {$checks} checks passed\n";
