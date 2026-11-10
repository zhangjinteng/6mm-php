<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantCustomerService;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** A durable, tenant-wide operation. No database lock is held during HTTP. */
final class CustomerLifecycleService
{
    public function __construct(private CustomerServiceContext $host) {}

    private const MAX_ATTEMPTS = 5;

    private function table(string $name): \Illuminate\Database\Query\Builder
    {
        return $this->host->table($name);
    }

    private function reject(string $message): never
    {
        $this->host->reject('customer_service', $message);
    }

    private function locked(string $id): array
    {
        if ($this->host->db->getDriverName() === 'pgsql') {
            $this->host->db->statement("SET LOCAL lock_timeout = '5s'");
            $this->host->db->statement("SET LOCAL statement_timeout = '15s'");
        }
        $tenant = $this->table('wl_tenants')->where('tenant_id', $id)->lockForUpdate()->first();
        if (!$tenant) $this->reject('白标不存在');
        $row = $this->table('wl_channel_configs')->where('tenant_id', $id)->lockForUpdate()->first();
        return [$tenant, $row, $row ? json_decode($row->customer_service, true, 512, JSON_THROW_ON_ERROR) : []];
    }

    private function persist(string $id, ?object $row, array $config, int $operatorId): void
    {
        $time = \Carbon\CarbonImmutable::now();
        if ($row && $time->lessThanOrEqualTo(\Carbon\Carbon::parse($row->updated_at))) $time = \Carbon\Carbon::parse($row->updated_at)->addMicrosecond();
        $stamp = $time->format('Y-m-d H:i:s.uP');
        $config = $this->host->normalizeBinding($config);
        $data = ['customer_service' => json_encode((object) $config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'updated_at' => $stamp];
        $data += $this->host->operatorColumns($row === null);
        if ($row) $this->table('wl_channel_configs')->where('tenant_id', $id)->update($data);
        else $this->table('wl_channel_configs')->insert($data + ['tenant_id' => $id, 'created_at' => $stamp]);
        // Never audit payloads, passwords, keys, HTTP bodies or exception text.
        $this->host->audit(['tenant_id' => $id, 'operation_id' => $config['operation']['id'],
            'enabled' => $config['operation']['enabled'], 'status' => $config['operation']['status']], $stamp, 'businessConfig:publish');
    }

    public static function publicOperation(array $config): ?array
    {
        // Channel DTOs decode nested JSON as objects; business DTOs use arrays.
        $op = (array) ($config['operation'] ?? []);
        if (!$op || $op['status'] === 'completed') return null;
        return ['status' => $op['status'], 'enabled' => $op['enabled'], 'message' => $op['message'] ?? '',
            'attempts' => $op['attempts'], 'retry_after' => $op['retry_after'] ?? null,
            'can_retry' => $op['status'] === 'confirmed' || (in_array($op['status'], ['pending', 'inflight'], true)
                && $op['attempts'] < self::MAX_ATTEMPTS && \Carbon\CarbonImmutable::now()->timestamp >= ($op['retry_after'] ?? 0))];
    }

    public function operation(string $id): ?array
    {
        $json = $this->table('wl_channel_configs')->where('tenant_id', $id)->value('customer_service');
        return self::publicOperation($json ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : []);
    }

    private function validTenantId(mixed $id): bool
    {
        return (is_int($id) || is_string($id)) && preg_match('/^[1-9][0-9]{0,15}$/D', (string) $id)
            && (strlen((string) $id) < 16 || strcmp((string) $id, '9007199254740991') <= 0);
    }

    /** $preflight runs under publication locks before the claim; $publish completes inside its own transaction. */
    public function transition(array $input, int $operatorId, callable $preflight, callable $publish): array
    {
        $this->host->assertActor($operatorId);
        $this->host->assertCanPublish();
        $id = (string) $input['tenant_id'];
        $enabled = $input['modules'][CustomerService::MODULE];
        $attemptId = (string) Str::uuid();
        $prepared = $this->host->db->transaction(function () use ($input, $id, $enabled, $operatorId, $preflight, $attemptId): array {
            $preflight();
            [$tenant, $row, $config] = $this->locked($id);
            if (!empty($config['provider']) && !CustomerService::isProvider($config['provider'])) $this->reject('已有其他客服配置，不能覆盖');
            $old = $config['operation'] ?? null;
            $unresolved = $old && in_array($old['status'], ['inflight', 'pending', 'confirmed'], true);
            if ($unresolved) {
                if ($old['enabled'] !== $enabled) $this->reject('上次客服操作待核验，请先完成原操作，不能反向切换');
                if ($old['status'] === 'confirmed') return ['op' => $old, 'confirmed' => true];
                if (\Carbon\CarbonImmutable::now()->timestamp < ($old['retry_after'] ?? 0)) $this->reject('客服操作处理中或重试冷却中，请稍后核验');
                if ($old['attempts'] >= self::MAX_ATTEMPTS) $this->reject('客服操作已达重试上限，请联系管理员核验，勿反向切换');
            } elseif (in_array($config['status'] ?? '', ['pending', 'provisioning'], true)) {
                $this->reject('原客服开户结果待确认，请先在渠道配置使用原资料核验');
            }
            $this->host->assertReady();
            if (!$unresolved && $enabled && !(new CustomerService($this->host))->bound($id)) {
                $this->host->assertCanProvision();
            }
            if ($unresolved) $payload = $old['payload'];
            elseif ($enabled) {
                $name = trim($tenant->tenant_name);
                $requested = $config['requested'] ?? [];
                $payload = ['merchantId' => $config['merchant_id'] ?? '6mm_wl_'.$id, 'name' => $name,
                    'seatLimit' => 1, 'serviceEndAt' => \Carbon\CarbonImmutable::now($input['client_timezone'] ?? 'UTC')->addMonthNoOverflow()->format('Y-m-d\TH:i:sP'),
                    'adminName' => $requested['adminName'] ?? $name, 'adminEmail' => strtolower(trim($requested['adminEmail'] ?? $name.'@6mm.com'))];
                $this->host->validator->make($payload, ['merchantId' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/D'],
                    'name' => 'required|string|max:128', 'adminName' => 'required|string|max:64',
                    'adminEmail' => 'required|email:rfc|max:254', 'serviceEndAt' => 'required|date|after:now'])->validate();
            } else {
                if (!(new CustomerService($this->host))->bound($id) || !$this->validTenantId($config['tenant_id'] ?? null)) {
                    $this->reject('缺少有效客服绑定，不能确认远程关闭');
                }
                if (!is_string($config['merchant_id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $config['merchant_id'])) {
                    $this->reject('缺少有效的原商户标识，请核验客服绑定');
                }
                $payload = ['merchantId' => $config['merchant_id'], 'tenantId' => (int) $config['tenant_id'], 'reason' => $this->host->actorType === 'platform' ? '总后台关闭6MM客服' : '白标后台关闭6MM客服'];
            }
            $op = ['id' => $unresolved ? $old['id'] : (string) Str::uuid(), 'attempt_id' => $attemptId,
                'enabled' => $enabled, 'status' => 'inflight', 'payload' => $payload,
                'attempts' => $unresolved ? $old['attempts'] + 1 : 1, 'uncertain' => (bool) $unresolved,
                'retry_after' => \Carbon\CarbonImmutable::now()->timestamp + 45, 'message' => '客服操作处理中'];
            $config = array_replace($config, ['provider' => CustomerService::PROVIDER,
                'merchant_id' => $payload['merchantId'], 'status' => $config['status'] ?? 'unconfigured', 'operation' => $op]);
            $this->persist($id, $row, $config, $operatorId);
            return ['op' => $op, 'confirmed' => false];
        }, 3);

        $op = $prepared['op'];
        if (!$prepared['confirmed']) {
            $status = 'pending'; $message = '客服结果待核验，请稍后重试原操作'; $binding = null;
            $retryAfter = min(60, 2 ** $op['attempts']);
            try {
                $response = $this->host->http->asJson()->acceptJson()->withHeaders(['X-Exchange-Api-Key' => trim($this->host->apiKey)])
                    ->timeout(25)->withOptions(['connect_timeout' => 5, 'allow_redirects' => false])
                    ->post(CustomerService::ENDPOINT.($enabled ? '' : '/close'),
                        $enabled ? $op['payload'] + ['adminPassword' => '12345678'] : $op['payload']);
                $body = json_decode($response->body(), true, 512, JSON_BIGINT_AS_STRING);
                $data = $body['data'] ?? null;
                if ($response->status() === 200 && ($body['code'] ?? null) === 0 && is_array($data)) {
                    if ($enabled && is_bool($data['created'] ?? null) && $this->validTenantId($data['tenantId'] ?? null)
                        && is_string($data['appKey'] ?? null) && trim($data['appKey']) !== '' && strlen($data['appKey']) <= 4096) {
                        $status = 'confirmed';
                        $binding = ['status' => 'bound', 'tenant_id' => (string) $data['tenantId'],
                            'appKey' => $data['appKey'], 'created' => $data['created'], 'requested' => $op['payload']];
                    } elseif (!$enabled && ($data['status'] ?? '') === 'suspended' && is_bool($data['changed'] ?? null)
                        && ($data['merchantId'] ?? null) === $op['payload']['merchantId']
                        && $this->validTenantId($data['tenantId'] ?? null) && (string) $data['tenantId'] === (string) $op['payload']['tenantId']) {
                        $status = 'confirmed';
                    }
                } elseif (in_array($response->status(), [400, 401, 404, 409], true)) {
                    $machine = $body['error'] ?? $body['code'] ?? '';
                    $messages = is_array($body['message'] ?? null) ? implode(' ', $body['message']) : (string) ($body['message'] ?? '');
                    $changed = $machine === 'TENANT_CHANGED' || str_contains($messages, 'TENANT_CHANGED');
                    $deleting = $machine === 'TENANT_DELETING' || str_contains($messages, 'TENANT_DELETING');
                    $status = !$op['uncertain'] || $changed ? 'failed' : 'pending';
                    $message = $changed ? '客服租户已变化，原关闭任务已停止，请联系管理员核验绑定' : ($deleting ? '客服租户正在删除，请稍后重试' : match ($response->status()) {
                        400 => '客服资料校验失败，请检查开户资料', 401 => '客服服务端密钥无效，请联系管理员',
                        404 => '客服租户不存在，请核验绑定', default => '客服平台状态冲突，请核验账号后重试',
                    });
                }
                if ($response->status() === 429) {
                    $header = $response->header('Retry-After');
                    $retryAfter = ctype_digit((string) $header) ? max(1, min(86400, (int) $header))
                        : max($retryAfter, min(86400, (strtotime((string) $header) ?: \Carbon\CarbonImmutable::now()->timestamp) - \Carbon\CarbonImmutable::now()->timestamp));
                    $message = '客服平台限流，请在冷却后重试原操作';
                }
            } catch (Throwable $e) {
                // Requests include credentials. Do not log exceptions or raw responses.
            }
            $this->host->db->transaction(function () use ($id, $op, $operatorId, $status, $message, $binding, $retryAfter): void {
                [, $row, $config] = $this->locked($id);
                if (($config['operation']['attempt_id'] ?? '') !== $op['attempt_id']) $this->reject('客服操作状态已变化，请重新打开核验');
                $config = array_replace($config, $binding ?? []);
                $config['operation']['status'] = $status;
                $config['operation']['message'] = $status === 'confirmed' ? '远程已确认，等待本地发布' : $message;
                $config['operation']['retry_after'] = \Carbon\CarbonImmutable::now()->timestamp + $retryAfter;
                if ($status === 'confirmed') $config['remote_status'] = $op['enabled'] ? 'active' : 'suspended';
                $this->persist($id, $row, $config, $operatorId);
            }, 3);
            if ($status !== 'confirmed') $this->reject($message);
        }
        return $publish($op['id']);
    }

    /** Called in the same transaction as local module/snapshot publication. */
    public function complete(string $id, string $operationId, int $operatorId): void
    {
        [, $row, $config] = $this->locked($id);
        if (($config['operation']['id'] ?? '') !== $operationId || ($config['operation']['status'] ?? '') !== 'confirmed') {
            $this->reject('客服操作尚未确认，不能发布');
        }
        $config['operation']['status'] = 'completed';
        unset($config['operation']['payload']);
        $this->persist($id, $row, $config, $operatorId);
    }
}

