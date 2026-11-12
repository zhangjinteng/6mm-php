<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/fixtures/scoped_handling_fees.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use SixMm\Shared\HandlingFees\{HandlingFeeConfigManager, HandlingFeeOperationSchema, ScopedHandlingFeeConfigService};

$capsule = new Capsule(); $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection(); scopedFeeFixture($db); HandlingFeeOperationSchema::create($db);
$scoped = new ScopedHandlingFeeConfigService($db); $scoped->ensureScope(10); $scoped->ensureScope(10, 20); $scoped->ensureScope(11, 21);
$remote = []; $calls = []; $mode = 'ok'; $receipts = [];
$rpc = static function (string $method, array $payload) use (&$remote, &$calls, &$mode, &$receipts): array {
    $calls[] = [$method, $payload];
    $item = $payload['items'][0] ?? $payload;
    $key = ($item['home_site_id'] ?: '0') . ':' . $item['agent_id'] . ':' . $item['level'];
    if ($method === 'GetFeeLevelConfig') return ['configs' => isset($remote[$key]) ? [$remote[$key]] : [], 'total_count' => isset($remote[$key]) ? 1 : 0];
    if ($mode === 'reject') return $method === 'DeleteFeeLevelConfig' ? ['reject_code' => 6003] : ['total_count' => 1, 'failed_count' => 1, 'success_count' => 0,
        'results' => [$item + ['reject_code' => 6003]]];
    if (!isset($receipts[$item['command_id']])) {
        if ($method === 'DeleteFeeLevelConfig') { unset($remote[$key]); $receipts[$item['command_id']] = []; }
        else { $remote[$key] = $item; $receipts[$item['command_id']] = ['total_count' => 1, 'success_count' => 1, 'failed_count' => 0,
            'results' => [['home_site_id' => $item['home_site_id'], 'agent_id' => $item['agent_id'], 'level' => $item['level'], 'reject_code' => 0]]]; }
    }
    if ($mode === 'unknown') throw new RuntimeException('reply lost after commit');
    return $receipts[$item['command_id']];
};
$manager = new HandlingFeeConfigManager($db, $rpc);
$checks = 0;
$check = static function (bool $ok, string $why) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException($why); };
$input = ['request_id' => 'request-create-0001', 'volume_30d' => '3000', 'maker_fee_rate' => '0.0001', 'taker_fee_rate' => '0.0003'];
$before = $scoped->query(0)->get()->toJson();
$created = $manager->mutate(10, 20, 'create', $input, 'merchant:20');
$check($created['level'] === 2 && bccomp($created['maker_fee_rate'], '0.0001', 18) === 0, 'Create preserves raw rates and appends a stable scoped level');
$check($scoped->query(0)->get()->toJson() === $before && $scoped->query(11, 21)->count() === 2, 'Other owners are unchanged');
$check($manager->mutate(10, 20, 'create', $input) === $created, 'Confirmed request is idempotent');
try { $manager->mutate(10, 20, 'create', array_replace($input, ['maker_fee_rate' => '0'])); throw new RuntimeException('Changed request accepted'); }
catch (DomainException $e) { $check(str_contains($e->getMessage(), '编号'), 'Same request cannot change its intent'); }
try { $manager->detail(11, 21, $created['id']); throw new RuntimeException('Foreign row exposed'); }
catch (DomainException $e) { $check(true, 'Detail enforces both scope keys'); }
$update = array_replace($input, ['id' => $created['id'], 'request_id' => 'request-update-0001', 'maker_fee_rate' => '0.00009']);
$mode = 'unknown';
try { $manager->mutate(10, 20, 'update', $update); throw new RuntimeException('Lost reply accepted'); }
catch (DomainException $e) { $check(str_contains($e->getMessage(), '待确认'), 'Unknown response is reported as pending'); }
$check($manager->detail(10, 20, $created['id'])['maker_fee_rate'] === $created['maker_fee_rate'], 'Unknown write does not claim a confirmed local value');
$pending = $manager->listing(10, 20)['pending_operation'];
$writeBefore = end($calls)[1];
$mode = 'ok'; $updated = $manager->retry(10, 20, $pending['id']);
$check(end($calls)[1] === $writeBefore && bccomp($updated['maker_fee_rate'], '0.00009', 18) === 0, 'Retry preserves exact command, version and payload');
$mode = 'reject';
try { $manager->mutate(10, 20, 'update', array_replace($update, ['request_id' => 'request-reject-0001', 'maker_fee_rate' => '0.00008'])); throw new RuntimeException('Rejected update applied'); }
catch (DomainException $e) { $check($manager->listing(10, 20)['pending_operation'] === null, 'Definitive rejection is recorded and does not lock scope'); }
$mode = 'ok';
$manager->mutate(10, 20, 'delete', ['id' => $created['id'], 'request_id' => 'request-delete-0001']);
$check(!$scoped->query(10, 20)->where('level', 2)->exists() && $scoped->query(10, 20)->count() === 2, 'Delete does not renumber remaining levels');
$tierOne = $manager->listing(10, 20)['lists'][1];
$remote['10:20:1'] = ['level' => 1, 'home_site_id' => '10', 'agent_id' => '20', 'version' => '2'];
$manager->mutate(10, 20, 'delete', ['id' => $tierOne['id'], 'request_id' => 'request-delete-0002']);
$scoped->ensureScope(10, 20);
$check(!$scoped->query(10, 20)->where('level', 1)->exists(), 'Account initialization preserves intentional deletion tombstones');
$effective = $manager->effectiveLevels(10, 20);
$check(count($effective) === 2 && $effective[1]['agent_id'] === '0', 'Deleted local tier inherits its parent at the same level');
$check($manager->vipLevel(10, 20, '2000') === 1 && $manager->vipLevel(10, 20, '1999') === 0, 'VIP calculation uses each VIP tier own lower boundary');
$db->table('handling_fee_level_config')->where('home_site_id',10)->where('agent_id',20)->where('level',0)->update(['volume_30d'=>'2000']);
$zero=$scoped->query(10,20)->where('level',0)->first();
$manager->mutate(10,20,'update',['id'=>$zero->id,'request_id'=>'request-equal-boundaries','volume_30d'=>'2000','maker_fee_rate'=>'0.0002','taker_fee_rate'=>'0.0005']);
$check($manager->listing(10,20)['lists'][0]['volume_30d_min']==='0','Equal ordinary and VIP1 boundaries allow rate-only edits');
try { $manager->mutate(10, 20, 'delete', ['id' => $effective[0]['id'], 'request_id' => 'request-delete-zero']); throw new RuntimeException('Zero tier deleted'); }
catch (DomainException $e) { $check(true, 'Ordinary membership tier is protected'); }
echo "Scoped fee CRUD: {$checks} checks passed\n";
