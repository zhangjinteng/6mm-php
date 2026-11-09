<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use SixMm\Shared\TenantBrand\{DomainConfigStore, DomainConfigValidator, DomainConfigException};

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
$db->statement('CREATE TABLE wl_tenants (tenant_id integer PRIMARY KEY, tenant_name text, status text DEFAULT \'enabled\', updated_at text)');
$db->statement('CREATE TABLE wl_sites (site_id integer PRIMARY KEY, tenant_id integer, site_name text, entitlement_version integer DEFAULT 1, status text, updated_at text)');
$db->statement('CREATE TABLE wl_site_domains (site_id integer, domain text UNIQUE, is_primary boolean, created_at text)');
$db->statement('CREATE TABLE wl_site_config_domains (domain text PRIMARY KEY, site_id integer NOT NULL)');
$db->statement('CREATE TABLE wl_config_snapshots (snapshot_version integer PRIMARY KEY, schema_version integer, payload text, payload_sha256 text, previous_snapshot_version integer, rollback_of_snapshot_version integer, published_by text, publish_reason text, published_at text)');
$db->table('wl_tenants')->insert([['tenant_id' => 1, 'tenant_name' => 'Alpha'], ['tenant_id' => 2, 'tenant_name' => 'Beta']]);
$db->table('wl_sites')->insert([
    ['site_id' => 10, 'tenant_id' => 1, 'site_name' => 'Main', 'status' => 'enabled'],
    ['site_id' => 11, 'tenant_id' => 1, 'site_name' => 'Disabled', 'status' => 'disabled'],
    ['site_id' => 20, 'tenant_id' => 2, 'site_name' => 'Other', 'status' => 'enabled'],
]);
$db->table('wl_site_domains')->insert([['site_id' => 10, 'domain' => 'one.example', 'is_primary' => true], ['site_id' => 20, 'domain' => 'other.example', 'is_primary' => true]]);
$payload = (object) ['schemaVersion' => 4, 'snapshotVersion' => 8, 'extension' => (object) [], 'sites' => [
    (object) ['siteId' => 10, 'tenantId' => 1, 'domains' => ['one.example'], 'authPolicy' => (object) ['keep' => true]],
    (object) ['siteId' => 20, 'tenantId' => 2, 'domains' => ['other.example']],
]];
$json = json_encode($payload, JSON_THROW_ON_ERROR);
$db->table('wl_config_snapshots')->insert(['snapshot_version' => 8, 'schema_version' => 4, 'payload' => $json, 'payload_sha256' => hash('sha256', $json)]);
require __DIR__.'/fixtures/site_publication.php';
$db->statement('CREATE TABLE wl_site_modules (site_id integer, module_code text, enabled boolean)');
publicationFixture($db, [
    ['siteId'=>10,'tenantId'=>1,'domains'=>['one.example'],'entitlements'=>[],'entitlementVersion'=>1,'status'=>'enabled'],
    ['siteId'=>20,'tenantId'=>2,'domains'=>['other.example'],'entitlements'=>[],'entitlementVersion'=>1,'status'=>'enabled'],
]);
$store = new DomainConfigStore($db, true);
$checks = 0;
$check = function (bool $value) use (&$checks): void { ++$checks; if (!$value) throw new RuntimeException('Brand check failed #' . $checks); };
$detail = $store->detail(['tenant_id' => '1']);
$check(array_column($detail['sites'], 'site_id') === ['10']);
$check(!str_contains(json_encode($detail), 'authPolicy'));
$input = ['tenant_id' => '1', 'site_id' => '10', 'expected_config_version' => $detail['sites'][0]['config_version'], 'expected_revision' => $detail['sites'][0]['revision'],
    'domains' => [['domain' => 'one.example', 'is_primary' => true], ['domain' => ' BACKUP.Example. ', 'is_primary' => false]]];
$audits = [];
$audit = function (array $change) use (&$audits): void { $audits[] = $change; };
$saved = $store->save($input, 7, $audit, 'white_label');
$check($saved['snapshot_version'] === '9');
$check($db->table('wl_site_config_publications')->where('publication_version', 9)->value('published_by') === 'white-label-admin:7');
$check($db->table('wl_config_snapshots')->where('snapshot_version', 8)->value('payload') === $json);
$published = json_decode($db->table('wl_site_config_snapshots')->where('config_version', 9)->value('payload'));
$check($published->authPolicy == (object)['version'=>2,'registrationClass'=>'white_label','emailRegistrationEnabled'=>true,'emailLoginEnabled'=>true,'passwordManagementEnabled'=>false]);
$body = $db->table('wl_site_email_template_versions')->where('site_id',10)->first();
$check($published->emailTemplateRef->sha256 === hash('sha256',$body->payload) && $body->published_snapshot_version === 9);
$check(json_decode($body->payload,true) === ['version'=>1,'siteName'=>'Main','defaultLocale'=>'zh-CN','templates'=>[]]);
$check($db->table('wl_site_config_snapshots')->where('site_id',20)->max('config_version') === 8);
$check($db->table('wl_site_auth_policy_audits')->count() === 1 && $db->table('wl_site_email_template_audits')->count() === 1);
$check($db->table('wl_site_config_snapshots')->where('config_version',9)->value('previous_config_version') === 8);
$check($audits[0]['tenant_id'] === '1' && $audits[0]['site_id'] === '10');
try { $store->save($input, 7, $audit); throw new RuntimeException('Stale write accepted'); }
catch (DomainConfigException $e) { $check(true); }
$input['expected_revision'] = $saved['revision'];
try { $store->save($input,7,$audit); throw new RuntimeException('Stale config version accepted'); }
catch (DomainConfigException $e) { $check(true); }
$input['expected_config_version'] = $saved['config_version'];
$check(!$store->save($input, 7, $audit)['changed']);
$check(count($audits) === 1);
foreach (['site_id' => '20', 'disabled_site' => '11'] as $site) {
    try { $store->save(array_replace($input, ['site_id' => $site]), 7, $audit); throw new RuntimeException('Unowned/disabled site accepted'); }
    catch (DomainConfigException $e) { $check(true); }
}
foreach (['https://bad.test', '*.test', 'localhost', 'a.test/path', 'a.test:443', '127.0.0.1', 'a..test'] as $name) {
    try { DomainConfigValidator::normalize([['domain' => $name, 'is_primary' => true]]); throw new RuntimeException('Invalid domain accepted'); }
    catch (DomainConfigException $e) { $check(true); }
}
$input['domains'][] = ['domain' => 'rollback.example', 'is_primary' => false];
try { $store->save($input, 7, static function (): void { throw new RuntimeException('Audit failed'); }); }
catch (RuntimeException $e) { $check($e->getMessage() === 'Audit failed'); }
$check(!$db->table('wl_site_domains')->where('domain', 'rollback.example')->exists());
$check(!$db->table('wl_site_config_domains')->where('domain', 'rollback.example')->exists());
$check($db->table('wl_site_config_publications')->count() === 2 && $db->table('wl_config_snapshots')->count() === 1);
$db->table('wl_site_config_domains')->insert(['domain' => 'rollback.example', 'site_id' => 20]);
try { $store->save($input, 7, $audit); throw new RuntimeException('Gateway domain reassigned'); }
catch (DomainConfigException $e) { $check(str_contains($e->getMessage(), '其他站点')); }
$check(!$db->table('wl_site_domains')->where('domain', 'rollback.example')->exists());
$check((int) $db->table('wl_site_config_domains')->where('domain', 'rollback.example')->value('site_id') === 20);
$check($db->table('wl_site_config_publications')->count() === 2 && $db->table('wl_config_snapshots')->count() === 1);
array_pop($input['domains']);
$db->table('wl_site_config_domains')->where('site_id', 10)->delete();
$check($store->save($input, 7, $audit)['changed']);
$check($db->table('wl_site_config_domains')->where('site_id', 10)->orderBy('domain')->pluck('domain')->all() === ['backup.example', 'one.example']);
$check($db->table('wl_site_config_publications')->count() === 2 && $db->table('wl_config_snapshots')->count() === 1);
// Initializing missing fields must not convert an existing merchant-only login policy.
$db->table('wl_site_auth_policies')->insert(['site_id'=>20, 'version'=>5, 'registration_class'=>'agent_only',
    'email_registration_enabled'=>false, 'email_login_enabled'=>false, 'password_management_enabled'=>false]);
$writer = new \SixMm\Shared\TenantConfig\ConfigSnapshotWriter($db->table(...), static fn ($error) => throw new DomainConfigException($error));
$publish = fn ($expected, $disable) => $db->transaction(function () use ($db, $writer, $expected, $disable) {
    $writer->lock();
    if ($disable) $db->table('wl_tenants')->where('tenant_id',2)->update(['status'=>'disabled']);
    return $writer->append(2,20,$expected,7,'platform-admin:7','测试单站发布');
});
$result = $publish(8,false);
[, $first] = $writer->latest(2,20);
$check($first->authPolicy->version === 5 && !$first->authPolicy->emailLoginEnabled && $first->authPolicy->registrationClass === 'agent_only');
$publish((int)$result['config_version'],true);
[, $second] = $writer->latest(2,20);
$check($second->status === 'disabled' && $second->authPolicy->version === 6 && $second->entitlementVersion === 2);
$check($first->emailTemplateRef == $second->emailTemplateRef && $db->table('wl_site_email_template_versions')->where('site_id',20)->count() === 1);
$check($db->table('wl_site_auth_policy_audits')->where('site_id',20)->value('action') === 'availability_change');
echo "Tenant brand: {$checks} checks passed\n";
