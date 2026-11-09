<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

require __DIR__.'/fixtures/site_publication.php';

use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use SixMm\Shared\TenantFeatures\{FeatureConfigStore, FeatureConfigException};

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
$db->statement("CREATE TABLE wl_tenants (tenant_id integer PRIMARY KEY, tenant_name text, status text DEFAULT 'enabled')");
$db->statement('CREATE TABLE wl_sites (site_id integer PRIMARY KEY, tenant_id integer, site_name text, status text, entitlement_version integer DEFAULT 3, updated_at text)');
$db->statement("CREATE TABLE wl_site_modules (site_id integer, module_code text, module_type text DEFAULT 'business', enabled boolean, created_at text, updated_at text, PRIMARY KEY(site_id,module_code))");
$db->statement('CREATE TABLE wl_config_snapshots (id integer PRIMARY KEY, payload text)');
$db->table('wl_tenants')->insert([['tenant_id' => 1, 'tenant_name' => 'Alpha_%'], ['tenant_id' => 2, 'tenant_name' => 'Beta']]);
$db->table('wl_sites')->insert([
    ['site_id' => 10, 'tenant_id' => 1, 'site_name' => 'Main', 'status' => 'enabled'],
    ['site_id' => 11, 'tenant_id' => 1, 'site_name' => 'Second', 'status' => 'enabled'],
    ['site_id' => 12, 'tenant_id' => 1, 'site_name' => 'Disabled', 'status' => 'disabled'],
    ['site_id' => 20, 'tenant_id' => 2, 'site_name' => 'Other', 'status' => 'enabled'],
]);
$db->table('wl_site_modules')->insert([
    ['site_id' => 10, 'module_code' => 'contract', 'module_type' => 'business', 'enabled' => true],
    ['site_id' => 10, 'module_code' => 'future_feature', 'module_type' => 'feature', 'enabled' => true],
]);
$db->table('wl_config_snapshots')->insert(['id' => 1, 'payload' => '{"preserve":true}']);
publicationFixture($db, []);
foreach ([10, 11] as $id) $db->table('wl_site_domains')->insert(['site_id' => $id, 'domain' => 'site'.$id.'.example', 'is_primary' => true]);
$store = new FeatureConfigStore($db, true, true);
$platform = new FeatureConfigStore($db);
$checks = 0;
$check = static function (bool $value) use (&$checks): void { ++$checks; if (!$value) throw new RuntimeException('Feature check failed #' . $checks); };
$detail = $store->detail(['tenant_id' => '1']);
$check($detail['features'] === ['trade_ranking' => false] && $detail['expected_updated_at'] === null);
$check(array_column($detail['sites'], 'site_id') === ['10', '11']);
$check($db->table('wl_site_modules')->count() === 2);
$check($store->detail(['tenant_id' => 1, 'site_id' => 11])['site_id'] === '11');
$check($platform->detail(['tenant_id' => 1, 'site_id' => 11])['site_id'] === '10');
$audits = [];
$audit = static function (array $change) use (&$audits): void { $audits[] = $change; };
$input = ['tenant_id' => '1', 'site_id' => '10', 'expected_updated_at' => null, 'features' => ['trade_ranking' => true]];
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03T10:00:00Z'));
$saved = $store->save($input, 7, 'test:7', $audit);
$token = $saved['detail']['expected_updated_at'];
$check($saved['changed'] && $saved['detail']['features'] === ['trade_ranking' => true]);
$check($audits[0]['site_id'] === '10' && $audits[0]['updated_at'] === $token);
$check(!$store->save(array_replace($input, ['expected_updated_at' => $token]), 7, 'test:7', $audit)['changed']);
$check(count($audits) === 1);
try { $store->save($input, 7, 'test:7', $audit); throw new RuntimeException('Stale accepted'); }
catch (FeatureConfigException $e) { $check(true); }
$next = $store->save(array_replace($input, ['expected_updated_at' => $token, 'features' => ['trade_ranking' => false]]), 7, 'test:7', $audit);
$check(CarbonImmutable::parse($next['detail']['expected_updated_at'])->greaterThan(CarbonImmutable::parse($token)));
CarbonImmutable::setTestNow();
foreach ([['site_id' => 20], ['site_id' => 12], ['features' => ['contract' => false]], ['features' => ['trade_ranking' => 'false']], ['features' => []], ['features' => ['trade_ranking' => false, 'feedback' => false]]] as $invalid) {
    try { $store->save(array_replace($input, $invalid), 7, 'test:7', $audit); throw new RuntimeException('Invalid save accepted'); }
    catch (FeatureConfigException $e) { $check(true); }
}
try { $platform->save(array_replace($input, ['site_id' => 11]), 7, 'test:7', $audit); throw new RuntimeException('Non-default Platform site accepted'); }
catch (FeatureConfigException $e) { $check(true); }
try { $store->save(array_replace($input, ['site_id' => 11]), 7, 'test:7', static function (): void { throw new RuntimeException('Audit failed'); }); }
catch (RuntimeException $e) { $check($e->getMessage() === 'Audit failed'); }
$check(!$db->table('wl_site_modules')->where('site_id', 11)->exists());
$check((bool) $db->table('wl_site_modules')->where('module_code', 'contract')->value('enabled'));
$check((bool) $db->table('wl_site_modules')->where('module_code', 'future_feature')->value('enabled'));
$check($db->table('wl_sites')->where('site_id', 10)->value('entitlement_version') === 4);
$check($db->table('wl_config_snapshots')->value('payload') === '{"preserve":true}');
$check($platform->listing(['keyword' => '_%'])['count'] === 1);
$content = new FeatureConfigStore($db, true, true, null, true);
$all = ['trade_ranking' => false, 'news' => false, 'academy' => false, 'help_center' => false, 'feedback' => false, 'announcement' => false];
$initial = $content->detail(['tenant_id' => 1, 'site_id' => 11]);
$check($initial['features'] === $all);
$check(strlen($initial['expected_revision']) === 64);
$check(!$db->table('wl_site_modules')->where('site_id', 11)->exists());
$contentInput = ['tenant_id' => 1, 'site_id' => 11, 'expected_updated_at' => null,
    'expected_revision' => $initial['expected_revision'], 'features' => array_replace($all, ['news' => true, 'feedback' => true])];
$result = $content->save($contentInput, 7, 'test:7', $audit);
$check($result['detail']['features']['news'] && $result['detail']['features']['feedback']);
$check($db->table('wl_site_modules')->where('site_id', 11)->count() === 2);
$check($result['detail']['expected_revision'] !== $initial['expected_revision']);
$check($content->detail(['tenant_id' => 2, 'site_id' => 20])['features'] === $all);
try { $content->save($contentInput, 7, 'test:7', $audit); throw new RuntimeException('Stale content accepted'); }
catch (FeatureConfigException $e) { $check(true); }
$fresh = array_replace($contentInput, ['expected_revision' => $result['detail']['expected_revision'], 'expected_updated_at' => $result['detail']['expected_updated_at']]);
$check(!$content->save($fresh, 7, 'test:7', $audit)['changed']);
foreach ([['expected_revision' => null], ['expected_revision' => 'bad'], ['site_id' => 20], ['site_id' => 12],
    ['features' => ['contract' => false]], ['features' => ['news' => 'false']], ['features' => []]] as $invalid) {
    try { $content->save(array_replace($fresh, $invalid), 7, 'test:7', $audit); throw new RuntimeException('Invalid content accepted'); }
    catch (FeatureConfigException $e) { $check(true); }
}
// Audit rollback is atomic across multiple flags.
try { $content->save(array_replace($fresh, ['features' => ['academy' => true, 'announcement' => true]]), 7, 'test:7', static function (): void { throw new RuntimeException('Audit failed'); }); }
catch (RuntimeException $e) { $check($e->getMessage() === 'Audit failed'); }
$check(!$content->detail(['tenant_id' => 1, 'site_id' => 11])['features']['academy']);
$check($db->table('wl_site_modules')->where('site_id', 11)->count() === 2);
// Platform remains ranking-only and cannot overwrite content rows.
$owned = $content->detail(['tenant_id' => 1, 'site_id' => 10]);
$content->save(['tenant_id' => 1, 'site_id' => 10, 'expected_updated_at' => $owned['expected_updated_at'],
    'expected_revision' => $owned['expected_revision'], 'features' => ['news' => true]], 7, 'test:7', $audit);
$owned = $content->detail(['tenant_id' => 1, 'site_id' => 10]);
$platformDetail = $platform->detail(['tenant_id' => 1]);
$check(array_keys($platformDetail['features']) === ['trade_ranking']);
$check(!array_key_exists('expected_revision', $platformDetail));
$platform->save(['tenant_id' => 1, 'site_id' => 10, 'expected_updated_at' => $platformDetail['expected_updated_at'], 'features' => ['trade_ranking' => true]], 7, 'test:7', $audit);
$check($content->detail(['tenant_id' => 1, 'site_id' => 10])['features']['news']);
try { $content->save(['tenant_id' => 1, 'site_id' => 10, 'expected_updated_at' => $owned['expected_updated_at'],
    'expected_revision' => $owned['expected_revision'], 'features' => $all], 7, 'test:7', $audit); throw new RuntimeException('Platform update lost'); }
catch (FeatureConfigException $e) { $check(true); }
$legacy = $content->detail(['tenant_id' => 1, 'site_id' => 10]);
$content->save(['tenant_id' => 1, 'site_id' => 10, 'expected_updated_at' => $legacy['expected_updated_at'], 'features' => ['trade_ranking' => false]], 7, 'test:7', $audit);
$check($content->detail(['tenant_id' => 1, 'site_id' => 10])['features']['news']);
// A second row can change while the aggregate maximum timestamp stays unchanged.
$snapshot = $content->detail(['tenant_id' => 1, 'site_id' => 11]);
$db->table('wl_site_modules')->where('site_id', 11)->where('module_code', 'news')->update(['enabled' => false]);
$check($content->detail(['tenant_id' => 1, 'site_id' => 11])['expected_updated_at'] === $snapshot['expected_updated_at']);
try { $content->save(['tenant_id' => 1, 'site_id' => 11, 'expected_updated_at' => $snapshot['expected_updated_at'],
    'expected_revision' => $snapshot['expected_revision'], 'features' => ['news' => true]], 7, 'test:7', $audit); throw new RuntimeException('Equal timestamp conflict missed'); }
catch (FeatureConfigException $e) { $check(true); }
// A code already used as a business entitlement must never be overwritten.
$db->table('wl_site_modules')->insert(['site_id' => 20, 'module_code' => 'news', 'module_type' => 'business', 'enabled' => true]);
try { $content->detail(['tenant_id' => 2, 'site_id' => 20]); throw new RuntimeException('Classification collision accepted'); }
catch (FeatureConfigException $e) { $check(true); }
$check((bool) $db->table('wl_site_modules')->where('site_id', 20)->where('module_code', 'news')->value('enabled'));
echo "Tenant features: {$checks} checks passed\n";
