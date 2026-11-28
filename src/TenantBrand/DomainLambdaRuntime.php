<?php
declare(strict_types=1);

namespace SixMm\Shared\TenantBrand;

use Symfony\Component\Process\Process;

/** Isolate the AWS SDK's Promises 2.x autoloader from the host's Kafka Promises 1.x. */
final class DomainLambdaRuntime
{
    public function __construct(private array $config) {}
    public function dryRun(): bool { return $this->call('dry-run',[])['ok'] === true; }
    public function invoke(string $action,object $b): array
    {
        return $this->call($action,['binding_id'=>$b->binding_id,'domain'=>$b->domain,'cloudfront_tenant_id'=>$b->cloudfront_tenant_id]);
    }
    private function call(string $action,array $binding): array
    {
        $process=new Process([PHP_BINARY,dirname(__DIR__,2).'/integrations/domain-lambda/invoke.php']);
        $process->setInput(json_encode(['config'=>array_intersect_key($this->config,array_flip(['region','endpoint','function'])),
            'action'=>$action,'binding'=>$binding],JSON_THROW_ON_ERROR));
        $process->setTimeout(60)->run();
        if (!$process->isSuccessful()) {
            $error=json_decode($process->getErrorOutput(),true);
            throw new \RuntimeException('Lambda runtime: '.substr((string)($error['reason']??'runtime unavailable'),0,120)
                .' requestId='.substr((string)($error['request_id']??'-'),0,64));
        }
        return json_decode($process->getOutput(),true,512,JSON_THROW_ON_ERROR);
    }
}
