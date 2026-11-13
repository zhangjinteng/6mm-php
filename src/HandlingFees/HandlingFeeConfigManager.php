<?php
declare(strict_types=1);

namespace SixMm\Shared\HandlingFees;

use Closure;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Throwable;

/** Host supplies an authenticated scope and transport; request scope fields are never consumed. */
final class HandlingFeeConfigManager
{
    private Closure $rpc;
    private Closure $commandId;
    private ScopedHandlingFeeConfigService $scoped;
    private ?HandlingFeeOperationStore $store;

    public function __construct(private ConnectionInterface $db, callable $rpc, ?callable $commandId = null, ?HandlingFeeOperationStore $operations = null)
    {
        $this->rpc = Closure::fromCallable($rpc);
        $this->commandId = Closure::fromCallable($commandId ?? static fn (): string => (string) random_int(1_000_000_000_000_000_000, 8_999_999_999_999_999_999));
        $this->scoped = new ScopedHandlingFeeConfigService($db);
        $this->store = $operations;
    }

    public function listing(int $site, int $agent, int $page = 1, int $size = 20): array
    {
        $rows = $this->scoped->query($site, $agent)->orderBy('level')->get()->map(fn ($r) => $this->row((array) $r))->all();
        foreach ($rows as &$row) $row['volume_30d_min'] = $row['level'] === 0 ? '0' : $row['volume_30d'];
        unset($row);
        $page = max(1, $page); $size = min(100, max(1, $size));
        $pending = $this->journal()->pending($site, $agent);
        return ['lists' => array_slice($rows, ($page - 1) * $size, $size), 'count' => count($rows),
            'page' => $page, 'limit' => $size, 'pending_operation' => $pending ? array_intersect_key($pending, array_flip(['id', 'request_id', 'action', 'error'])) : null,
            'scope' => ['home_site_id' => (string) $site, 'agent_id' => (string) $agent]];
    }

    public function detail(int $site, int $agent, int $id): array
    {
        $record = $this->scoped->query($site, $agent)->where('id', $id)->first();
        if (!$record) throw new DomainException('手续费等级不存在或不属于当前账户');
        return $this->row((array) $record);
    }

    public function nextLevel(int $site, int $agent): array
    {
        $this->scoped->query($site, $agent);
        // Include tombstones: a deleted VIP identity must not be silently reused.
        $max = $this->db->table('handling_fee_level_config')->where('home_site_id', $site)->where('agent_id', $agent)->max('level');
        $level = $max === null ? 0 : (int) $max + 1;
        $last = $this->effectiveLevels($site, $agent);
        return ['level' => $level, 'level_name' => $level === 0 ? 'VIP0' : 'VIP' . $level,
            'volume_30d_min' => $last === [] ? '0' : (string) end($last)['volume_30d']];
    }

    /** Effective per-level inheritance is also the source for scope-aware VIP thresholds. */
    public function effectiveLevels(int $site, int $agent): array
    {
        $this->scoped->query($site, $agent);
        $result = [];
        foreach (array_unique(['0:0', ...($site > 0 ? ["{$site}:0"] : []), ...($agent > 0 ? ["{$site}:{$agent}"] : [])]) as $scope) {
            [$s, $a] = array_map('intval', explode(':', $scope));
            foreach ($this->scoped->query($s, $a)->orderBy('level')->get() as $record) $result[(int) $record->level] = $this->row((array) $record);
        }
        ksort($result, SORT_NUMERIC);
        return array_values($result);
    }

    public function vipLevel(int $site, int $agent, string $volume): int
    {
        return self::levelForVolume($this->effectiveLevels($site, $agent), $volume);
    }

    public static function levelForVolume(array $tiers, string $volume): int
    {
        $level = 0;
        foreach ($tiers as $row) {
            if ((int) $row['level'] > 0 && bccomp($volume, $row['volume_30d'], 18) >= 0) $level = (int) $row['level'];
        }
        return $level;
    }

    public function mutate(int $site, int $agent, string $action, array $input, string $operator = ''): array
    {
        $this->scoped->query($site, $agent);
        if (!in_array($action, ['create', 'update', 'delete'], true)) throw new InvalidArgumentException('无效的手续费操作');
        $requestId = (string) ($input['request_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestId)) throw new InvalidArgumentException('缺少有效的操作编号，请重新打开弹窗');
        $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($id === false || ($action !== 'create' && $id === 0)) throw new InvalidArgumentException('无效的手续费等级');
        $values = $action === 'delete' ? [] : $this->values($input);
        $intent = hash('sha256', json_encode([$action, $id, $values], JSON_THROW_ON_ERROR));
        return $this->journal()->withScopeLock($site, $agent, function () use ($site, $agent, $action, $requestId, $id, $values, $intent, $operator): array {
            $existing = $this->journal()->byRequest($site, $agent, $requestId);
            if ($existing) {
                if ($existing['intent_hash'] !== $intent) throw new DomainException('操作编号已用于其他请求，请勿修改原请求后重试');
                return $this->execute($existing);
            }
            // A terminal receipt expires after seven days. Its old request must not become a new command.
            if (!preg_match('/^fee-([0-9]{13})-[A-Za-z0-9_-]{16,44}$/D', $requestId, $match)
                || (int) $match[1] < ($this->journal()->now() - HandlingFeeOperationStore::RETENTION_SECONDS) * 1000
                || (int) $match[1] > ($this->journal()->now() + 300) * 1000) {
                throw new InvalidArgumentException('操作编号已过期或无效，请重新打开弹窗');
            }
            if ($this->journal()->pending($site, $agent)) throw new DomainException('当前范围有结果待确认的手续费操作，请先重试原操作');
            $op = $this->db->transaction(function () use ($site, $agent, $action, $requestId, $id, $values, $intent, $operator): array {
                $this->scoped->lock();
                $before = $action === 'create' ? null : $this->detail($site, $agent, $id);
                $level = $before['level'] ?? $this->nextLevel($site, $agent)['level'];
                if ($action === 'delete' && $level === 0) throw new DomainException('普通会员等级不能删除');
                if ($action !== 'delete') $this->validateThreshold($site, $agent, $level, $values['volume_30d']);
                $version = (string) ($before['tk_version'] ?? '0');
                if ($version === '0') $version = $this->remoteVersion($site, $agent, $level);
                if ($action === 'delete' && bccomp($version, '0') <= 0) throw new DomainException('TK 中未找到当前层等级，请先核对同步状态');
                $payload = ['level' => $level, 'home_site_id' => $site === 0 ? '' : (string) $site,
                    'agent_id' => (string) $agent, 'command_id' => (string) ($this->commandId)(),
                    'version' => $action === 'delete' ? $version : (string) max((int) $version + 1, (int) (microtime(true) * 1_000_000))];
                if ($action !== 'delete') $payload += array_intersect_key($values, array_flip(['maker_fee_rate', 'taker_fee_rate']));
                $method = $action === 'delete' ? 'DeleteFeeLevelConfig' : 'BatchUpdateFeeLevelConfig';
                if ($action !== 'delete') $payload = ['items' => [$payload]];
                return ['id' => bin2hex(random_bytes(16)), 'home_site_id' => $site, 'agent_id' => $agent,
                    'request_id' => $requestId, 'action' => $action, 'level' => $level, 'intent_hash' => $intent,
                    'method' => $method, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'values' => json_encode($values, JSON_THROW_ON_ERROR), 'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                    'status' => 'prepared', 'operator' => $operator, 'created_at' => $this->now(), 'updated_at' => $this->now()];
            });
            // This write must succeed before any TK mutation is sent.
            return $this->execute($this->journal()->save($op));
        });
    }

    public function operation(int $site, int $agent, string $id): ?array
    {
        $this->scoped->query($site, $agent);
        return $this->journal()->get($site, $agent, $id);
    }

    /** Exact persisted retry, including pending operations older than seven days. */
    public function retry(int $site, int $agent, string $operationId): array
    {
        $this->scoped->query($site, $agent);
        return $this->journal()->withScopeLock($site, $agent, function () use ($site, $agent, $operationId): array {
            $op = $this->operation($site, $agent, $operationId);
            if (!$op) throw new DomainException('同步操作不存在、已过期或不属于当前账户');
            return $this->execute($op);
        });
    }

    public function vipPendingOperations(): array { return $this->journal()->vipPending(); }

    public function markVipRecalculated(array $operations): void
    {
        foreach ($operations as $snapshot) {
            $site = (int) $snapshot['home_site_id']; $agent = (int) $snapshot['agent_id'];
            $this->journal()->withScopeLock($site, $agent, function () use ($snapshot, $site, $agent): void {
                $op = $this->journal()->get($site, $agent, $snapshot['id']);
                if (!$op || $op['status'] !== 'applied') throw new DomainException('手续费重算操作记录缺失或状态异常');
                if (empty($op['vip_recalculated_at'])) {
                    // Complete linked RPC receipts only after the business/VIP work actually succeeded.
                    if (function_exists('app') && app()->bound(\SixMm\Shared\RpcTickets\WriteJournal::class)) {
                        app(\SixMm\Shared\RpcTickets\WriteJournal::class)->completeOperation($op['id']);
                    }
                    $op['vip_recalculated_at'] = $this->now();
                    $this->journal()->save($op);
                }
            });
        }
    }

    private function execute(array $op): array
    {
        if ($op['status'] === 'applied') return json_decode($op['result'], true, 512, JSON_THROW_ON_ERROR);
        if ($op['status'] === 'rejected') throw new DomainException($op['error']);
        $payload = json_decode($op['payload'], true, 512, JSON_THROW_ON_ERROR);
        try {
            if ($op['status'] === 'confirmed') {
                $response = json_decode($op['response'], true, 512, JSON_THROW_ON_ERROR);
            } else {
                $response = $this->normalize(\SixMm\Shared\RpcTickets\OperationContext::run($op['id'],
                    fn()=>($this->rpc)($op['method'], $payload)));
                if ($op['action'] !== 'delete') {
                    $items = $response['results'] ?? [];
                    $expected = $payload['items'][0];
                    if (count($items) !== 1 || (int) ($items[0]['level'] ?? -1) !== $expected['level']
                        || (string) ($items[0]['home_site_id'] ?? '') !== $expected['home_site_id']
                        || (string) ($items[0]['agent_id'] ?? 0) !== $expected['agent_id']) {
                        throw new DomainException('TK 手续费操作回执不完整或归属不符');
                    }
                    $item = $items[0];
                } else $item = $response;
                $code = (int) ($item['reject_code'] ?? 0);
                if ($code !== 0 && $code !== -1) {
                    $message = 'TK 拒绝手续费操作（' . $code . '）：' . ($item['message'] ?? $response['message'] ?? '请核对配置');
                    $op = $this->journal()->save(array_replace($op, ['status' => 'rejected', 'error' => $message,
                        'response' => json_encode($response, JSON_THROW_ON_ERROR)]));
                    throw new HandlingFeeRejected($message);
                }
                if ($code === -1) throw new DomainException('TK 返回系统错误，操作结果待确认');
                if ($op['action'] !== 'delete') FeeLevelBatch::confirm($payload, $response);
                // Keep the confirmed response before touching SQL. A retry can finish a failed SQL commit without another TK write.
                $op = $this->journal()->save(array_replace($op, ['status' => 'confirmed', 'response' => json_encode($response, JSON_THROW_ON_ERROR)]));
            }
            $result = $this->applyLocal($op, $payload);
            // Applied records stay without TTL while VIP recalculation is still outstanding.
            $this->journal()->save(array_replace($op, ['status' => 'applied', 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'error' => null]));
            return $result;
        } catch (HandlingFeeRejected $e) { throw $e; }
        catch (Throwable $e) {
            try {
                $current = $this->journal()->get((int) $op['home_site_id'], (int) $op['agent_id'], $op['id']);
                if ($current && !in_array($current['status'], ['applied', 'rejected'], true)) {
                    $this->journal()->save(array_replace($current, ['status' => $current['status'] === 'confirmed' ? 'confirmed' : 'unknown',
                        'error' => mb_substr($e->getMessage(), 0, 2000)]));
                }
            } catch (Throwable) { /* The already persisted original command must never be replaced. */ }
            throw new DomainException('手续费操作结果待确认，请使用原操作重试；不会生成新命令。' . $e->getMessage(), 0, $e);
        }
    }

    private function applyLocal(array $op, array $payload): array
    {
        return $this->db->transaction(function () use ($op, $payload): array {
            $this->scoped->lock();
            $values = json_decode($op['values'], true, 512, JSON_THROW_ON_ERROR);
            $before = $op['before'] === null ? null : json_decode($op['before'], true, 512, JSON_THROW_ON_ERROR);
            $site = (int) $op['home_site_id']; $agent = (int) $op['agent_id'];
            $version = (string) ($payload['items'][0]['version'] ?? $payload['version']);
            $current = $this->scoped->query($site, $agent)->where('level', $op['level'])->first();
            if ($op['action'] === 'delete') {
                $deleted = $this->db->table('handling_fee_level_config')->where('home_site_id', $site)->where('agent_id', $agent)
                    ->where('id', $before['id'])->first();
                if ($deleted && $deleted->deleted_at !== null && (string) $deleted->tk_version === $version
                    && $this->fingerprint((array) $deleted) === $this->fingerprint($before)) {
                    return ['id' => $before['id'], 'level' => (int) $op['level'], 'deleted' => true];
                }
            } elseif ($current && (string) ($current->tk_version ?? '') === $version
                && ($before === null || (int) $current->id === $before['id'])
                && $this->fingerprint((array) $current) === $this->fingerprint($values + ['level' => $op['level']])) {
                return $this->detail($site, $agent, (int) $current->id);
            }
            if (($before === null && $current !== null) || ($before !== null && (!$current || (int) $current->id !== $before['id']
                || (string) ($current->tk_version ?? '0') !== (string) ($before['tk_version'] ?? '0')
                || $this->fingerprint((array) $current) !== $this->fingerprint($before)))) {
                throw new DomainException('TK 已确认，但本地等级发生变化，需要核对后重试');
            }
            if ($op['action'] === 'delete') {
                $this->scoped->query($site, $agent)->where('id', $before['id'])->update([
                    'deleted_at' => $this->now(), 'updated_at' => $this->now(), 'tk_version' => $version]);
                return ['id' => $before['id'], 'level' => (int) $op['level'], 'deleted' => true];
            }
            $values += ['home_site_id' => $site, 'agent_id' => $agent, 'level' => $op['level'],
                'level_name' => $before['level_name'] ?? ('VIP' . $op['level']), 'tk_version' => $version, 'updated_at' => $this->now()];
            if ($before === null) $id = $this->db->table('handling_fee_level_config')->insertGetId($values + ['created_at' => $this->now()]);
            else {
                $id = $before['id'];
                $this->scoped->query($site, $agent)->where('id', $id)->update($values);
            }
            return $this->detail($site, $agent, (int) $id);
        });
    }

    private function remoteVersion(int $site, int $agent, int $level): string
    {
        $response = $this->normalize(($this->rpc)('GetFeeLevelConfig', ['level' => $level, 'all' => false,
            'home_site_id' => $site === 0 ? '' : (string) $site, 'agent_id' => (string) $agent, 'include_inherited' => false]));
        $configs = $response['configs'] ?? [];
        if (!is_array($configs) || count($configs) > 1 || (int) ($response['total_count'] ?? count($configs)) !== count($configs)) {
            throw new DomainException('TK 手续费查询结果不完整');
        }
        if ($configs === []) return '0';
        $config = $configs[0];
        if ((int) ($config['level'] ?? 0) !== $level || (int) ($config['home_site_id'] ?? 0) !== $site
            || (int) ($config['agent_id'] ?? 0) !== $agent || ($config['fallback'] ?? false) === true
            || !preg_match('/^[1-9][0-9]*$/D', (string) ($config['version'] ?? ''))) {
            throw new DomainException('TK 未返回当前层的有效版本，不能使用继承版本修改');
        }
        return (string) $config['version'];
    }

    private function validateThreshold(int $site, int $agent, int $level, string $value): void
    {
        foreach ($this->effectiveLevels($site, $agent) as $row) {
            $cmp = bccomp($value, $row['volume_30d'], 18);
            // Level 0 stores the ordinary-member upper boundary; VIP1 stores its
            // own lower boundary, so equal thresholds for 0/1 are legitimate.
            if ($cmp === 0 && (($level === 0 && $row['level'] === 1) || ($level === 1 && $row['level'] === 0))) continue;
            if (($row['level'] < $level && $cmp <= 0) || ($row['level'] > $level && $cmp >= 0)) {
                throw new DomainException('成交量门槛必须按 VIP 等级严格递增，不会自动重排等级');
            }
        }
    }

    private function values(array $input): array
    {
        $values = [];
        foreach (['volume_30d', 'maker_fee_rate', 'taker_fee_rate'] as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            if (!preg_match('/^\d{1,20}(?:\.\d{1,18})?$/D', $value)) throw new InvalidArgumentException('门槛和手续费率必须为非负十进制数');
            if ($field !== 'volume_30d' && bccomp($value, '1', 18) > 0) throw new InvalidArgumentException('手续费率不能超过 100%');
            $values[$field] = bcadd($value, '0', 18);
        }
        return $values;
    }

    private function fingerprint(array $row): string
    {
        return hash('sha256', json_encode(array_map(static fn ($field): string => bcadd((string) ($row[$field] ?? '0'), '0', 18),
            ['level', 'volume_30d', 'maker_fee_rate', 'taker_fee_rate']), JSON_THROW_ON_ERROR));
    }
    private function row(array $row): array
    {
        $allowed = ['id', 'home_site_id', 'agent_id', 'level', 'level_name', 'volume_30d', 'maker_fee_rate', 'taker_fee_rate', 'tk_version', 'updated_at'];
        $row = array_intersect_key($row, array_flip($allowed));
        foreach (['id', 'level'] as $key) $row[$key] = (int) $row[$key];
        foreach (['home_site_id', 'agent_id', 'volume_30d', 'maker_fee_rate', 'taker_fee_rate'] as $key) $row[$key] = (string) $row[$key];
        return $row;
    }
    private function journal(): HandlingFeeOperationStore
    {
        return $this->store ??= app(HandlingFeeOperationStore::class);
    }
    private function normalize(array $input): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            $key = is_string($key) ? strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key)) : $key;
            $result[$key] = is_array($value) ? $this->normalize($value) : $value;
        }
        return $result;
    }
    private function now(): string { return gmdate('Y-m-d H:i:s', $this->journal()->now()); }
}

final class HandlingFeeRejected extends DomainException {}
