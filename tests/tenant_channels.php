<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use SixMm\Shared\TenantChannels\{ChannelConfigStore, ChannelConfigException};

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
$db->statement('PRAGMA foreign_keys = ON');
$db->statement('CREATE TABLE admin (id integer PRIMARY KEY)');
$db->table('admin')->insert([['id' => 7], ['id' => 8]]);
$db->statement("CREATE TABLE wl_tenants (tenant_id integer PRIMARY KEY, tenant_name varchar, status text DEFAULT 'enabled')");
$db->statement("CREATE TABLE wl_channel_configs (tenant_id integer PRIMARY KEY, social_links text DEFAULT '{}', app_downloads text DEFAULT '{}', customer_service text DEFAULT '{}', updated_by integer REFERENCES admin(id), created_at varchar, updated_at varchar)");
$db->table('wl_tenants')->insert([['tenant_id' => 1, 'tenant_name' => 'A'], ['tenant_id' => 2, 'tenant_name' => 'B']]);
$store = new ChannelConfigStore($db);
require __DIR__.'/fixtures/site_themes.php';
$db->statement("CREATE TABLE wl_sites (site_id integer primary key, tenant_id integer, site_name text, status text DEFAULT 'enabled')");
$db->table('wl_sites')->insert([['site_id'=>10,'tenant_id'=>1,'site_name'=>'A'],['site_id'=>20,'tenant_id'=>2,'site_name'=>'B']]);
themeFixture($db);
$checks = 0;
$check = function (bool $condition) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException('Assertion failed #' . $checks); };
$check($store->detail('1')['expected_updated_at'] === null);
$check($db->table('wl_channel_configs')->count() === 0);
$saved = $store->save('1', null, ['social_links' => ['x' => ' https://x.com/a ']], 7);
$check($saved['changed']);
$check((array) $saved['detail']['social_links'] === ['x' => 'https://x.com/a']);
$token = $saved['detail']['expected_updated_at'];
$check(!$store->save('1', $token, ['social_links' => ['x' => 'https://x.com/a']], 7)['changed']);
$db->table('wl_channel_configs')->where('tenant_id', 1)->update(['app_downloads' => '{"apk":"https://example.com/app.apk"}', 'customer_service' => '{"secret":"keep"}', 'social_links' => '{"x":"https://x.com/a","future":"https://example.com/future"}']);
$saved = $store->save('1', $token, ['social_links' => []], 8);
$row = $db->table('wl_channel_configs')->where('tenant_id', 1)->first();
$check(json_decode($row->social_links, true) === ['future' => 'https://example.com/future']);
$check(json_decode($row->app_downloads, true) === ['apk' => 'https://example.com/app.apk']);
$check($row->customer_service === '{"secret":"keep"}');
$check(!array_key_exists('customer_service', $saved['detail']));
foreach (['http://example.com', 'javascript:alert(1)', 'https://a:b@example.com', "https://example.com/a\nb", 'https://example.com/a b', 'https://example.com\\evil', str_repeat('x', 2049)] as $url) {
    try { $store->save('2', null, ['social_links' => ['x' => $url]], 7); throw new RuntimeException('invalid URL accepted'); }
    catch (ChannelConfigException $e) { $check($e->field === 'social_links.x'); }
}
foreach ([['social_links' => ['bad' => 'https://example.com']], ['social_links' => ['x' => []]], ['wrong' => []]] as $sections) {
    try { $store->save('2', null, $sections, 7); throw new RuntimeException('invalid keys accepted'); }
    catch (ChannelConfigException $e) { $check(true); }
}
try { $store->save('1', $token, ['social_links' => ['x' => 'https://x.com/new']], 7); throw new RuntimeException('stale accepted'); }
catch (ChannelConfigException $e) { $check($e->field === 'expected_updated_at'); }
try { $store->save('2', null, ['social_links' => ['x' => 'https://x.com/b']], 7, function () { throw new RuntimeException('audit failure'); }); }
catch (RuntimeException $e) { $check($e->getMessage() === 'audit failure'); }
$check($db->table('wl_channel_configs')->where('tenant_id', 2)->count() === 0);
$db->table('wl_tenants')->insert(['tenant_id' => '9007199254740993', 'tenant_name' => 'Big tenant']);
$db->table('wl_sites')->insert(['site_id'=>30,'tenant_id'=>'9007199254740993','site_name'=>'Big']);
$white = $store->save('9007199254740993', null, ['social_links' => ['telegram' => 'https://t.me/big']], 999, static function () {}, 'white_label');
$check($white['detail']['tenant_id'] === '9007199254740993');
$check($db->table('wl_channel_configs')->where('tenant_id', '9007199254740993')->value('updated_by') === null);
try { $store->save('2', null, ['social_links' => []], 999, null, 'white_label'); throw new RuntimeException('unaudited white label accepted'); }
catch (InvalidArgumentException $e) { $check(true); }
echo "Tenant channels: {$checks} checks passed\n";
