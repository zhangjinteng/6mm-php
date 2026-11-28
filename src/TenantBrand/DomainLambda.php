<?php
declare(strict_types=1);

namespace SixMm\Shared\TenantBrand;

use Aws\Credentials\CredentialProvider;
use Aws\Lambda\LambdaClient;

final class DomainLambda
{
    public function __construct(private LambdaClient $client, private string $function) {}

    public static function configured(array $cfg): self
    {
        $options = ['region' => $cfg['region'], 'version' => '2015-03-31',
            'credentials' => CredentialProvider::memoize(CredentialProvider::instanceProfile()),
            'retries' => 0, 'http' => ['connect_timeout' => 4, 'timeout' => 28]];
        if ($cfg['endpoint'] ?? '') {
            if (parse_url($cfg['endpoint'], PHP_URL_SCHEME) !== 'https') throw new \LogicException('Lambda endpoint must use HTTPS');
            $options['endpoint'] = $cfg['endpoint'];
        }
        return new self(new LambdaClient($options), $cfg['function']);
    }

    public function dryRun(): bool
    {
        return 204 === $this->client->invoke(['FunctionName'=>$this->function, 'InvocationType'=>'DryRun'])['StatusCode'];
    }

    public function invoke(string $action, object $binding): array
    {
        $event = ['action'=>$action, 'domain'=>$binding->domain, 'bindingId'=>$binding->binding_id];
        if ($action !== 'createDomain') $event['tenantId'] = $binding->cloudfront_tenant_id;
        $result = $this->client->invoke(['FunctionName'=>$this->function, 'InvocationType'=>'RequestResponse',
            'Payload'=>json_encode($event, JSON_THROW_ON_ERROR)]);
        if ($result['FunctionError'] || $result['StatusCode'] !== 200) {
            throw new \RuntimeException('Lambda invoke failed; requestId='.($result['@metadata']['headers']['x-amzn-requestid'] ?? '-'));
        }
        $r = json_decode((string)$result['Payload'], true, 512, JSON_THROW_ON_ERROR);
        $states = $action === 'deleteDomain' ? ['deleting','deleted'] :
            ['provisioning','pending_validation','deploying','active','certificate_error','disabled','not_found'];
        if (($r['domain'] ?? null) !== $binding->domain || !is_string($r['tenantId'] ?? null)
            || trim($r['tenantId']) === '' || strlen($r['tenantId']) > 255
            || ($binding->cloudfront_tenant_id !== null && $r['tenantId'] !== $binding->cloudfront_tenant_id)
            || !in_array($r['status'] ?? null, $states, true)) {
            throw new \UnexpectedValueException('Invalid Lambda domain response');
        }
        return $r;
    }
}
