<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use SixMm\Shared\TenantConfig\{EmailConfigException, SiteEmailPublisher};

$socket=getenv('EMAIL_TEST_SOCKET');
if (!is_string($socket) || !preg_match('~^/tmp/site-email-pg\.[A-Za-z0-9]+$~D',$socket)) throw new RuntimeException('Use the isolated PostgreSQL test launcher');
$capsule=new Capsule(); $capsule->addConnection(['driver'=>'pgsql','host'=>$socket,'port'=>55461,'database'=>'email_publication_test',
    'username'=>'email_test','password'=>'','schema'=>['control_plane','public']]);
$db=$capsule->getConnection();
$publisher=new SiteEmailPublisher($db,['cdn.example']);
if (($argv[1]??'') === 'worker') {
    try {
        $publisher->publish(1,10,['expectedVersion'=>1,'siteName'=>$argv[2]],7,'platform-admin:7','Concurrent brand save');
        echo "saved\n";
    } catch (EmailConfigException $e) {
        if (!str_contains($e->getMessage(),'配置已被修改')) throw $e;
        echo "conflict\n";
    }
    exit;
}
$db->unprepared('CREATE SCHEMA control_plane; CREATE SEQUENCE control_plane.wl_snapshot_version_seq START 1');
foreach ([
    'wl_tenants'=>'tenant_id bigint primary key, tenant_name text, status text',
    'wl_sites'=>'site_id bigint primary key, tenant_id bigint references control_plane.wl_tenants, site_name text, status text, entitlement_version bigint, updated_at timestamptz',
    'wl_site_domains'=>'site_id bigint references control_plane.wl_sites, domain text unique, is_primary boolean, template_id text, logo_url text, favicon_url text, color_primary text',
    'wl_site_modules'=>'site_id bigint, module_code text, module_type text, enabled boolean',
    'wl_site_config_publications'=>'publication_version bigint primary key, published_by text, publish_reason text, published_at timestamptz',
    'wl_site_config_snapshots'=>'site_id bigint references control_plane.wl_sites, config_version bigint references control_plane.wl_site_config_publications, previous_config_version bigint, rollback_of_config_version bigint, schema_version bigint check(schema_version in (4,5)), payload text, payload_sha256 text, primary key(site_id,config_version), foreign key(site_id,previous_config_version) references control_plane.wl_site_config_snapshots(site_id,config_version)',
    'wl_site_config_domains'=>'domain text primary key, site_id bigint references control_plane.wl_sites',
    'wl_site_auth_policies'=>'site_id bigint primary key, version bigint, registration_class text, email_registration_enabled boolean, email_login_enabled boolean, password_management_enabled boolean, updated_by bigint, updated_at timestamptz',
    'wl_site_email_templates'=>'site_id bigint primary key, version bigint, site_name text, default_locale text, templates jsonb, updated_by bigint',
    'wl_site_email_configs'=>"site_id bigint primary key references control_plane.wl_sites, version bigint check(version>0), site_name varchar(128), logo_url text not null default '', default_locale varchar(35), updated_by bigint check(updated_by>0), created_at timestamptz, updated_at timestamptz",
    'wl_site_email_template_items'=>"site_id bigint references control_plane.wl_site_email_configs, purpose varchar(32), locale varchar(35), subject text, text_body text, html_body text not null default '', primary key(site_id,purpose,locale)",
    'wl_site_email_template_versions'=>'site_id bigint, template_version bigint, payload text, payload_sha256 text, published_snapshot_version bigint references control_plane.wl_site_config_publications, primary key(site_id,template_version)',
    'wl_site_email_template_item_versions'=>'site_id bigint, template_version bigint, purpose varchar(32), locale varchar(35), payload text, payload_sha256 text, primary key(site_id,template_version,purpose,locale), foreign key(site_id,template_version) references control_plane.wl_site_email_template_versions(site_id,template_version)',
    'wl_site_config_themes'=>'site_id bigint, config_version bigint, schema_version bigint, payload text, payload_sha256 text, previous_config_version bigint, published_by text, publish_reason text, published_at timestamptz, primary key(site_id,config_version)',
    'wl_site_seo_configs'=>'site_id bigint, indexing_enabled boolean, translations text, og_image_url text, google_verification_token text, verification_domain text',
    'wl_channel_configs'=>'tenant_id bigint, social_links text, app_downloads text, customer_service text',
    'partner_account_profiles'=>'home_site_id bigint, agent_id bigint, support_email text, legal_entity_name text, registration_number text, registered_address text, effective_date text',
    'wl_agreements'=>'site_id bigint, scope_type text, locale text, agreement_type text, source_mode text, custom_type text, is_enabled boolean, published_version bigint, published_url text',
] as $table=>$columns) $db->statement("CREATE TABLE control_plane.$table ($columns)");
foreach (['auth_policy'=>'policy_version bigint, before_policy jsonb, after_policy jsonb','email_template'=>'template_version bigint, before_templates jsonb, after_templates jsonb'] as $kind=>$columns) {
    $db->statement("CREATE TABLE control_plane.wl_site_{$kind}_audits (id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, $columns, site_id bigint, snapshot_version bigint references control_plane.wl_site_config_publications, operator_id bigint, reason text, source_service text, request_id text, action text)");
}
$db->table('control_plane.wl_tenants')->insert(['tenant_id'=>1,'tenant_name'=>'Test','status'=>'enabled']);
$db->table('control_plane.wl_sites')->insert(['site_id'=>10,'tenant_id'=>1,'site_name'=>'Test','status'=>'enabled','entitlement_version'=>1]);
$db->table('control_plane.wl_site_domains')->insert(['site_id'=>10,'domain'=>'test.example','is_primary'=>true]);
$checks=0;
$check=static function(bool $value) use (&$checks):void { if (!$value) throw new RuntimeException('PostgreSQL email check failed #'.($checks+1)); ++$checks; };
$publisher->initializeIfMissing(1,10,7,'platform-admin:7','Initialize email');
$check($publisher->detail(1,10)['version']==='1');
$check($db->table('control_plane.wl_site_email_template_items')->count()===8);
$db->disconnect();
$processes=[];
foreach (['Concurrent A','Concurrent B'] as $name) {
    $process=proc_open([PHP_BINARY,__FILE__,'worker',$name],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start concurrent writer');
    fclose($pipes[0]); $processes[]=[$process,$pipes];
}
$outcomes=[];
foreach ($processes as [$process,$pipes]) {
    $output=trim(stream_get_contents($pipes[1])); $errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process)!==0) throw new RuntimeException('Concurrent writer failed: '.$errors);
    $outcomes[]=$output;
}
sort($outcomes);
$check($outcomes===['conflict','saved']);
$check($publisher->detail(1,10)['version']==='2');
$check($db->table('control_plane.wl_site_email_template_audits')->count()===2);
$check($db->table('control_plane.wl_site_email_template_item_versions')->count()===16);
$bodies=$db->table('control_plane.wl_site_email_template_item_versions')->where('template_version',1)->orderBy('purpose')->orderBy('locale')->get(['payload','payload_sha256'])->all();
$check($db->table('control_plane.wl_site_email_template_item_versions')->where('template_version',2)->orderBy('purpose')->orderBy('locale')->get(['payload','payload_sha256'])->all()==$bodies);
$before=$publisher->detail(1,10);
try {
    $publisher->publish(1,10,['expectedVersion'=>2,'siteName'=>'Rollback'],7,'platform-admin:7','Rollback',static fn()=>throw new RuntimeException('audit failure'));
    throw new RuntimeException('Expected rollback');
} catch(RuntimeException $e) { $check($e->getMessage()==='audit failure'); }
$check($publisher->detail(1,10)===$before);
$check($db->table('control_plane.wl_site_email_template_audits')->count()===2);
$check($db->table('control_plane.wl_site_email_template_item_versions')->count()===16);
$check($db->table('control_plane.wl_site_config_snapshots')->count()===2);
echo "PostgreSQL email publication: $checks checks passed (concurrent expectedVersion: one winner, one conflict)\n";
