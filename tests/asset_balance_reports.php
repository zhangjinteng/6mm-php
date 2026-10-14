<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Carbon\CarbonImmutable;
use SixMm\Shared\AssetReports\AssetBalanceDirectSnapshotPublisher;
use SixMm\Shared\AssetReports\AssetBalanceReportSchema;
use SixMm\Shared\AssetReports\AssetBalanceReportStore;

require dirname(__DIR__) . '/vendor/autoload.php';

function assetCheck(bool $okay, string $message): void { if (!$okay) throw new RuntimeException($message); }
function assetRow(array $rows, string $type, string $date): array {
    foreach ($rows as $row) if ($row['source_type'] === $type && $row['stat_date'] === $date) return $row;
    throw new RuntimeException("Missing {$type} {$date}");
}

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal(); $capsule->bootEloquent();
$db = $capsule->getConnection(); $schema = $db->getSchemaBuilder();
$schema->create('agents', static function (Blueprint $t): void {
    $t->bigInteger('id'); $t->bigInteger('pid'); $t->bigInteger('home_site_id');
    $t->string('agent_code'); $t->string('agent_name'); $t->string('user_name'); $t->timestamp('deleted_at')->nullable();
});
$schema->create('users', static function (Blueprint $t): void {
    $t->bigInteger('user_id'); $t->bigInteger('home_site_id')->nullable(); $t->bigInteger('agent_id'); $t->timestamp('deleted_at')->nullable();
});
$schema->create('wl_sites', static function (Blueprint $t): void { $t->bigInteger('site_id'); $t->bigInteger('tenant_id'); });
$schema->create('wl_tenants', static function (Blueprint $t): void { $t->bigInteger('tenant_id'); $t->string('tenant_name'); });
$schema->create('admin_agent_margin_daily_statistics', static function (Blueprint $t): void {
    $t->bigIncrements('id'); $t->bigInteger('agent_id'); $t->date('stat_date');
    $t->string('currency'); $t->string('account_type'); $t->bigInteger('funded_user_count');
    foreach (['opening_total', 'closing_total', 'closing_available', 'closing_occupied'] as $field) $t->decimal($field, 38, 16)->nullable();
    $t->boolean('is_finalized');
});
$db->table('agents')->insert([['id'=>1,'pid'=>0,'home_site_id'=>100,'agent_code'=>'M1','agent_name'=>'Merchant 1','user_name'=>'m1'],
    ['id'=>2,'pid'=>0,'home_site_id'=>100,'agent_code'=>'M2','agent_name'=>'Merchant 2','user_name'=>'m2']]);
$db->table('wl_sites')->insert(['site_id'=>100,'tenant_id'=>7]);
$db->table('wl_tenants')->insert(['tenant_id'=>7,'tenant_name'=>'White label 7']);
AssetBalanceReportSchema::create($db);
foreach (['2026-10-12', '2026-10-13'] as $index => $date) {
    $db->table('admin_agent_margin_daily_statistics')->insert([
        ['agent_id'=>1,'stat_date'=>$date,'currency'=>'USDT','account_type'=>'contract','funded_user_count'=>2,
            'opening_total'=>$index ? '120' : '100','closing_total'=>$index ? '140' : '120',
            'closing_available'=>$index ? '130' : '110','closing_occupied'=>'10','is_finalized'=>true],
        ['agent_id'=>2,'stat_date'=>$date,'currency'=>'USDT','account_type'=>'contract','funded_user_count'=>1,
            'opening_total'=>'50','closing_total'=>'50','closing_available'=>'50','closing_occupied'=>'0','is_finalized'=>true],
    ]);
    $db->table(AssetBalanceReportSchema::DIRECT_TABLE)->insert([
        'tenant_id'=>7,'stat_date'=>$date,'currency'=>'USDT','account_type'=>'contract','funded_user_count'=>1,
        'opening_total'=>$index ? '30' : '20','closing_total'=>$index ? '40' : '30',
        'closing_available'=>$index ? '40' : '30','closing_occupied'=>'0','is_finalized'=>true,
        'updated_at'=>'2026-10-14 00:00:00',
    ]);
}
$store = new AssetBalanceReportStore($db);
$params = ['start_date'=>'2026-10-12','end_date'=>'2026-10-13','source_type'=>'platform'];
$daily = $store->search($params);
assetCheck($daily['count'] === 2, 'Platform daily has two dates');
assetCheck(assetRow($daily['lists'], 'platform', '2026-10-12')['closing_total'] === '200.0000000000000000', 'Platform adds merchant and direct balances once');
assetCheck(assetRow($daily['lists'], 'platform', '2026-10-13')['period_net_change'] === '30.0000000000000000', 'Daily net change is closing minus opening');
$monthly = $store->search($params + ['period'=>'month']);
assetCheck($monthly['count'] === 1 && $monthly['lists'][0]['opening_total'] === '170.0000000000000000'
    && $monthly['lists'][0]['closing_total'] === '230.0000000000000000'
    && $monthly['lists'][0]['period_net_change'] === '60.0000000000000000', 'Month selects first opening and last closing');
$tenant = $store->search(array_merge($params, ['source_type'=>'tenant','source_keyword'=>'White label']));
assetCheck($tenant['count'] === 2, 'Tenant search works');
$merchant = $store->search(array_merge($params, ['source_type'=>'platform']), '1');
assetCheck($merchant['count'] === 2 && $merchant['lists'][0]['source_type'] === 'merchant'
    && $merchant['lists'][0]['source_id'] === '1', 'Authenticated merchant cannot read other scope');
$db->table(AssetBalanceReportSchema::DIRECT_TABLE)->where('stat_date', '2026-10-12')->delete();
$incomplete = $store->search($params);
assetCheck(assetRow($incomplete['lists'], 'platform', '2026-10-12')['stat_status'] === 2,
    'Pre-snapshot historical platform totals are marked incomplete');
$db->table('admin_agent_margin_daily_statistics')->where('agent_id', 2)->where('stat_date', '2026-10-13')->delete();
assetCheck(assetRow($store->search($params)['lists'], 'platform', '2026-10-13')['stat_status'] === 2,
    'A missing merchant USDT snapshot must not make a platform total look complete');
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:'], 'asset');
$asset = $capsule->getConnection('asset');
$assetSchema = $asset->getSchemaBuilder();
$schema->create('user_assets', static function (Blueprint $t): void {
    $t->bigInteger('user_id'); $t->string('asset_type'); $t->decimal('wallet_balance', 38, 16);
    $t->decimal('frozen_balance', 38, 16); $t->timestamp('deleted_at')->nullable();
});
$assetSchema->create('funding_accounts', static function (Blueprint $t): void {
    $t->bigInteger('account_id'); $t->bigInteger('user_id'); $t->bigInteger('agent_id'); $t->string('status');
});
$assetSchema->create('funding_assets', static function (Blueprint $t): void {
    $t->bigInteger('asset_id'); $t->string('asset_code'); $t->integer('internal_scale'); $t->string('status');
});
$assetSchema->create('funding_balances', static function (Blueprint $t): void {
    $t->bigInteger('account_id'); $t->bigInteger('asset_id'); $t->bigInteger('available_amount'); $t->bigInteger('held_amount');
});
$db->table('users')->insert(['user_id'=>100,'home_site_id'=>100,'agent_id'=>0]);
$db->table('user_assets')->insert(['user_id'=>100,'asset_type'=>'USDT','wallet_balance'=>'12','frozen_balance'=>'2']);
$asset->table('funding_accounts')->insert(['account_id'=>1,'user_id'=>100,'agent_id'=>0,'status'=>'ACTIVE']);
$asset->table('funding_assets')->insert(['asset_id'=>1,'asset_code'=>'USDT','internal_scale'=>2,'status'=>'ENABLED']);
$asset->table('funding_balances')->insert(['account_id'=>1,'asset_id'=>1,'available_amount'=>300,'held_amount'=>100]);
$excludedUsers = [
    ['user_id'=>101,'home_site_id'=>null,'agent_id'=>0,'deleted_at'=>null],
    ['user_id'=>102,'home_site_id'=>0,'agent_id'=>0,'deleted_at'=>null],
    ['user_id'=>103,'home_site_id'=>-1,'agent_id'=>0,'deleted_at'=>null],
    ['user_id'=>104,'home_site_id'=>100,'agent_id'=>1,'deleted_at'=>null],
    ['user_id'=>105,'home_site_id'=>100,'agent_id'=>0,'deleted_at'=>'2026-10-13 12:00:00'],
];
$db->table('users')->insert($excludedUsers);
foreach ($excludedUsers as $excludedUser) {
    $id = $excludedUser['user_id'];
    $db->table('user_assets')->insert(['user_id'=>$id,'asset_type'=>'USDT','wallet_balance'=>'1000','frozen_balance'=>'100']);
    $asset->table('funding_accounts')->insert(['account_id'=>$id,'user_id'=>$id,'agent_id'=>$excludedUser['agent_id'],'status'=>'ACTIVE']);
    $asset->table('funding_balances')->insert(['account_id'=>$id,'asset_id'=>1,'available_amount'=>100000,'held_amount'=>10000]);
}
CarbonImmutable::setTestNow('2026-10-14 00:00:00 UTC');
$publisher = new AssetBalanceDirectSnapshotPublisher($db, $asset);
assetCheck($publisher->capture(CarbonImmutable::now('UTC'), true) === 2, 'Boundary captures both account types');
$snapshot = $db->table(AssetBalanceReportSchema::DIRECT_TABLE)->where('stat_date','2026-10-14')
    ->where('account_type','contract')->first();
assetCheck(bccomp((string) $snapshot->opening_total, '12', 16) === 0 && bccomp((string) $snapshot->closing_available, '10', 16) === 0,
    'Direct contract opening is actual zero-boundary balance');
$funding = $db->table(AssetBalanceReportSchema::DIRECT_TABLE)->where('stat_date','2026-10-14')
    ->where('account_type','funding')->first();
assetCheck(bccomp((string) $funding->opening_total, '4', 16) === 0, 'Funding minor units use asset scale');
assetCheck((int) $snapshot->funded_user_count === 1 && (int) $funding->funded_user_count === 1,
    'Missing/non-positive sites, merchant users and deleted users are excluded from both account snapshots');
$closed = $db->table(AssetBalanceReportSchema::DIRECT_TABLE)->where('stat_date','2026-10-13')
    ->where('account_type','contract')->first();
assetCheck((bool) $closed->is_finalized && bccomp((string) $closed->opening_total, '30', 16) === 0
    && bccomp((string) $closed->closing_total, '12', 16) === 0,
    'UTC boundary closes prior day without replacing its opening snapshot');
$beforeInvalidOrigin = $db->table(AssetBalanceReportSchema::DIRECT_TABLE)->orderBy('stat_date')->orderBy('account_type')->get()->toJson();
$db->table('wl_sites')->insert(['site_id'=>200,'tenant_id'=>99]);
foreach ([999, 200] as $invalidSite) {
    $db->table('users')->insert(['user_id'=>106,'home_site_id'=>$invalidSite,'agent_id'=>0]);
    try {
        $publisher->capture(CarbonImmutable::now('UTC'));
        throw new RuntimeException('Unmapped positive site was accepted');
    } catch (InvalidArgumentException $e) {
        assetCheck($e->getMessage() === '直客缺少有效白标来源，拒绝发布不完整资产快照',
            'Positive sites still require a valid site-to-tenant mapping');
    }
    assetCheck($db->table(AssetBalanceReportSchema::DIRECT_TABLE)->orderBy('stat_date')->orderBy('account_type')->get()->toJson() === $beforeInvalidOrigin,
        'Invalid positive origins cannot partially modify snapshots');
    $db->table('users')->where('user_id', 106)->delete();
}
try {
    $publisher->capture(CarbonImmutable::parse('2026-10-13 12:00:00', 'UTC'));
    throw new RuntimeException('Historical balance backfill accepted');
} catch (InvalidArgumentException $e) {
    assetCheck(str_contains($e->getMessage(), '历史'), 'Historical balances cannot be fabricated');
}
CarbonImmutable::setTestNow();
echo "Shared asset balance report: scopes, direct users, month snapshots, missing data passed.\n";
