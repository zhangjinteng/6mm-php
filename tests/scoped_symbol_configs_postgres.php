<?php

declare(strict_types=1);

// The application .env is never read. Only an explicitly local, empty disposable DB is permitted.
require dirname(__DIR__).'/vendor/autoload.php';
use Illuminate\Database\Capsule\Manager as Capsule;
use SixMm\Shared\SymbolConfigs\ScopedSymbolConfigService;

$port=(int)(getenv('SCOPED_SYMBOL_TEST_PORT')?:55479);
$database=getenv('SCOPED_SYMBOL_TEST_DATABASE')?:'scoped_symbol_sparse_test';
if ($port<1024 || !preg_match('/^scoped_symbol_sparse_test(?:_[a-z0-9]+)?$/D',$database)) throw new RuntimeException('Use an owned local test database');
$capsule=new Capsule();
$capsule->addConnection(['driver'=>'pgsql','host'=>'127.0.0.1','port'=>$port,'database'=>$database,
    'username'=>getenv('USER')?:'mac','password'=>'','charset'=>'utf8','prefix'=>'','schema'=>'public','sslmode'=>'disable']);
$db=$capsule->getConnection();
if ($db->getSchemaBuilder()->hasTable('symbol_config')) throw new RuntimeException('Test requires an empty disposable database');
// These are existing upstream columns, not a migration or a proposed schema change.
$db->statement('CREATE TABLE symbol_config (id serial PRIMARY KEY,symbol text NOT NULL,
    base_asset text,quote_asset text,liquidation_fee numeric,min_quantity numeric,version bigint,sort integer,is_visible boolean,is_active smallint,
    home_site_id bigint NOT NULL DEFAULT 0,agent_id bigint NOT NULL DEFAULT 0,base_config_id integer REFERENCES symbol_config(id),
    revision bigint NOT NULL DEFAULT 1,created_at timestamptz NOT NULL DEFAULT now(),updated_at timestamptz NOT NULL DEFAULT now(),deleted_at timestamptz,
    UNIQUE(home_site_id,agent_id,symbol),CHECK(home_site_id>=0 AND agent_id>=0))');
$db->statement('CREATE TABLE symbol_tag (id serial PRIMARY KEY,home_site_id bigint NOT NULL DEFAULT 0,agent_id bigint NOT NULL DEFAULT 0,
    base_tag_id integer REFERENCES symbol_tag(id),tag_name text,tag_code text,tag_name_zh text,tag_name_en text,parent_id integer NOT NULL DEFAULT 0,
    sort integer,is_enable integer,count integer NOT NULL DEFAULT 0,revision bigint NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL DEFAULT now(),updated_at timestamptz NOT NULL DEFAULT now(),deleted_at timestamptz,
    UNIQUE(home_site_id,agent_id,base_tag_id))');
$db->statement('CREATE TABLE symbol_config_tag (id serial PRIMARY KEY,home_site_id bigint NOT NULL DEFAULT 0,agent_id bigint NOT NULL DEFAULT 0,
    symbol_config_id integer NOT NULL,symbol_tag_id integer NOT NULL,is_attached boolean NOT NULL DEFAULT true,
    revision bigint NOT NULL DEFAULT 1,UNIQUE(home_site_id,agent_id,symbol_config_id,symbol_tag_id))');
$fingerprint=fn()=>json_encode($db->select("SELECT table_name,column_name,data_type,is_nullable,column_default
    FROM information_schema.columns WHERE table_schema='public' ORDER BY table_name,ordinal_position"));
$schema=$fingerprint();
$rootId=$db->table('symbol_config')->insertGetId(['symbol'=>'BTCUSDT','base_asset'=>'BTC','quote_asset'=>'USDT',
    'liquidation_fee'=>'0.005','min_quantity'=>'0.001','version'=>4,'sort'=>10,'is_visible'=>true,'is_active'=>1]);
$tagId=$db->table('symbol_tag')->insertGetId(['tag_name'=>'Crypto','tag_code'=>'crypto','sort'=>10,'is_enable'=>1]);
$db->table('symbol_config_tag')->insert(['symbol_config_id'=>$rootId,'symbol_tag_id'=>$tagId]);
$service=new ScopedSymbolConfigService($db);
$service->ensureScope(10,20);
$site=$service->query(10)->sole(); $merchant=$service->query(10,20)->sole();
foreach ([$site,$merchant] as $row) {
    $rule=$db->table('symbol_config_tag')->where('symbol_config_id',$row->id)->sole();
    $tag=$db->table('symbol_tag')->where('id',$rule->symbol_tag_id)->sole();
    if (!$rule->is_attached || $rule->home_site_id!==$row->home_site_id || $rule->agent_id!==$row->agent_id
        || $tag->home_site_id!==$row->home_site_id || $tag->agent_id!==$row->agent_id || $tag->base_tag_id!==$tagId) {
        throw new RuntimeException('Initial tag relation must use local config and tag IDs');
    }
}
if ($merchant->is_visible!==null || $merchant->is_active!==null || $merchant->version!==null || $merchant->liquidation_fee!==null) {
    throw new RuntimeException('Initialization must keep preferences and public fields NULL');
}
$service->update(10,0,$site->id,['sort'=>0,'is_visible'=>false,'is_active'=>0]);
$effective=$service->effectiveQuery(10,20)->sole();
if ($effective->sort!==0 || $effective->is_visible!==false || $effective->is_active!==0) throw new RuntimeException('Inherited false or zero lost');
$service->update(10,20,$merchant->id,['is_visible'=>false,'is_active'=>0,'tags'=>[]]);
$service->update(10,0,$site->id,['is_visible'=>true,'is_active'=>1]);
$effective=$service->effectiveQuery(10,20)->sole();
if ($effective->is_visible!==false || $effective->is_active!==0) throw new RuntimeException('Explicit lower-level off lost');
if ($service->tagsFor(10,20,[$merchant])[$merchant->id]!==[]) throw new RuntimeException('Explicit detach lost');
$childBefore=json_encode($service->query(10,20)->sole());
$db->table('symbol_config')->where('id',$rootId)->update(['liquidation_fee'=>'0.008','version'=>9]);
if ($service->effectiveQuery(10,20)->sole()->liquidation_fee!=='0.008' || $service->effectiveQuery(10,20)->sole()->version!==9) {
    throw new RuntimeException('Public parameters must resolve from root without updates');
}
if ($childBefore!==json_encode($service->query(10,20)->sole())) throw new RuntimeException('Public update wrote child data');
$service->update(10,20,$merchant->id,['tags'=>null,'is_visible'=>null,'is_active'=>null]);
$effective=$service->effectiveQuery(10,20)->sole();
if ($effective->is_visible!==true || $effective->is_active!==1 || count($service->tagsFor(10,20,[$merchant])[$merchant->id])!==1) {
    throw new RuntimeException('Resetting inheritance failed');
}
if ($service->backfillTagRelations(10,20)!==1 || $service->backfillTagRelations(10,20)!==0) {
    throw new RuntimeException('Explicit tag backfill must write once with PostgreSQL scope matching');
}
$rule=$db->table('symbol_config_tag')->where('symbol_config_id',$merchant->id)->sole();
$localTag=$db->table('symbol_tag')->where('id',$rule->symbol_tag_id)->sole();
if (!$rule->is_attached || $localTag->home_site_id!==10 || $localTag->agent_id!==20) {
    throw new RuntimeException('Backfill must point to local tags');
}
if ($schema!==$fingerprint()) throw new RuntimeException('Service modified upstream schema');
echo "PostgreSQL sparse config: NULL storage, boolean inheritance, tag detach/reset, root-only public parameters and unchanged schema passed\n";
