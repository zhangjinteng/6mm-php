<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\TenantConfig\ThemeRedisOutboxSchema;

// Only an explicitly owned temporary Unix-socket cluster, never application PG env/config.
$socket=getenv('CONFIG_REDIS_PG_TEST_SOCKET');
if (!$socket || !str_starts_with($socket,sys_get_temp_dir().'/') || !file_exists($socket.'/.s.PGSQL.5432')) throw new RuntimeException('Owned temporary PostgreSQL socket required');
$capsule=new Capsule(); $capsule->addConnection(['driver'=>'pgsql','host'=>$socket,'port'=>5432,'database'=>'postgres','username'=>'fixture','password'=>'']);
$db=$capsule->getConnection();
$db->statement('CREATE SCHEMA control_plane');
$db->statement('CREATE TABLE control_plane.wl_sites (site_id bigint PRIMARY KEY, tenant_id bigint NOT NULL)');
$db->statement('CREATE TABLE control_plane.wl_site_config_themes (site_id bigint, config_version bigint, schema_version bigint, payload text, payload_sha256 varchar(64), previous_config_version bigint, PRIMARY KEY(site_id,config_version))');
$db->getSchemaBuilder()->create('control_plane.wl_site_theme_redis_outbox',static function (Blueprint $t): void {
    $t->bigInteger('site_id')->primary(); $t->bigInteger('config_version'); $t->bigInteger('synced_version')->nullable();
    $t->integer('attempts')->default(0); $t->timestampTz('next_attempt_at'); $t->string('last_error_code',64)->nullable();
    $t->timestampTz('synced_at')->nullable(); $t->timestampTz('updated_at');
    $t->foreign(['site_id','config_version'],'wl_theme_redis_snapshot_fk')->references(['site_id','config_version'])->on('control_plane.wl_site_config_themes');
});
$db->statement('CREATE INDEX wl_theme_redis_pending_idx ON control_plane.wl_site_theme_redis_outbox(next_attempt_at,site_id) WHERE synced_version IS NULL');
$payload='{"siteId":"10","name":"Legacy"}'; $hash=hash('sha256',$payload);
$db->table('control_plane.wl_sites')->insert(['site_id'=>10,'tenant_id'=>7]);
$db->table('control_plane.wl_site_config_themes')->insert(['site_id'=>10,'config_version'=>33,'schema_version'=>1,'payload'=>$payload,'payload_sha256'=>str_repeat('0',64)]);
$db->table('control_plane.wl_site_theme_redis_outbox')->insert(['site_id'=>10,'config_version'=>33,'synced_version'=>33,'attempts'=>2,'next_attempt_at'=>CarbonImmutable::now(),'updated_at'=>CarbonImmutable::now()]);
$checks=0;
$check=static function (bool $ok) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException('PostgreSQL migration check failed #'.$checks); };
try { ThemeRedisOutboxSchema::upgrade($db); throw new LogicException('Corrupt source accepted'); }
catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(),'rolled back')) throw new RuntimeException('Unexpected isolated migration failure: '.$e->getMessage(), 0, $e);
    $check(true);
}
$check(!(bool)$db->selectOne("SELECT EXISTS(SELECT 1 FROM pg_attribute WHERE attrelid='control_plane.wl_site_theme_redis_outbox'::regclass AND attname='resource_type' AND NOT attisdropped) AS present")->present);
$check((int)$db->table('control_plane.wl_site_theme_redis_outbox')->value('synced_version')===33);
$db->table('control_plane.wl_site_config_themes')->update(['payload_sha256'=>$hash]);
$tables=fn () => array_map(static fn ($r) => $r->tablename,$db->select("SELECT tablename FROM pg_tables WHERE schemaname='control_plane' ORDER BY tablename"));
$before=$tables();
ThemeRedisOutboxSchema::upgrade($db);
ThemeRedisOutboxSchema::create($db); // safe when the second host also applies its migration
$check($tables()===$before);
$row=$db->table('control_plane.wl_site_theme_redis_outbox')->sole();
$check($row->resource_type==='theme' && (int)$row->resource_id===10 && $row->locale==='' && (int)$row->tenant_id===7);
$check($row->payload===$payload && $row->payload_sha256===$hash && (int)$row->schema_version===1);
$check($row->synced_version===null && (int)$row->attempts===0);
$base=['resource_type'=>'agreement_template','resource_id'=>10,'locale'=>'en','tenant_id'=>null,'site_id'=>null,
    'config_version'=>34,'schema_version'=>1,'payload'=>'{}','payload_sha256'=>hash('sha256','{}'),'next_attempt_at'=>CarbonImmutable::now(),'updated_at'=>CarbonImmutable::now()];
$db->table('control_plane.wl_site_theme_redis_outbox')->insert($base);
$db->table('control_plane.wl_site_theme_redis_outbox')->insert(array_replace($base,['locale'=>'zh-CN']));
$check($db->table('control_plane.wl_site_theme_redis_outbox')->count()===3);
foreach ([['locale'=>'en:other'],['tenant_id'=>7,'locale'=>'fr'],['resource_type'=>'unknown','locale'=>'fr'],['config_version'=>0,'locale'=>'fr']] as $bad) {
    try { $db->transaction(fn ()=>$db->table('control_plane.wl_site_theme_redis_outbox')->insert(array_replace($base,$bad))); throw new LogicException('Invalid scope accepted'); }
    catch (\Illuminate\Database\QueryException $e) { $check(true); }
}
$check((int)$db->selectOne("SELECT COUNT(*) AS n FROM pg_constraint WHERE conrelid='control_plane.wl_site_theme_redis_outbox'::regclass AND contype='f'")->n===0);
$check((int)$db->selectOne("SELECT COUNT(*) AS n FROM pg_constraint WHERE conrelid='control_plane.wl_site_theme_redis_outbox'::regclass AND conname='wl_config_redis_resource_pk'")->n===1);
// The fresh-install branch must create the same single canonical table.
$db->statement('DROP TABLE control_plane.wl_site_theme_redis_outbox');
ThemeRedisOutboxSchema::create($db);
ThemeRedisOutboxSchema::create($db);
$check($tables()===$before);
$db->table('control_plane.wl_site_theme_redis_outbox')->insert($base);
$check($db->table('control_plane.wl_site_theme_redis_outbox')->sole()->resource_type==='agreement_template');
echo "PostgreSQL generic Outbox upgrade: {$checks} checks passed\n";
