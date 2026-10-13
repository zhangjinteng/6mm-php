<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): void {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\FuturesReports\MerchantReportScope;

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$db = $capsule->getConnection();
$db->getSchemaBuilder()->create('agents', static function (Blueprint $t): void {
    $t->bigInteger('id'); $t->bigInteger('pid'); $t->bigInteger('home_site_id')->nullable();
    $t->timestamp('deleted_at')->nullable();
});
$db->table('agents')->insert([
    ['id' => 1, 'pid' => 0, 'home_site_id' => 100, 'deleted_at' => null],
    ['id' => 2, 'pid' => 0, 'home_site_id' => 200, 'deleted_at' => null],
    ['id' => 11, 'pid' => 1, 'home_site_id' => 200, 'deleted_at' => null],
    ['id' => 12, 'pid' => 999, 'home_site_id' => 100, 'deleted_at' => null],
    ['id' => 13, 'pid' => 11, 'home_site_id' => 100, 'deleted_at' => null],
    ['id' => 14, 'pid' => 0, 'home_site_id' => 100, 'deleted_at' => '2026-10-01 00:00:00'],
    ['id' => 15, 'pid' => 14, 'home_site_id' => 100, 'deleted_at' => null],
]);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};

foreach (['Trade', 'Pnl', 'UserNetPnl', 'Fee', 'FundingFee', 'Risk', 'Position'] as $report) {
    $schema = 'SixMm\\Shared\\FuturesReports\\' . $report . 'ReportSchema';
    $storeClass = 'SixMm\\Shared\\FuturesReports\\' . $report . 'ReportStore';
    $schema::create($db);
    $columns = $db->getSchemaBuilder()->getColumnListing($schema::TABLE);
    foreach (['2026-10-09', '2026-10-10'] as $date) {
        foreach ([['merchant', '1'], ['merchant', '2'], ['tenant', '1'], ['platform', '0']] as [$type, $id]) {
            $row = array_fill_keys($columns, '0');
            foreach ([
                'stat_date' => $date, 'source_type' => $type, 'source_id' => $id,
                'source_code' => $type . $id, 'source_name' => $type . $id, 'symbol' => '',
                'status' => 1, 'updated_at' => $date . ' 12:00:00', 'snapshot_at' => $date . ' 12:00:00',
                'identities' => json_encode(['users' => [], 'orders' => [], 'executions' => []]), 'user_ids' => '[]',
                'user_metrics' => '[]', 'settle_asset' => 'USDT',
            ] as $field => $value) if (array_key_exists($field, $row)) $row[$field] = $value;
            $db->table($schema::TABLE)->insert($row);
        }
    }
    $store = new $storeClass($db);
    foreach ([null, 0, 100, 999] as $site) {
        $db->table('agents')->where('id', 1)->update(['home_site_id' => $site]);
        foreach (['day', 'month'] as $period) {
            $params = ['start_date' => '2026-10-09', 'end_date' => '2026-10-10', 'period' => $period,
                'source_type' => 'tenant', 'source_id' => '2', 'source_keyword' => 'Other', 'home_site_id' => '200'];
            foreach (['1', '11'] as $accountId) {
                $rows = $store->search($params, $accountId, '200');
                $check($rows['count'] === ($period === 'day' ? 2 : 1), "$report $period must retain published own-merchant rows without a site gate");
                foreach ($rows['lists'] as $row) {
                    $check($row['source_type'] === 'merchant' && $row['source_id'] === '1', "$report must not expose another merchant, white-label or platform");
                }
            }
            $tenant = $store->search(['start_date' => '2026-10-09', 'end_date' => '2026-10-10',
                'period' => $period, 'source_type' => 'tenant', 'source_id' => '1']);
            $check($tenant['count'] === ($period === 'day' ? 2 : 1), "$report must retain the existing published white-label query");
            foreach ($tenant['lists'] as $row) $check($row['source_type'] === 'tenant' && $row['source_id'] === '1', 'Tenant ID must not be confused with the same numeric merchant ID');
        }
    }
    foreach (['0', '404', '12', '13', '14', '15'] as $invalidId) {
        try { $store->search([], $invalidId); throw new RuntimeException("$report accepted an invalid account"); }
        catch (InvalidArgumentException $e) { $check(true, 'Invalid identity rejected'); }
    }
}
$check(MerchantReportScope::forAccount($db, '11', '999')->merchantId === '1', 'Subaccount uses authenticated parent regardless of site');
echo "report identity scope ok ($checks checks)\n";
