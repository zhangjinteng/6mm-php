<?php
declare(strict_types=1);
use Aws\{MockHandler,Result};
use Aws\Lambda\LambdaClient;
use GuzzleHttp\Psr7\Utils;
use SixMm\Shared\TenantBrand\DomainLambda;

require __DIR__.'/vendor/autoload.php';
require dirname(__DIR__,2).'/src/TenantBrand/DomainLambda.php';
$mock=new MockHandler();
$client=new DomainLambda(new LambdaClient(['version'=>'2015-03-31','region'=>'ap-northeast-1',
    'credentials'=>false,'handler'=>$mock]),'fixture-domain');
$binding=(object)['binding_id'=>'test-1','cloudfront_tenant_id'=>null,'domain'=>'a.example.com'];
foreach (['createDomain'=>'provisioning','getDomainStatus'=>'active','deleteDomain'=>'deleted'] as $action=>$status) {
    $mock->append(new Result(['StatusCode'=>200,'Payload'=>Utils::streamFor(json_encode(['domain'=>$binding->domain,'tenantId'=>'cf-1','status'=>$status]))]));
    $out=$client->invoke($action,$binding);
    $cmd=$mock->getLastCommand(); $body=json_decode((string)$cmd['Payload'],true);
    if ($cmd['InvocationType']!=='RequestResponse'||$cmd['FunctionName']!=='fixture-domain'||$out['status']!==$status||
        ($body['tenantId']??null)!==$binding->cloudfront_tenant_id || $body['bindingId']!=='test-1'||$body['action']!==$action) throw new RuntimeException('incorrect invoke');
    $binding->cloudfront_tenant_id='cf-1';
}
$mock->append(new Result(['StatusCode'=>200,'FunctionError'=>'Unhandled','Payload'=>Utils::streamFor('{}')]));
try {$client->invoke('getDomainStatus',$binding); throw new LogicException('accepted function error');}
catch(RuntimeException $e){ if (!str_contains($e->getMessage(),'Lambda invoke failed')) throw $e; }
$mock->append(new Result(['StatusCode'=>204]));
if (!$client->dryRun() || $mock->getLastCommand()['InvocationType']!=='DryRun') throw new RuntimeException('bad dry-run');
echo "OK: offline AWS SDK Invoke payloads, FunctionError and DryRun\n";
