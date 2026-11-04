<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantCustomerService;

use Closure;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Validation\ValidationException;

/** Host-owned credentials, identity and transactional audit. No application models in the shared domain. */
final class CustomerServiceContext
{
    private Closure $audit;
    private Closure $canProvision;
    private Closure $canPublish;
    private ?Closure $channelDetail;

    public function __construct(
        public readonly ConnectionInterface $db,
        public readonly HttpFactory $http,
        public readonly ValidationFactory $validator,
        public readonly ?Encrypter $encrypter,
        public readonly string $apiKey,
        public readonly string $fingerprintKey,
        public readonly int $actorId,
        public readonly string $actorType,
        callable $audit,
        callable $canProvision,
        callable $canPublish,
        ?callable $channelDetail = null,
        public readonly ?string $schema = null,
    ) {
        if (!in_array($actorType, ['platform', 'white_label'], true) || $actorId < 0
            || ($schema !== null && !preg_match('/^[a-z_][a-z0-9_]*$/D', $schema))) {
            throw new \InvalidArgumentException('Invalid customer-service host context');
        }
        $this->audit = Closure::fromCallable($audit);
        $this->canProvision = Closure::fromCallable($canProvision);
        $this->canPublish = Closure::fromCallable($canPublish);
        $this->channelDetail = $channelDetail === null ? null : Closure::fromCallable($channelDetail);
    }

    public function table(string $name): Builder { return $this->db->table($this->tableName($name)); }
    public function tableName(string $name): string
    {
        $schema = $this->schema ?? ($this->db->getDriverName() === 'pgsql' ? 'control_plane' : '');
        return ($schema !== '' ? $schema.'.' : '').$name;
    }
    public function ready(): bool { return trim($this->apiKey) !== '' && $this->encrypter !== null; }
    public function reject(string $field, string $message): never
    {
        $validator = $this->validator->make([], []);
        $validator->errors()->add($field, $message);
        throw new ValidationException($validator);
    }
    public function assertReady(): void
    {
        if (!$this->ready()) $this->reject('customer_service', '客服服务未配置，请联系平台管理员配置客服 API 和共享凭据加密密钥');
    }
    public function assertActor(int $operatorId): void
    {
        if ($operatorId < 1 || $operatorId !== $this->actorId) throw new \InvalidArgumentException('Invalid customer-service operator');
    }
    public function assertCanProvision(): void
    {
        if (!(bool) ($this->canProvision)()) $this->reject('customer_service', '未开通客服账号，需要“开通并绑定客服”权限');
    }
    public function assertCanPublish(): void
    {
        if (!(bool) ($this->canPublish)()) $this->reject('customer_service', '没有发布业务配置权限');
    }
    public function audit(array $change, string $timestamp, string $action): void
    {
        $this->assertActor($this->actorId);
        ($this->audit)($change, $timestamp, $action);
    }
    public function actorReference(): string { return ($this->actorType === 'platform' ? 'platform-admin:' : 'white-label-admin:').$this->actorId; }
    public function operatorColumns(bool $insert): array
    {
        // updated_by is a platform-admin foreign key. White-label actors belong only in their own audit log.
        return $this->actorType === 'platform' ? ['updated_by' => $this->actorId] : ($insert ? ['updated_by' => null] : []);
    }
    public function encrypt(string $value): string { $this->assertReady(); return $this->encrypter->encryptString($value); }
    public function decrypt(string $value): string
    {
        if ($this->encrypter === null) $this->reject('customer_service', '客服凭据加密密钥未配置');
        return $this->encrypter->decryptString($value);
    }
    public function channelDetail(string $tenantId): array
    {
        if ($this->channelDetail === null) throw new \LogicException('Channel detail adapter is required for manual provisioning');
        return ($this->channelDetail)($tenantId);
    }
}
