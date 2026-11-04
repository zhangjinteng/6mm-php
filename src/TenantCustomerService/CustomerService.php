<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantCustomerService;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CustomerService
{
    public const PROVIDER = 'sixmm';
    public const MODULE = 'customer_service';
    public const ENDPOINT = 'https://cs.6mm.com/api/v1/integrations/exchange/tenants';
    public function __construct(private CustomerServiceContext $host) {}

    private const ACTION = 'businessConfig:channel:provision';

    public function ready(): bool { return $this->host->ready(); }

    public static function publicConfig(array $config, string $tenantId, bool $enabled = false, bool $ready = false): array
    {
        $isProvider = ($config['provider'] ?? '') === self::PROVIDER;
        $status = $isProvider ? ($config['status'] ?? 'unconfigured') : (empty($config['provider']) ? 'unconfigured' : 'unsupported');
        return ['provider' => $config['provider'] ?? '', 'status' => $status,
            'operation' => CustomerLifecycleService::publicOperation($config),
            'enabled' => $enabled,
            'fallback_email' => $config['fallback_email'] ?? '',
            'merchant_id' => $isProvider ? ($config['merchant_id'] ?? '6mm_wl_'.$tenantId) : '6mm_wl_'.$tenantId,
            'tenant_id' => $isProvider ? ($config['tenant_id'] ?? null) : null,
            'app_key_configured' => $isProvider && !empty($config['app_key_encrypted']),
            'provisioning_ready' => $ready,
            'requested' => $isProvider ? (object) array_intersect_key((array) ($config['requested'] ?? []), array_flip(['name', 'seatLimit', 'serviceEndAt', 'adminName', 'adminEmail'])) : (object) [],
            'created' => $isProvider ? ($config['created'] ?? null) : null];
    }

    /** Flags belong to the default site, exactly like the business-config page. */
    public function moduleStates(array $tenantIds): array
    {
        if (!$tenantIds) return [];
        $sites = $this->table('wl_sites')->whereIn('tenant_id', $tenantIds)->groupBy('tenant_id')
            ->select('tenant_id')->selectRaw('MIN(site_id) AS site_id');
        $modules = $this->host->tableName('wl_site_modules');
        return $this->host->db->query()->fromSub($sites, 'site')->leftJoin($modules.' as module', function ($join): void {
            $join->on('module.site_id', '=', 'site.site_id')->where('module.module_code', self::MODULE)->where('module.module_type', 'business');
        })->get(['site.tenant_id', 'module.enabled'])->mapWithKeys(fn ($row) => [(string) $row->tenant_id => (bool) $row->enabled])->all();
    }

    public function bound(string $tenantId): bool
    {
        $json = $this->table('wl_channel_configs')->where('tenant_id', $tenantId)->value('customer_service');
        if (!$json) return false;
        $config = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (($config['provider'] ?? '') !== self::PROVIDER || ($config['status'] ?? '') !== 'bound'
            || empty($config['tenant_id']) || empty($config['app_key_encrypted'])) return false;
        try { return trim($this->host->decrypt($config['app_key_encrypted'])) !== ''; }
        catch (Throwable $e) { $this->reject('客服绑定凭据异常，请联系管理员修复'); }
    }

    private function table(string $name): Builder
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
        $tenant = $this->table('wl_tenants')->where('tenant_id', $id)->lockForUpdate()->first(['tenant_id', 'tenant_name']);
        if (!$tenant) $this->reject('白标不存在');
        $row = $this->table('wl_channel_configs')->where('tenant_id', $id)->lockForUpdate()->first(['customer_service', 'updated_at']);
        $config = $row ? json_decode($row->customer_service, true, 512, JSON_THROW_ON_ERROR) : [];
        return [$tenant, $row, $config];
    }

    private function persist(string $id, ?object $row, array $config, int $operatorId, string $phase): void
    {
        $now = \Carbon\CarbonImmutable::now();
        if ($row && $now->lessThanOrEqualTo(\Carbon\Carbon::parse($row->updated_at))) $now = \Carbon\Carbon::parse($row->updated_at)->addMicrosecond();
        $timestamp = $now->format('Y-m-d H:i:s.uP');
        $data = ['customer_service' => json_encode((object) $config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'updated_at' => $timestamp];
        $data += $this->host->operatorColumns($row === null);
        if ($row) $this->table('wl_channel_configs')->where('tenant_id', $id)->update($data);
        else $this->table('wl_channel_configs')->insert($data + ['tenant_id' => $id, 'created_at' => $timestamp]);
        $this->host->audit(['tenant_id' => $id, 'merchant_id' => $config['merchant_id'], 'phase' => $phase,
            'status' => $config['status'], 'customer_tenant_id' => $config['tenant_id'] ?? null], $timestamp, self::ACTION);
    }

    public function provision(array $input, int $operatorId): array
    {
        $this->host->assertActor($operatorId);
        $this->host->assertReady();
        $id = (string) $input['tenant_id'];
        $attempt = (string) Str::uuid();
        $prepared = $this->host->db->transaction(function () use ($input, $id, $attempt, $operatorId): array {
            [$tenant, $row, $config] = $this->locked($id);
            if (in_array($config['operation']['status'] ?? '', ['inflight', 'pending', 'confirmed'], true)) {
                $this->reject('客服业务操作尚未完成，请先在业务配置核验原操作');
            }
            $this->host->assertCanProvision();
            if (!empty($config['provider']) && $config['provider'] !== self::PROVIDER) $this->reject('已有其他客服配置，不能自动覆盖');
            if (($config['status'] ?? '') === 'bound') return ['bound' => true];
            if (!is_string($tenant->tenant_name) || trim($tenant->tenant_name) === '') $this->reject('请先完善白标名称再开通客服');
            if ($row?->updated_at !== $input['expected_updated_at']) $this->reject('配置已变化，请重新打开后再操作');
            if (($config['status'] ?? '') === 'provisioning' && \Carbon\Carbon::parse($config['started_at'])->greaterThan(\Carbon\CarbonImmutable::now()->subSeconds(40))) {
                $this->reject('客服开户正在处理中，请稍后查询或重试');
            }
            $uncertain = in_array($config['status'] ?? '', ['pending', 'provisioning'], true);
            $payload = ['merchantId' => $config['merchant_id'] ?? '6mm_wl_'.$id, 'name' => $uncertain ? $config['requested']['name'] : $tenant->tenant_name,
                'seatLimit' => (int) $input['seat_limit'], 'serviceEndAt' => $input['service_end_at'],
                'adminName' => $input['admin_name'], 'adminEmail' => $input['admin_email']];
            $fingerprint = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR)."\0".$input['admin_password'], $this->host->fingerprintKey);
            if (in_array($config['status'] ?? '', ['pending', 'provisioning'], true) && !hash_equals($config['request_fingerprint'] ?? '', $fingerprint)) {
                $this->reject('上次开户结果待确认，请使用原开户资料和原密码重试，不能更换商户 ID');
            }
            $config = array_replace($config, ['provider' => self::PROVIDER, 'status' => 'provisioning',
                'merchant_id' => $payload['merchantId'], 'requested' => $payload, 'request_fingerprint' => $fingerprint,
                'attempt_id' => $attempt, 'started_at' => \Carbon\CarbonImmutable::now()->toIso8601String()]);
            $this->persist($id, $row, $config, $operatorId, 'request');
            return ['bound' => false, 'payload' => $payload, 'uncertain' => $uncertain];
        }, 3);
        if ($prepared['bound']) return ['status' => 'bound', 'message' => '客服已绑定，无需重复开户', 'detail' => $this->host->channelDetail($id)];

        // The durable merchantId is saved before HTTP. Never hold DB locks over this network request.
        $status = 'pending';
        $message = '开户结果待确认，请使用原资料和原密码重试；系统将核验同一商户，不会更换商户 ID';
        $binding = null;
        try {
            $response = $this->host->http->asJson()->acceptJson()->withHeaders(['X-Exchange-Api-Key' => trim($this->host->apiKey)])
                ->timeout(25)->withOptions(['connect_timeout' => 5, 'allow_redirects' => false])->post(self::ENDPOINT,
                    $prepared['payload'] + ['adminPassword' => $input['admin_password']]);
            if ($response->status() === 200) {
                $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                $data = $body['data'] ?? null;
                if (($body['code'] ?? null) === 0 && is_array($data) && is_bool($data['created'] ?? null)
                    && (is_int($data['tenantId'] ?? null) || is_string($data['tenantId'] ?? null))
                    && preg_match('/^[1-9][0-9]{0,18}$/D', (string) $data['tenantId'])
                    && is_string($data['appKey'] ?? null) && trim($data['appKey']) !== '' && strlen($data['appKey']) <= 4096) {
                    $binding = ['tenant_id' => (string) $data['tenantId'], 'app_key_encrypted' => $this->host->encrypt($data['appKey']), 'created' => $data['created']];
                    $status = 'bound';
                    $message = $data['created'] ? '客服开户并绑定成功' : '已绑定既有客服租户；原名称、管理员、席位及到期时间未变更';
                }
            } elseif (in_array($response->status(), [400, 401, 409, 429], true)) {
                $status = 'failed';
                $message = match ($response->status()) {
                    400 => '开户资料未通过客服平台校验，请检查资料和服务到期时间',
                    401 => '客服开户密钥无效，请联系管理员检查服务端配置',
                    409 => '管理员邮箱已被使用，请更换邮箱',
                    429 => '客服平台请求限流，请稍后重试',
                };
                if ($prepared['uncertain']) {
                    $status = 'pending';
                    $message .= '；此前开户结果仍待确认，请继续使用原资料重试';
                }
            }
        } catch (Throwable $e) {
            // Do not log HTTP exceptions: request headers/body contain API key and initial password.
        }
        $this->host->db->transaction(function () use ($id, $attempt, $operatorId, $status, $binding): void {
            [, $row, $config] = $this->locked($id);
            if (($config['attempt_id'] ?? '') !== $attempt) $this->reject('开户状态已变化，请重新打开核验');
            $config['status'] = $status;
            if ($binding) {
                $config = array_replace($config, $binding);
                unset($config['request_fingerprint']);
            }
            $this->persist($id, $row, $config, $operatorId, 'result');
        }, 3);
        return ['status' => $status, 'message' => $message, 'detail' => $this->host->channelDetail($id)];
    }
}

