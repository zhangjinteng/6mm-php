<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use SixMm\Shared\SymbolConfigs\SymbolTradingScopeInitializer;

$checks = 0;
$check = static function (bool $ok, string $why) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException($why); };
$config = static fn ($symbol, $state=1, $selected=true, $effective=1, $canOpen=true) =>
    ['symbol'=>$symbol,'state'=>$state,'selected'=>$selected,'tradingAccess'=>['effectiveState'=>$effective,'canOpen'=>$canOpen]];
foreach ([0, 20] as $agent) {
    foreach (['success','timeout_confirmed','rejected','pending','query_failed','parent_unregistered','wrong_scope','version_changed'] as $case) {
        $registered = false; $calls = []; $stored = null;
        $journal = static function ($key, $factory) use (&$stored) { return $stored ??= $factory(); };
        $rpc = static function ($method, $request) use (&$calls, &$registered, $case, $agent, $config, &$stored): array {
            $calls[] = [$method,$request];
            $op = static fn () => ['commandId'=>$stored['command_id'],'scope'=>$stored['scope'],
                'action'=>'SYMBOL_TRADING_ACTION_INITIALIZE','state'=>'SYMBOL_TRADING_OPERATION_COMPLETED','appliedVersion'=>'9'];
            if ($method==='ListSymbolTradingConfigs') {
                if ($case==='query_failed') throw new RuntimeException('offline');
                $target = $request['scope']['kind']===($agent>0?3:2);
                if ($target) return $registered ? ['mode'=>1,'scopeVersion'=>'9','configs'=>[$config('BTCUSDT')]] : [];
                if ($case==='parent_unregistered') return [];
                if ($request['cursor']==='') return ['mode'=>1,'scopeVersion'=>'8','configs'=>[
                    $config('BTCUSDT'),$config('PAUSED',2),$config('UNSELECTED',1,false),$config('BLOCKED',1,true,2),$config('NOOPEN',1,true,1,false)],'nextCursor'=>'page2'];
                return ['mode'=>1,'scopeVersion'=>$case==='version_changed'?'10':'8','configs'=>[$config('ETHUSDT')]];
            }
            if ($method==='InitializeSymbolTradingScope') {
                if ($case==='rejected') return ['rejectCode'=>6003,'message'=>'version conflict'];
                $registered = true;
                if (in_array($case,['timeout_confirmed','pending'],true)) throw new RuntimeException('timeout');
                $operation = $op();
                if ($case==='wrong_scope') $operation['scope']['home_site_id']='999';
                return ['operation'=>$operation];
            }
            if ($method==='GetSymbolTradingOperation') return ['found'=>$case==='timeout_confirmed','operation'=>$op()];
            throw new LogicException('Unexpected RPC');
        };
        $service = new SymbolTradingScopeInitializer($rpc,$journal);
        try {
            $result = $service->initialize(10,$agent);
            $check(in_array($case,['success','timeout_confirmed'],true),'Failure must not succeed: '.$case);
            $check($result['initialized']===true,'Initialization confirmed');
            $check($stored['initial_symbols']===['BTCUSDT','ETHUSDT'],'Only effectively tradable parent selection, across all pages');
            $check($stored['expected_version']==='0' && $stored['mode']===1,'WHITELIST CAS zero');
            $writes = count(array_filter($calls,fn ($call)=>$call[0]==='InitializeSymbolTradingScope'));
            $check($service->initialize(10,$agent)['initialized']===false,'Registered scope skipped');
            $check(count(array_filter($calls,fn ($call)=>$call[0]==='InitializeSymbolTradingScope'))===$writes,'No second Initialize');
        } catch (DomainException|RuntimeException $e) {
            $check(!in_array($case,['success','timeout_confirmed'],true),'Success unexpectedly failed: '.$e->getMessage());
            if (in_array($case,['query_failed','parent_unregistered','version_changed'],true)) {
                $check(count(array_filter($calls,fn ($call)=>$call[0]==='InitializeSymbolTradingScope'))===0,'Invalid preflight prevents write');
            }
        }
    }
}
$stored = null; $payloads = []; $parentReads = 0;
$rpc = static function ($method, $request) use (&$payloads, &$parentReads, $config): array {
    if ($method==='ListSymbolTradingConfigs') {
        if ($request['scope']['kind']===2) return [];
        ++$parentReads;
        return ['mode'=>1,'scopeVersion'=>'1','configs'=>[$config($parentReads===1?'BTCUSDT':'ETHUSDT')]];
    }
    if ($method==='InitializeSymbolTradingScope') { $payloads[]=$request; throw new RuntimeException('timeout before apply'); }
    return ['found'=>false];
};
$journal = static function ($key,$factory) use (&$stored) { return $stored ??= $factory(); };
$service = new SymbolTradingScopeInitializer($rpc,$journal);
for ($attempt=0;$attempt<2;++$attempt) {
    try { $service->initialize(10); throw new LogicException('Unknown write accepted'); }
    catch (DomainException $e) { $check(str_contains($e->getMessage(),'待确认'),'Unknown result stays pending'); }
}
$check($payloads[0]===$payloads[1] && $parentReads===1,'Retry preserves the exact command and original parent selection');
echo "Symbol trading scope initialization: {$checks} checks passed\n";
