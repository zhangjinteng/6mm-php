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
    if ($method==='DelistAndSettleSymbol') return ['command_id'=>$request['command_id'],'symbol'=>$request['symbol'],'status'=>'ACCEPTED'];
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
        $check(($calls[1][0]==='DelistAndSettleSymbol')===($action==='delist' && $site===0),'Only platform delisting uses the legacy global RPC');
        if ($calls[1][0]==='DelistAndSettleSymbol') $check(!isset($write['scope'],$write['expected_version']),'Legacy RPC has no scope/version');
        else $check($write['scope']===SymbolTradingLifecycle::scope($site,$agent) && $write['expected_version']==='8','Exact scope and queried version');
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
echo "Symbol trading lifecycle: {$checks} checks passed\n";
