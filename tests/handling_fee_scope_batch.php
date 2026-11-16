<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/fixtures/scoped_handling_fees.php';
require __DIR__.'/fixtures/fee_operation_store.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use SixMm\Shared\HandlingFees\{HandlingFeeConfigManager, ScopedHandlingFeeConfigService};

$checks = 0;
$check = static function (bool $ok, string $why) use (&$checks): void {
    ++$checks;
    if (!$ok) throw new RuntimeException($why);
};
foreach ([[0,0],[910003,0],[910003,1]] as [$site,$agent]) {
    $capsule = new Capsule(); $capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
    $db = $capsule->getConnection(); scopedFeeFixture($db);
    $scoped = new ScopedHandlingFeeConfigService($db);
    if ($site) $scoped->ensureScope($site,$agent);
    for ($level=2; $level<23; ++$level) $db->table('handling_fee_level_config')->insert([
        'home_site_id'=>$site,'agent_id'=>$agent,'level'=>$level,'level_name'=>'VIP'.$level,
        'volume_30d'=>(string)(($level+1)*1000),'maker_fee_rate'=>'0.0001','taker_fee_rate'=>'0.0003','tk_version'=>5,
    ]);
    $scoped->query($site,$agent)->update(['tk_version'=>5]);
    $journal = new FeeOperationMemoryStore(); $calls=[]; $mode='ok';
    $rpc = static function (string $method,array $payload) use (&$calls,&$mode): array {
        $calls[]=[$method,$payload];
        if ($method==='GetFeeLevelConfig') return [];
        if ($mode==='timeout') throw new RuntimeException('lost reply');
        $response=successfulFeeBatch($payload);
        // Simulate protobuf omission of zero scalar values and platform "0" spelling.
        foreach ($response['results'] as &$item) {
            if ($item['level']===0) unset($item['level']);
            if ($item['agentId']==='0') unset($item['agentId']);
            if ($item['homeSiteId']==='') $item['homeSiteId']='0';
        }
        unset($item);
        if ($mode==='partial') { $response['results'][1]['rejectCode']=6010; --$response['successCount']; $response['failedCount']=1; }
        if ($mode==='reject') { foreach ($response['results'] as &$item) $item['rejectCode']=6010; unset($item); $response['failedCount']=count($payload['items']); unset($response['successCount']); }
        if ($mode==='wrong') $response['results'][0]['homeSiteId']='999';
        if ($mode==='wrong_agent') $response['results'][0]['agentId']='999';
        if ($mode==='counters') $response['successCount']=0;
        if ($mode==='unknown_item') { $response['results'][0]['rejectCode']=-1; --$response['successCount']; $response['failedCount']=1; }
        if ($mode==='duplicate') $response['results'][1]=$response['results'][0];
        if ($mode==='missing') array_pop($response['results']);
        return $response;
    };
    $manager = new HandlingFeeConfigManager($db,$rpc,operations:$journal);
    $tier=$scoped->query($site,$agent)->where('level',1)->first();
    $input=['id'=>$tier->id,'request_id'=>feeRequestId('batch-'.$site.'-'.$agent),'volume_30d'=>'2000','maker_fee_rate'=>'0.00015','taker_fee_rate'=>'0.0004'];
    $saved=$manager->mutate($site,$agent,'update',$input);
    $payload=end($calls)[1];
    $check(count($payload['items'])===23,'All tiers, including those beyond the first page, are sent');
    $check(array_column($payload['items'],'level')===range(0,22),'Full scope is ordered by stable VIP level');
    $check(count(array_unique(array_column($payload['items'],'command_id')))===23,'Each item has a distinct stable command ID');
    foreach ($payload['items'] as $item) {
        $check($item['home_site_id']===(string)$site && $item['agent_id']===(string)$agent,'No inherited/foreign owner is sent');
        $check((int)$item['version']>5,'Each item advances its own TK version');
        $local=$scoped->query($site,$agent)->where('level',$item['level'])->first();
        $check((string)$local->tk_version===$item['version'],'All synchronized versions are saved locally');
    }
    $check(bccomp($saved['maker_fee_rate'],'0.00015',18)===0,'Modified tier is merged');
    $check(bccomp($payload['items'][0]['maker_fee_rate'],'0.0002',18)===0,'Unmodified tier retains its rate');
    $before=$scoped->query($site,$agent)->get()->toJson();
    $mode='timeout';
    try { $manager->mutate($site,$agent,'update',array_replace($input,['request_id'=>feeRequestId('timeout-'.$site.'-'.$agent)])); throw new LogicException('Timeout accepted'); }
    catch (DomainException) {}
    $pending=$journal->pending($site,$agent); $original=end($calls)[1];
    $check($before===$scoped->query($site,$agent)->get()->toJson(),'Timeout changes no local rates or versions');
    $mode='ok'; $manager->retry($site,$agent,$pending['id']);
    $check(end($calls)[1]===$original,'Retry sends the exact complete original batch');
    // A crash after SQL commit must be recoverable without a second TK write.
    $journal->failAppliedOnce=true;
    try { $manager->mutate($site,$agent,'update',array_replace($input,['request_id'=>feeRequestId('after-sql-'.$site.'-'.$agent)])); } catch (DomainException) {}
    $pending=$journal->pending($site,$agent); $count=count($calls);
    $manager->retry($site,$agent,$pending['id']);
    $check(count($calls)===$count,'Confirmed batch finalizes locally without another RPC');
    foreach (['partial','wrong','wrong_agent','duplicate','missing','counters','unknown_item','reject'] as $scenario) {
        $mode=$scenario; $before=$scoped->query($site,$agent)->get()->toJson();
        try { $manager->mutate($site,$agent,'update',array_replace($input,['request_id'=>feeRequestId($scenario.'-'.$site.'-'.$agent)])); throw new LogicException('Invalid batch accepted'); }
        catch (DomainException) {}
        $check($before===$scoped->query($site,$agent)->get()->toJson(),'No local write on '.$scenario);
        $pending=$journal->pending($site,$agent);
        $check(($pending!==null)===($scenario!=='reject'),'Only complete deterministic rejection is terminal: '.$scenario);
        if ($pending) { $mode='ok'; $manager->retry($site,$agent,$pending['id']); }
    }
    // Existing pending single-tier operations must not be expanded or receive new command IDs.
    $mode='ok'; $before=$manager->detail($site,$agent,(int)$tier->id);
    $legacy=array_values($journal->records)[0];
    unset($legacy['scope_before']);
    $legacy['id']=bin2hex(random_bytes(16)); $legacy['request_id']=feeRequestId('legacy-'.$site.'-'.$agent);
    $legacy['status']='unknown'; $legacy['level']=1; $legacy['before']=json_encode($before);
    $legacy['values']=json_encode(['volume_30d'=>'2000','maker_fee_rate'=>'0.00014','taker_fee_rate'=>'0.0004']);
    $legacyPayload=['items'=>[['level'=>1,'home_site_id'=>$site ? (string)$site : '', 'agent_id'=>(string)$agent,
        'command_id'=>'1234567890123456789','version'=>(string)((int)$before['tk_version']+1),
        'maker_fee_rate'=>'0.00014','taker_fee_rate'=>'0.0004']]];
    $legacy['payload']=json_encode($legacyPayload); $journal->save($legacy);
    $manager->retry($site,$agent,$legacy['id']);
    $check(end($calls)[1]===$legacyPayload,'Legacy pending one-tier request retains its exact payload');
    // A local conflict in an unchanged tier prevents every local update after TK success.
    $mode='timeout';
    try { $manager->mutate($site,$agent,'update',array_replace($input,['request_id'=>feeRequestId('local-conflict-'.$site.'-'.$agent)])); } catch (DomainException) {}
    $pending=$journal->pending($site,$agent);
    $scoped->query($site,$agent)->where('level',22)->update(['maker_fee_rate'=>'0.000099']);
    $beforeConflict=$scoped->query($site,$agent)->get()->toJson(); $mode='ok';
    try { $manager->retry($site,$agent,$pending['id']); throw new LogicException('Concurrent local change overwritten'); }
    catch (DomainException) {}
    $check($beforeConflict===$scoped->query($site,$agent)->get()->toJson(),'Concurrent change in any tier prevents all local batch writes');
    $check($journal->pending($site,$agent)['status']==='confirmed','Confirmed batch conflict never becomes a new remote command');

}
echo "Full scope fee saves: {$checks} checks passed\n";
