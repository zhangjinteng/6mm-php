<?php
declare(strict_types=1);
require getenv('PREDICTION_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
spl_autoload_register(static function(string $class): void {
    $prefix='SixMm\\Shared\\Prediction\\';
    if (str_starts_with($class,$prefix)) { require __DIR__.'/../src/Prediction/'.substr($class,strlen($prefix)).'.php'; }
},true,true);
use SixMm\Shared\Prediction\PredictionPageReader as Reader;
use SixMm\Shared\Prediction\ReadException;
use SixMm\Shared\Prediction\ReadResponder;
function check(bool $condition,string $name): void { if (!$condition) { throw new RuntimeException($name); } }
function fails(callable $call,string $code): void {
    try { $call(); } catch (ReadException $error) { check($error->errorCode===$code,'expected error code');return; }
    throw new RuntimeException('expected failure');
}
$page=['items'=>[['agent_id'=>'0','round_id'=>'round-1']],'query_contract_version'=>2];
check(Reader::read(['time_field'=>'DRAW_AT'],fn()=>$page) === $page,'page preserved');
Reader::assertMerchant($page,'100',true);
fails(fn()=>Reader::assertMerchant($page,'100'),'DEPENDENCY_UNAVAILABLE');
foreach (['100','200',0,null,'00'] as $agent) {
    fails(fn()=>Reader::assertMerchant(['items'=>[['agent_id'=>$agent,'round_id'=>'round-1']]],'100',true),'DEPENDENCY_UNAVAILABLE');
}
fails(fn()=>Reader::assertMerchant($page+['metadata'=>['agent_id'=>'200']],'100',true),'DEPENDENCY_UNAVAILABLE');
fails(fn()=>Reader::assertMerchant($page+['receipt'=>[]],'100',true),'DEPENDENCY_UNAVAILABLE');
foreach ([null,0,1,3,'2'] as $version) {
    fails(fn()=>Reader::read(['time_field'=>'DRAW_AT'],fn()=>array_replace($page,['query_contract_version'=>$version])),'QUERY_CONTRACT_MISMATCH');
}
fails(fn()=>Reader::read([],fn()=>$page,fn($p)=>array_replace($p,['items'=>[['agent_id'=>'200']]]),
    fn($p)=>Reader::assertMerchant($p,'100',true)),'DEPENDENCY_UNAVAILABLE');
$response=ReadResponder::run(fn()=>throw new RuntimeException('secret SQL credential'));
check($response->getStatusCode()===503,'safe error status');
check(!str_contains($response->getContent(),'secret'),'no exception leak');
check($response->getData(true)['data']['error_code']==='DEPENDENCY_UNAVAILABLE','safe error envelope');
$login=ReadResponder::run(fn()=>\SixMm\Shared\Prediction\GridResponder::reject('FORBIDDEN',401));
check($login->getStatusCode()===401,'preserve host authentication failure');
$permission=ReadResponder::run(fn()=>\SixMm\Shared\Prediction\GridResponder::reject('FORBIDDEN',403));
check($permission->getStatusCode()===403,'preserve host permission failure');
$unsafe=ReadResponder::run(fn()=>throw new \Illuminate\Http\Exceptions\HttpResponseException(
    new \Illuminate\Http\JsonResponse(['data'=>['error_code'=>'INVALID_REQUEST'],'debug'=>'secret SQL credential'],422)));
check($unsafe->getStatusCode()===422 && !str_contains($unsafe->getContent(),'secret'),'sanitize HTTP exceptions');
echo "Prediction SDK contract and scope checks passed\n";
