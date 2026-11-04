<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as Validator;
use SixMm\Shared\TenantCustomerService\{BusinessConfigStore, CustomerService, CustomerServiceContext};

$capsule = new Capsule(); $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']); $db = $capsule->getConnection();
$db->statement('CREATE TABLE wl_tenants (tenant_id integer PRIMARY KEY, tenant_name text)');
$db->statement('CREATE TABLE wl_sites (site_id integer PRIMARY KEY, tenant_id integer, site_name text, entitlement_version integer, updated_at text)');
$db->statement('CREATE TABLE wl_site_modules (site_id integer, module_code text, module_type text, enabled boolean, created_at text, updated_at text, PRIMARY KEY(site_id,module_code))');
$db->statement("CREATE TABLE wl_channel_configs (tenant_id integer PRIMARY KEY, customer_service text DEFAULT '{}', updated_by integer, created_at text, updated_at text)");
$db->statement('CREATE TABLE wl_config_snapshots (snapshot_version integer PRIMARY KEY, schema_version integer, payload text, payload_sha256 text, previous_snapshot_version integer, rollback_of_snapshot_version integer, published_by text, publish_reason text, published_at text)');
$sites = [];
foreach ([10, 11, 20] as $id) {
    $tenant = $id === 20 ? 2 : 1;
    $db->table('wl_sites')->insert(['site_id' => $id, 'tenant_id' => $tenant, 'site_name' => 'Site'.$id, 'entitlement_version' => 3]);
    $db->table('wl_site_modules')->insert(['site_id' => $id, 'module_code' => 'contract', 'module_type' => 'business', 'enabled' => true]);
    $sites[] = ['siteId' => $id, 'tenantId' => $tenant, 'entitlementVersion' => 3, 'entitlements' => ['contract'], 'domains' => [], 'status' => 'enabled', 'extension' => (object) []];
}
$db->table('wl_tenants')->insert([['tenant_id' => 1, 'tenant_name' => 'alpha'], ['tenant_id' => 2, 'tenant_name' => 'beta']]);
$json = json_encode(['schemaVersion' => 4, 'snapshotVersion' => 8, 'sites' => $sites, 'extension' => (object) []]);
$db->table('wl_config_snapshots')->insert(['snapshot_version' => 8, 'schema_version' => 4, 'payload' => $json, 'payload_sha256' => hash('sha256', $json)]);
$checks = 0; $check = static function (bool $ok) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException('Customer check #'.$checks.' failed'); };
$http = new Http(); $calls = 0;
$http->fake(function ($request) use ($db, &$calls) {
    if ($db->transactionLevel() !== 0) throw new RuntimeException('HTTP under transaction');
    ++$calls;
    return (new Http())->response(['code' => 0, 'data' => ['created' => true, 'tenantId' => 123, 'appKey' => 'private-binding']], 200);
});
$audits = [];
$context = new CustomerServiceContext($db, $http, new Validator(new Translator(new ArrayLoader(), 'en')),
    new Encrypter(str_repeat('C', 32), 'AES-256-CBC'), 'private-api', 'fingerprint', 7, 'white_label',
    static function (array $change) use (&$audits): void { $audits[] = $change; }, static fn () => true, static fn () => true);
$store = new BusinessConfigStore($context);
$check(!$store->detail(['tenant_id' => '1'])['modules']['customer_service']);
$result = $store->toggleCustomer(['tenant_id' => '1', 'site_id' => '10', 'expected_entitlement_version' => '3', 'enabled' => true], 7);
$check($result['detail']['modules']['customer_service'] && $calls === 1);
$check($db->table('wl_site_modules')->where('module_code', 'customer_service')->count() === 2);
$check(!$db->table('wl_site_modules')->where('site_id', 20)->where('module_code', 'customer_service')->exists());
$check($db->table('wl_channel_configs')->value('updated_by') === null);
$check($db->table('wl_config_snapshots')->orderByDesc('snapshot_version')->value('published_by') === 'white-label-admin:7');
$check((new CustomerService($context))->bound('1'));
$check(count($audits) === 3 && !str_contains(json_encode($audits), 'private-'));
$public = CustomerService::publicConfig(json_decode($db->table('wl_channel_configs')->value('customer_service'), true), '1', true, true);
$check(!str_contains(json_encode($public), 'private-') && !array_key_exists('app_key_encrypted', $public));
try { $store->toggleCustomer(['tenant_id' => '1', 'site_id' => '10', 'expected_entitlement_version' => '3', 'enabled' => true], 7); throw new RuntimeException('Stale write accepted'); }
catch (\Illuminate\Validation\ValidationException $e) { $check($calls === 1); }
try { $context->assertActor(8); throw new RuntimeException('Actor mismatch accepted'); }
catch (\InvalidArgumentException $e) { $check(true); }
echo "Tenant customer service: {$checks} checks passed\n";
