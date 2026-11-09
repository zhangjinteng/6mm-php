<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use SixMm\Shared\TenantSeo\SeoConfigStore;

$container = new Container();
$container->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $container));
Facade::setFacadeApplication($container);
$capsule = new Capsule(); $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
$db->statement('CREATE TABLE wl_tenants (tenant_id integer PRIMARY KEY, tenant_name text, status text)');
$db->statement('CREATE TABLE wl_sites (site_id integer PRIMARY KEY, tenant_id integer, site_name text, status text)');
$db->statement('CREATE TABLE wl_site_domains (site_id integer, domain text, is_primary boolean)');
$db->statement('CREATE TABLE wl_site_seo_configs (site_id integer PRIMARY KEY, indexing_enabled boolean, default_locale text, translations text, og_image_url text, google_verification_token text, verification_domain text, version integer, created_by integer, created_by_type text, updated_by integer, updated_by_type text, created_at text, updated_at text)');
$db->statement('CREATE TABLE wl_config_snapshots (payload text)');
$db->table('wl_config_snapshots')->insert(['payload' => 'unchanged']);
$db->table('wl_tenants')->insert([['tenant_id' => 1, 'tenant_name' => 'Tenant_%', 'status' => 'enabled'], ['tenant_id' => 2, 'tenant_name' => 'Other', 'status' => 'enabled']]);
foreach ([10, 11, 12, 20] as $id) {
    $db->table('wl_sites')->insert(['site_id' => $id, 'tenant_id' => $id === 20 ? 2 : 1, 'site_name' => 'Site'.$id, 'status' => $id === 12 ? 'disabled' : 'enabled']);
    $db->table('wl_site_domains')->insert(['site_id' => $id, 'domain' => 'site'.$id.'.example', 'is_primary' => true]);
}
$platform = new SeoConfigStore($db); $tenant = new SeoConfigStore($db, true);
require __DIR__.'/fixtures/site_themes.php';
themeFixture($db);
$checks = 0;
$check = static function ($ok) use (&$checks) { ++$checks; if (!$ok) throw new RuntimeException('SEO check failed #'.$checks); };
$check(array_column($tenant->sites('1'), 'site_id') === ['10', '11']);
$scope = ['tenant_id' => '1', 'site_id' => '10'];
$detail = $tenant->detail($scope);
$check($detail['version'] === 0 && $detail['indexing_enabled'] === false && count($detail['locales']) === 20);
$check($db->table('wl_site_seo_configs')->count() === 0);
$input = $scope + ['expected_version' => 0, 'indexing_enabled' => true, 'default_locale' => 'zh-CN', 'translations' => ['zh-CN' => ['title' => 'Title', 'description' => 'Description']], 'google_verification_token' => '0', 'verification_domain' => 'site10.example'];
$logs = []; $audit = static function ($data) use (&$logs) { $logs[] = $data; };
$check($tenant->save($input, 7, 'tenant_admin', $audit)['version'] === 1);
$check($tenant->detail($scope)['effective_indexing_enabled'] === true);
$check($db->table('wl_site_seo_configs')->value('created_by_type') === 'tenant_admin');
$check(!str_contains(json_encode($logs), 'Description') && !isset($logs[0]['google_verification_token']));
$noop = array_replace($input, ['expected_version' => 1]);
$check(!$tenant->save($noop, 7, 'tenant_admin', $audit)['changed'] && count($logs) === 1);
foreach ([['site_id' => 20], ['site_id' => 12], ['expected_version' => 0], ['indexing_enabled' => 'true'], ['default_locale' => 'xx'], ['translations' => []], ['translations' => ['xx' => ['title' => 'x', 'description' => 'x']]], ['google_verification_token' => '<meta>'], ['verification_domain' => 'foreign.example'], ['translations' => ['zh-CN' => ['title' => str_repeat('x', 201), 'description' => 'x']]]] as $invalid) {
    try { $tenant->save(array_replace($noop, $invalid), 7, 'tenant_admin', $audit); throw new RuntimeException('Invalid SEO accepted'); }
    catch (ValidationException $e) { $check(true); }
}
try { $tenant->save(array_replace($noop, ['indexing_enabled' => false]), 7, 'tenant_admin', static fn () => throw new RuntimeException('audit failed')); }
catch (RuntimeException $e) { $check($e->getMessage() === 'audit failed'); }
$check($tenant->detail($scope)['version'] === 1 && $tenant->detail($scope)['indexing_enabled']);
$db->table('wl_site_domains')->where('site_id', 10)->update(['domain' => 'new.example']);
$check(!$tenant->detail($scope)['verification_domain_matches']);
try { $tenant->save($noop, 7, 'tenant_admin', $audit); throw new RuntimeException('Stale domain accepted'); }
catch (ValidationException $e) { $check(true); }
$tenant->save(array_replace($noop, ['google_verification_token' => null, 'verification_domain' => null]), 42, 'platform_admin', $audit);
$check($tenant->detail($scope)['version'] === 2);
$check($db->table('wl_site_seo_configs')->value('created_by_type') === 'tenant_admin' && $db->table('wl_site_seo_configs')->value('updated_by_type') === 'platform_admin');
$db->table('wl_sites')->where('site_id', 10)->update(['status' => 'disabled']);
$check($platform->detail($scope)['blocking_reason'] === '站点未启用');
try { $tenant->detail($scope); throw new RuntimeException('Disabled site accepted'); }
catch (ValidationException $e) { $check(true); }
$check($db->table('wl_config_snapshots')->value('payload') === 'unchanged');
$check($tenant->detail(['tenant_id' => 1, 'site_id' => 11])['version'] === 0);
$check($platform->listing(['keyword' => '_%'])['count'] === 3);
echo "Tenant SEO: {$checks} checks passed\n";
