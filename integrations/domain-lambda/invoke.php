<?php
declare(strict_types=1);

use SixMm\Shared\TenantBrand\DomainLambda;

if (PHP_SAPI !== 'cli') exit(1);
try {
    require __DIR__.'/vendor/autoload.php';
    require dirname(__DIR__,2).'/src/TenantBrand/DomainLambda.php';
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $client=DomainLambda::configured($input['config']);
    $result=match($input['action']) {
        'dry-run'=>['ok'=>$client->dryRun()],
        'createDomain','getDomainStatus','deleteDomain'=>$client->invoke($input['action'],(object)$input['binding']),
    };
    echo json_encode($result,JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $requestId=$e instanceof \Aws\Exception\AwsException ? $e->getAwsRequestId() : null;
    $reason=$e instanceof RuntimeException && str_starts_with($e->getMessage(),'Lambda invoke failed; requestId=') ? $e->getMessage() : get_class($e);
    fwrite(STDERR,json_encode(['reason'=>$reason,'request_id'=>$requestId]));
    exit(1);
}
