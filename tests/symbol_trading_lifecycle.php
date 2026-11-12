<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/fixtures/scoped_symbol_configs.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use SixMm\Shared\SymbolConfigs\ScopedSymbolConfigService;
use SixMm\Shared\SymbolConfigs\SymbolTradingLifecycle;

$checks=0;
$check=static function (bool $ok,string $message) use (&$checks): void {
    ++$checks;
    if (!$ok) throw new RuntimeException($message);
};
$fixture=static function (): array {
    $capsule=new Capsule();
    $capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
    $db=$capsule->getConnection();
    scopedSymbolFixture($db);
    $db->table('symbol_config')->insert([
        ['id'=>1,'symbol'=>'BTCUSDT','is_active'=>1,'is_visible'=>true],
        ['id'=>2,'symbol'=>'ETHUSDT','is_active'=>1,'is_visible'=>true],
    ]);
    $configs=new ScopedSymbolConfigService($db);
    foreach ([[10,20],[10,21],[11,30]] as [$site,$agent]) $configs->ensureScope($site,$agent);
    return [$db,$configs];
};
$response=static function (string $method,array $request): array {
    return ['operation'=>['command_id'=>$request['command_id'],'scope'=>$request['scope'],'symbol'=>$request['symbol'],
        'action'=>['ListSymbolForTrading'=>2,'PauseSymbolTrading'=>3,'ResumeSymbolTrading'=>4,'DelistSymbolTrading'=>5][$method],
        'state'=>$method==='DelistSymbolTrading'?1:2]];
};

foreach ([[0,0],[10,0],[10,20]] as [$site,$agent]) {
    foreach (['pause'=>1,'delist'=>2,'resume'=>2,'list'=>4] as $action=>$state) {
        [$db,$configs]=$fixture();
        $id=(int)$configs->query($site,$agent)->where('symbol','BTCUSDT')->value('id');
        $before=$db->table('symbol_config')->orderBy('id')->get()->keyBy('id');
        $calls=[];
        $rpc=static function ($method,$request) use (&$calls,$check,$db,$before,$state,$response): array {
            $check($db->table('symbol_config')->orderBy('id')->get()->keyBy('id')->toJson()===$before->toJson(),'No writes before TK acknowledgement');
            $calls[]=[$method,$request];
            if ($method==='ListSymbolTradingConfigs') return ['mode'=>1,'scope_version'=>'8','configs'=>[
                ['symbol'=>'BTCUSDT','state'=>$state,'trading_access'=>['effective_state'=>$state]]]];
            return $response($method,$request);
        };
        $result=(new SymbolTradingLifecycle($db,$rpc,fn ()=>'123'))->execute($site,$agent,$id,$action);
        $check(count($calls)===2 && $calls[0][0]==='ListSymbolTradingConfigs','Exactly one preflight precedes the operation');
        $write=$calls[1][1];
        $check(!array_key_exists('mark_price',$write) && !array_key_exists('mark_price_time',$write),'Price fields are absent');
        $check($calls[1][0]===['list'=>'ListSymbolForTrading','pause'=>'PauseSymbolTrading','resume'=>'ResumeSymbolTrading','delist'=>'DelistSymbolTrading'][$action],'Every scope uses the scoped operation RPC');
        $check($write['scope']===SymbolTradingLifecycle::scope($site,$agent) && $write['expected_version']==='8','Exact scope and queried version');
        foreach ($db->table('symbol_config')->get() as $row) {
            $affected=$row->symbol==='BTCUSDT' && ($site===0 || (int)$row->home_site_id===$site)
                && ($agent===0 || (int)$row->agent_id===$agent);
            if (in_array($action,['list','resume'],true)) $affected=$row->id===$id;
            $old=$before[$row->id];
            if (!$affected) $check((array)$row===(array)$old,'Unrelated rows and children on reopening remain untouched');
            else {
                $check((bool)$row->is_visible===in_array($action,['list','resume'],true),'Correct trading switch');
                $expected=match($action) { 'list'=>1,'delist'=>0,default=>$old->is_active };
                $check($row->is_active===$expected,'Correct listing switch');
            }
        }
        $check($result['message']===($action==='delist'?'下架申请已提交':['list'=>'上架成功','pause'=>'暂停交易成功','resume'=>'恢复交易成功'][$action]),'No false completion message');
    }
}

foreach (['busy','parent_busy','query_error','empty','unknown_state','missing_access','write_error','rejected','wrong_command','pending_pause','wrong_scope','not_paused'] as $failure) {
    [$db,$configs]=$fixture();
    $before=$db->table('symbol_config')->orderBy('id')->get()->toJson();
    $writes=0;
    $rpc=static function ($method,$request) use ($failure,&$writes,$response): array {
        if ($method==='ListSymbolTradingConfigs') {
            if ($failure==='query_error') throw new RuntimeException('network');
            $row=['symbol'=>'BTCUSDT','state'=>1,'trading_access'=>['effective_state'=>1]];
            if ($failure==='busy') $row['state']=3;
            if ($failure==='parent_busy') $row['trading_access']['effective_state']=3;
            if ($failure==='unknown_state') $row['state']=0;
            if ($failure==='missing_access') unset($row['trading_access']);
            return ['mode'=>1,'scope_version'=>'8','configs'=>$failure==='empty'?[]:[$row]];
        }
        ++$writes;
        if ($failure==='write_error') throw new RuntimeException('network');
        if ($failure==='rejected') return ['reject_code'=>6003,'message'=>'version conflict'];
        $result=$response($method,$request);
        if ($failure==='wrong_command') $result['operation']['command_id']='456';
        if ($failure==='pending_pause') $result['operation']['state']=1;
        if ($failure==='wrong_scope') $result['operation']['scope']=['kind'=>2,'home_site_id'=>'999'];
        return $result;
    };
    $id=(int)$configs->query(10,20)->where('symbol','BTCUSDT')->value('id');
    try {
        (new SymbolTradingLifecycle($db,$rpc,fn ()=>'123'))->execute(10,20,$id,$failure==='not_paused'?'delist':'pause');
        throw new LogicException('Failure incorrectly accepted: '.$failure);
    } catch (DomainException|RuntimeException $e) {
        $check($db->table('symbol_config')->orderBy('id')->get()->toJson()===$before,'Failure cannot change database: '.$failure);
        if (in_array($failure,['busy','parent_busy'],true)) $check($e->getMessage()===SymbolTradingLifecycle::BUSY && $writes===0,'Busy prevents RPC');
        if (in_array($failure,['query_error','empty','unknown_state','missing_access'],true)) $check($e->getMessage()===SymbolTradingLifecycle::UNCONFIRMED && $writes===0,'Unconfirmed query fails closed');
        if ($failure==='not_paused') $check($writes===0,'Delist never auto-pauses or bypasses prerequisite');
    }
}

// Platform delisting must never fall back to the global archive RPC.
foreach (['in_progress','completed','write_error','rejected','legacy_receipt','wrong_scope'] as $case) {
    [$db,$configs]=$fixture();
    $before=$db->table('symbol_config')->orderBy('id')->get()->toJson();
    $calls=[];
    $keys=[];
    $rpc=static function ($method,$request) use ($case,&$calls,$check): array {
        $calls[]=$method;
        $check($request['scope']===['kind'=>1],'Platform delist uses PLATFORM without site or agent IDs');
        if ($method==='ListSymbolTradingConfigs') return ['mode'=>1,'scope_version'=>'8','configs'=>[
            ['symbol'=>'BTCUSDT','state'=>2,'trading_access'=>['effective_state'=>2]]]];
        $check($method==='DelistSymbolTrading' && $request['expected_version']==='8','Platform delist uses scoped RPC and current version');
        if ($case==='write_error') throw new RuntimeException('network');
        if ($case==='rejected') return ['reject_code'=>6003,'message'=>'version conflict'];
        if ($case==='legacy_receipt') return ['status'=>'ACCEPTED','command_id'=>$request['command_id'],'symbol'=>'BTCUSDT'];
        return ['operation'=>['commandId'=>$request['command_id'],
            'scope'=>['kind'=>$case==='wrong_scope'?'SYMBOL_TRADING_SCOPE_SITE':'SYMBOL_TRADING_SCOPE_PLATFORM'],
            'symbol'=>'BTCUSDT','action'=>'SYMBOL_TRADING_ACTION_DELIST',
            'state'=>$case==='completed'?'SYMBOL_TRADING_OPERATION_COMPLETED':'SYMBOL_TRADING_OPERATION_IN_PROGRESS']];
    };
    $command=static function ($key) use (&$keys): string { $keys[]=$key; return '123'; };
    $error=null;
    try {
        (new SymbolTradingLifecycle($db,$rpc,$command))->execute(0,0,1,'delist');
    } catch (DomainException|RuntimeException $e) {
        $error=$e;
    }
    $check(($error===null)===in_array($case,['in_progress','completed'],true),'Only confirmed scoped delist receipts succeed: '.$case);
    $check($calls===['ListSymbolTradingConfigs','DelistSymbolTrading'],'No global RPC fallback: '.$case);
    $check($keys===[hash('sha256',json_encode([['kind'=>1],'BTCUSDT','8','delist','DelistSymbolTrading'],JSON_THROW_ON_ERROR))],'New scoped command identity is stable and separate from legacy delisting');
    if ($error!==null) $check($db->table('symbol_config')->orderBy('id')->get()->toJson()===$before,'Failed platform delist preserves DB: '.$case);
}

// SDK responses use camelCase and symbolic enums, and omit zero reject codes.
[$db,$configs]=$fixture();
$id=(int)$configs->query(10)->where('symbol','BTCUSDT')->value('id');
$rpc=static function ($method,$request): array {
    if ($method==='ListSymbolTradingConfigs') return ['mode'=>'SYMBOL_TRADING_MODE_WHITELIST','scopeVersion'=>'4','configs'=>[
        ['symbol'=>'BTCUSDT','state'=>'SYMBOL_TRADING_STATE_LISTED','tradingAccess'=>['effectiveState'=>'SYMBOL_TRADING_STATE_LISTED']]]];
    return ['operation'=>['commandId'=>$request['command_id'],'scope'=>['kind'=>'SYMBOL_TRADING_SCOPE_SITE','homeSiteId'=>'10'],
        'symbol'=>'BTCUSDT','action'=>'SYMBOL_TRADING_ACTION_PAUSE','state'=>'SYMBOL_TRADING_OPERATION_COMPLETED']];
};
(new SymbolTradingLifecycle($db,$rpc,fn ()=>'123'))->execute(10,0,$id,'pause');
$check(!(bool)$configs->query(10)->where('id',$id)->value('is_visible'),'Native SDK enum/field normalization');
foreach ([[10,0],[10,20]] as [$site,$agent]) {
    foreach (['paused','listed','delisted','busy','effective_busy','unselected','wrong_scope','ancestor_block','other_reason',
        'unknown_effective','unknown_state','missing_access','parent_disabled','write_reject','pending','wrong_command','resume'] as $case) {
        [$db,$configs]=$fixture();
        $id=(int)$configs->query($site,$agent)->where('symbol','BTCUSDT')->value('id');
        if ($case==='parent_disabled') $configs->query(0)->where('symbol','BTCUSDT')->update(['is_visible'=>false]);
        $before=$db->table('symbol_config')->orderBy('id')->get()->toJson();
        $writes=[];
        $rpc=static function ($method,$request) use ($case,$site,$agent,$response,$db,$before,$check,&$writes): array {
            $check($db->table('symbol_config')->orderBy('id')->get()->toJson()===$before,'Reselection never changes DB before acknowledgement');
            if ($method==='ListSymbolTradingConfigs') {
                $check($request['scope']===SymbolTradingLifecycle::scope($site,$agent),'No broader unauthorized scope queries');
                $config=['symbol'=>'BTCUSDT','state'=>'SYMBOL_TRADING_STATE_'.match($case) {
                    'listed'=>'LISTED','delisted'=>'DELISTED','busy'=>'DELISTING','unknown_state'=>'UNSPECIFIED',default=>'PAUSED',
                },'selected'=>true,'platformGeneration'=>'4','tradingAccess'=>[
                    'blockingScope'=>$request['scope'],'reason'=>'PARENT_GENERATION_EXPIRED','canReduce'=>true,'canCancel'=>true,
                ]];
                if ($case==='unselected') unset($config['selected']);
                if ($case==='wrong_scope') $config['tradingAccess']['blockingScope']['home_site_id']='999';
                if ($case==='ancestor_block') $config['tradingAccess']['blockingScope']=['kind'=>1];
                if ($case==='other_reason') $config['tradingAccess']['reason']='UNKNOWN';
                if ($case==='unknown_effective') $config['tradingAccess']['effectiveState']=999;
                if ($case==='effective_busy') $config['tradingAccess']['effectiveState']='SYMBOL_TRADING_STATE_DELISTING';
                if ($case==='missing_access') unset($config['tradingAccess']);
                return ['mode'=>'SYMBOL_TRADING_MODE_WHITELIST','scopeVersion'=>'2','configs'=>[$config]];
            }
            $writes[]=[$method,$request];
            $check($method==='ListSymbolForTrading','Expired bindings only reselect through List, never Initialize or Resume');
            $check($request['expected_version']==='2','Reselection uses current target scope version');
            if ($case==='write_reject') return ['reject_code'=>6003,'message'=>'parent unavailable'];
            $result=$response($method,$request);
            if ($case==='pending') $result['operation']['state']=1;
            if ($case==='wrong_command') $result['operation']['command_id']='456';
            return $result;
        };
        try {
            (new SymbolTradingLifecycle($db,$rpc,fn ()=>'123'))->execute($site,$agent,$id,$case==='resume'?'resume':'list');
            $check(in_array($case,['paused','listed','delisted'],true),'Invalid expiry case accepted: '.$case);
            $check(count($writes)===1,'One re-selection RPC');
            $saved=$configs->query($site,$agent)->where('id',$id)->first();
            $check((int)$saved->is_active===1 && (bool)$saved->is_visible,'Both switches open after confirmed reselection');
            foreach (json_decode($before,true,512,JSON_THROW_ON_ERROR) as $old) {
                if ((int)$old['id']===$id) continue;
                $check((array)$db->table('symbol_config')->where('id',$old['id'])->first()===$old,'Reselection changes only exact scope');
            }
        } catch (DomainException $e) {
            $check(!in_array($case,['paused','listed','delisted'],true),'Valid expiry rejected: '.$e->getMessage());
            $check($db->table('symbol_config')->orderBy('id')->get()->toJson()===$before,'Rejected reselection preserves DB');
            if (!in_array($case,['write_reject','pending','wrong_command'],true)) $check($writes===[],'Failed preflight cannot send List');
            if (in_array($case,['busy','effective_busy'],true)) $check($e->getMessage()===SymbolTradingLifecycle::BUSY,'Delisting stays blocked');
        }
    }
}
echo "Symbol trading lifecycle: {$checks} checks passed\n";
