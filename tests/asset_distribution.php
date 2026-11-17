<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use SixMm\Shared\AssetReports\SourceAssetReportSchema;
use SixMm\Shared\AssetDistribution\AssetDistributionQueryService;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException($message); };
$capsule = new Capsule(); $capsule->addConnection(['driver'=>'sqlite', 'database'=>':memory:']);
$db = $capsule->getConnection();
$db->statement('CREATE TABLE agents (id integer, home_site_id integer, agent_name text)');
$db->statement('CREATE TABLE wl_sites (site_id integer, tenant_id integer)');
$db->statement('CREATE TABLE wl_tenants (tenant_id integer, tenant_name text)');
$db->table('agents')->insert(['id'=>101, 'home_site_id'=>10, 'agent_name'=>'Merchant']);
$db->table('wl_tenants')->insert([['tenant_id'=>101, 'tenant_name'=>'A'], ['tenant_id'=>202, 'tenant_name'=>'B']]);
$db->table('wl_sites')->insert([['site_id'=>10, 'tenant_id'=>101], ['site_id'=>20, 'tenant_id'=>202]]);
SourceAssetReportSchema::create($db);
CarbonImmutable::setTestNow('2026-11-16 12:00:00 UTC');
$fact = static function (string $type, string $id, string $account, string $total) use ($db): void {
    $positive = bccomp($total, '0', 16) > 0;
    $buckets = array_fill(0, 5, ['count'=>0, 'amount'=>'0']);
    if ($positive) $buckets[4] = ['count'=>1, 'amount'=>$total];
    $snapshot = ['version'=>SourceAssetReportSchema::VERSION, 'user_types'=>'all', 'captured_at'=>'2026-11-16T12:00:00Z',
        'finalized'=>false, 'funded_user_count'=>$positive ? 1 : 0, 'total'=>$total, 'available'=>$total, 'occupied'=>'0',
        'positive_total'=>$positive ? $total : '0', 'negative_total'=>$positive ? '0' : $total, 'buckets'=>$buckets];
    $db->table(SourceAssetReportSchema::TABLE)->updateOrInsert(['stat_date'=>'2026-11-16', 'source_type'=>$type,
        'source_id'=>$id, 'account_type'=>$account, 'currency'=>'USDT'], ['source_name'=>$type . $id, 'asset_report_closing'=>json_encode($snapshot)]);
};
$fact('tenant', '101', 'funding', '3'); $fact('tenant', '101', 'contract', '1');
$fact('tenant', '202', 'funding', '700'); $fact('merchant', '101', 'funding', '900');
$fact('platform', '0', 'funding', '1000');
$service = new AssetDistributionQueryService($db);
$scoped = $service->searchTenant(['source_type'=>'merchant', 'source_id'=>'202', 'source_keyword'=>'B'], '101');
$check($scoped['count'] === 2 && array_unique(array_column($scoped['lists'], 'source_id')) === ['101'], 'Tenant identity overrides requested sources');
$check(array_unique(array_column($scoped['lists'], 'source_type')) === ['tenant'], 'Merchant/tenant numeric collisions cannot leak');
$funding = $service->searchTenant(['account_type'=>'funding', 'page_size'=>1], '101')['lists'][0];
$check($funding['asset_share'] === '75.0000000000000000', 'Filter/page must not shrink the source denominator');
$check($funding['total_balance'] === '3.0000000000000000' && $funding['current_price'] === '1', 'Preserve exact decimal balances and USDT price');
$global = $service->search(['source_type'=>'tenant', 'source_keyword'=>'101']);
$check($global === $service->searchTenant([], '101'), 'Scoped data must match the Platform source slice');
$db->table(SourceAssetReportSchema::TABLE)->where('source_type','tenant')->where('source_id','101')->where('account_type','contract')->delete();
$check($service->searchTenant(['account_type'=>'funding'], '101')['lists'][0]['asset_share'] === null, 'Missing opposite account is not 100%');
$missing = $service->searchTenant(['account_type'=>'contract'], '101')['lists'][0];
$check($missing['snapshot_status'] === 'incomplete' && $missing['total_balance'] === null, 'Missing snapshots are not zero');
$fact('tenant', '101', 'funding', '0'); $fact('tenant', '101', 'contract', '0');
$check($service->searchTenant([], '101')['lists'][0]['asset_share'] === '0.0000000000000000', 'Confirmed zero snapshots retain zero allocation');
$fact('tenant', '101', 'funding', '9007199254740993.1234567890123456');
$check($service->searchTenant(['account_type'=>'funding'], '101')['lists'][0]['total_balance'] === '9007199254740993.1234567890123456', 'Never convert monetary strings to floating point');
$check($service->searchTenant([], '999')['count'] === 0, 'Unknown tenant has no global fallback');
foreach (['', '0', '-1', '101 OR 1=1'] as $invalid) {
    try { $service->searchTenant([], $invalid); throw new RuntimeException('Invalid identity accepted'); }
    catch (InvalidArgumentException $e) { ++$checks; }
}
$db->enableQueryLog(); $service->searchTenant([], '101');
$check(count(array_filter($db->getQueryLog(), static fn ($q) => !preg_match('/^(select|pragma)\b/i', $q['query']))) === 0, 'Reads never initialize or publish snapshots');
CarbonImmutable::setTestNow();
echo "asset distribution: {$checks} checks passed\n";
