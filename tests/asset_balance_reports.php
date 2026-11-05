<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Carbon\CarbonImmutable;
use SixMm\Shared\AssetReports\AssetReportQueryService;
use SixMm\Shared\AssetReports\SourceAssetReportSchema;

require dirname(__DIR__) . '/vendor/autoload.php';

function assetCheck(bool $okay, string $message): void { if (!$okay) throw new RuntimeException($message); }

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal(); $capsule->bootEloquent();
$db = $capsule->getConnection(); $schema = $db->getSchemaBuilder();
$schema->create('agents', static function (Blueprint $t): void {
    $t->bigInteger('id'); $t->bigInteger('pid'); $t->bigInteger('home_site_id');
    $t->string('agent_code'); $t->string('agent_name'); $t->string('user_name'); $t->timestamp('deleted_at')->nullable();
});
$schema->create('users', static function (Blueprint $t): void {
    $t->bigInteger('user_id'); $t->bigInteger('home_site_id')->nullable(); $t->bigInteger('agent_id')->nullable(); $t->timestamp('deleted_at')->nullable();
});
$schema->create('wl_sites', static function (Blueprint $t): void { $t->bigInteger('site_id'); $t->bigInteger('tenant_id'); });
$schema->create('wl_tenants', static function (Blueprint $t): void { $t->bigInteger('tenant_id'); $t->string('tenant_name'); });
SourceAssetReportSchema::create($db);
CarbonImmutable::setTestNow('2026-11-01 12:00:00 UTC');
$db->table('agents')->insert([
    ['id'=>7, 'pid'=>0, 'home_site_id'=>100, 'agent_code'=>'M', 'agent_name'=>'Merchant', 'user_name'=>'m'],
    ['id'=>8, 'pid'=>7, 'home_site_id'=>100, 'agent_code'=>'S', 'agent_name'=>'Child', 'user_name'=>'s'],
]);
$db->table('wl_sites')->insert(['site_id'=>100, 'tenant_id'=>7]);
$db->table('wl_tenants')->insert(['tenant_id'=>7, 'tenant_name'=>'Tenant']);
foreach (['merchant'=>100, 'tenant'=>20] as $type => $total) {
    $buckets = array_fill(0, 5, ['count'=>0, 'amount'=>'0']);
    $buckets[0] = ['count'=>1, 'amount'=>(string)$total];
    $snapshot = ['version'=>SourceAssetReportSchema::VERSION, 'user_types'=>'all', 'funded_user_count'=>1,
        'total'=>(string)$total, 'available'=>(string)$total, 'occupied'=>'0', 'negative_total'=>'0',
        'positive_total'=>(string)$total, 'buckets'=>$buckets, 'finalized'=>false, 'captured_at'=>'2026-11-01T12:00:00Z'];
    $db->table(SourceAssetReportSchema::TABLE)->insert(['stat_date'=>'2026-11-01', 'source_type'=>$type,
        'source_name'=>$type, 'source_id'=>'7', 'account_type'=>'contract', 'currency'=>'USDT',
        'asset_report_closing'=>json_encode($snapshot, JSON_THROW_ON_ERROR)]);
}
$report = new AssetReportQueryService($db);
$range = ['start_date'=>'2026-11-01', 'end_date'=>'2026-11-01'];
$spoof = ['source_type'=>'platform', 'source_id'=>'0', 'source_keyword'=>'another merchant'];
foreach (['day','month'] as $period) {
    $params = $range + ['period'=>$period];
    foreach ([['merchant','8',null,100], ['tenant',null,'7',20]] as [$type,$account,$tenant,$total]) {
        $actual = $report->searchBalance($params + $spoof, $account, $tenant);
        $expected = $report->searchBalance($params + ['source_type'=>$type, 'source_id'=>'7']);
        assetCheck($expected === $actual && $actual['count'] === 1, 'Scoped results must equal Platform results');
        $row = $actual['lists'][0];
        assetCheck($row['closing_total'] === number_format($total,16,'.',''), 'Isolate sources with identical IDs');
        assetCheck($row['opening_total'] === null && $row['period_net_change'] === null, 'Never synthesize a missing opening');
    }
}
assetCheck($report->searchBalance($range, null, '99')['count'] === 0, 'Unknown source must not read other rows');
foreach ([['7','7'], [null,'0'], ['0',null]] as [$account,$tenant]) {
    try {
        $report->searchBalance($range, $account, $tenant);
        throw new RuntimeException('Invalid scope accepted');
    } catch (InvalidArgumentException $e) {}
}
echo "Asset balance scope and snapshots OK\n";
