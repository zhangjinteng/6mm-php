<?php

declare(strict_types=1);

namespace SixMm\Shared\SymbolConfigs;

use Closure;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Throwable;

final class SymbolTradingLifecycle
{
    public const BUSY = '该交易对正在下架处理中，暂不支持此操作，请稍后重试。';
    public const UNCONFIRMED = '暂时无法确认交易对状态，请稍后重试';
    private Closure $rpc;
    private Closure $command;

    public function __construct(private ConnectionInterface $db, callable $rpc, callable $command)
    {
        $this->rpc = Closure::fromCallable($rpc);
        $this->command = Closure::fromCallable($command);
    }

    public function execute(int $site, int $agent, int $id, string $action): array
    {
        $methods = ['list'=>'ListSymbolForTrading', 'pause'=>'PauseSymbolTrading',
            'resume'=>'ResumeSymbolTrading', 'delist'=>'DelistSymbolTrading'];
        if (!isset($methods[$action]) || $site < 0 || $agent < 0 || ($site === 0 && $agent !== 0)) {
            throw new DomainException('请求参数有误');
        }

        return $this->db->transaction(function () use ($site, $agent, $id, $action, $methods): array {
            $configs = new ScopedSymbolConfigService($this->db);
            // Same lock as configuration edits and scope initialization in both hosts.
            $configs->lock();
            $row = $configs->query($site, $agent)->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
            if (!$row) throw new DomainException('合约不存在或不属于当前账户');
            $symbol = (string) $row->symbol;
            $scope = self::scope($site, $agent);
            [$current, $version] = $this->snapshot($scope, $symbol);

            if ($site > 0) {
                $root = $configs->query(0)->where('symbol', $symbol)->whereNull('deleted_at')->first();
                $parent = $agent > 0 ? $configs->effectiveQuery($site)->where('base.symbol', $symbol)->first() : null;
                if (!$root || (int) $root->is_active !== 1 || !$root->is_visible
                    || ($agent > 0 && (!$parent || (int) $parent->is_active !== 1 || !$parent->is_visible))) {
                    throw new DomainException('上级配置已关闭，暂不支持此操作');
                }
            }

            $state = self::enum($current['state'] ?? null, 'SYMBOL_TRADING_STATE_', ['LISTED'=>1,'PAUSED'=>2,'DELISTING'=>3,'DELISTED'=>4]);
            if ($action === 'delist' && $state !== 2) throw new DomainException('请先暂停交易，再下架交易对');
            if ($action === 'pause' && $state !== 1) throw new DomainException('只有允许交易的交易对才能暂停');
            if ($action === 'resume' && $state !== 2) throw new DomainException('只有已暂停的交易对才能恢复交易');
            if ($action === 'list' && $state !== 4) throw new DomainException('该交易对尚未下架，请使用恢复交易');

            $method = $action === 'delist' && $site === 0 ? 'DelistAndSettleSymbol' : $methods[$action];
            $key = hash('sha256', json_encode([$scope, $symbol, $version, $action], JSON_THROW_ON_ERROR));
            $command = (string) ($this->command)($key);
            if (!ctype_digit($command) || $command === '0') throw new DomainException('操作编号生成失败，请稍后重试');
            $request = ['command_id'=>$command, 'symbol'=>$symbol, 'reason'=>'后台交易对操作：'.$action];
            if ($method !== 'DelistAndSettleSymbol') $request += ['scope'=>$scope, 'expected_version'=>$version];
            // Price evidence is intentionally omitted under the updated upstream contract.
            $response = $this->call($method, $request);
            $this->receipt($response, $request, $action, $method);

            $query = $this->db->table('symbol_config')->where('symbol', $symbol)->whereNull('deleted_at');
            if ($site > 0) $query->where('home_site_id', $site);
            if ($agent > 0 || in_array($action, ['list','resume'], true)) $query->where('agent_id', $agent);
            if ($site === 0 && in_array($action, ['list','resume'], true)) $query->where('home_site_id', 0);
            $values = ['is_visible'=>in_array($action, ['list','resume'], true),
                'revision'=>$this->db->raw('revision + 1'), 'updated_at'=>date('Y-m-d H:i:s')];
            if ($action === 'list' || $action === 'delist') $values['is_active'] = $action === 'list' ? 1 : 0;
            $query->update($values);
            $saved = $configs->effectiveQuery($site, $agent)->where('sc.id', $id)->first();
            return ['message'=>$action === 'delist' ? '下架申请已提交' : ['list'=>'上架成功','pause'=>'暂停交易成功','resume'=>'恢复交易成功'][$action],
                'command_id'=>$command, 'symbol'=>$symbol, 'listing_status'=>(int) $saved->is_active,
                'trading_status'=>(int) $saved->is_visible];
        });
    }

    private function snapshot(array $scope, string $symbol): array
    {
        try {
            $result = $this->call('ListSymbolTradingConfigs', ['scope'=>$scope, 'symbols'=>[$symbol], 'limit'=>1]);
            if (!is_array($result['configs'] ?? null) || count($result['configs']) !== 1
                || ($result['configs'][0]['symbol'] ?? '') !== $symbol
                || self::enum($result['mode'] ?? null, 'SYMBOL_TRADING_MODE_', ['WHITELIST'=>1,'LEGACY'=>2]) === 0) {
                throw new DomainException(self::UNCONFIRMED);
            }
            $config = $result['configs'][0];
            $state = self::enum($config['state'] ?? null, 'SYMBOL_TRADING_STATE_', ['LISTED'=>1,'PAUSED'=>2,'DELISTING'=>3,'DELISTED'=>4]);
            $effective = self::enum($config['trading_access']['effective_state'] ?? null, 'SYMBOL_TRADING_STATE_', ['LISTED'=>1,'PAUSED'=>2,'DELISTING'=>3,'DELISTED'=>4]);
            $version = (string) ($result['scope_version'] ?? '0');
            if ($state === 0 || $effective === 0 || !ctype_digit($version)) throw new DomainException(self::UNCONFIRMED);
        } catch (Throwable $e) {
            throw new DomainException(self::UNCONFIRMED, 0, $e);
        }
        if ($state === 3 || $effective === 3) throw new DomainException(self::BUSY);
        return [$config, $version];
    }

    private function receipt(array $response, array $request, string $action, string $method): void
    {
        if ($method === 'DelistAndSettleSymbol') {
            if (($response['status'] ?? '') === 'ACCEPTED' && (string) ($response['command_id'] ?? '') === $request['command_id']
                && ($response['symbol'] ?? '') === $request['symbol']) return;
        } else {
            $op = $response['operation'] ?? [];
            $actions = ['list'=>2,'pause'=>3,'resume'=>4,'delist'=>5];
            $kind = self::enum($op['scope']['kind'] ?? null, 'SYMBOL_TRADING_SCOPE_', ['PLATFORM'=>1,'SITE'=>2,'AGENT'=>3]);
            $scope = $request['scope'];
            $sameScope = $kind === $scope['kind'] && (string) ($op['scope']['home_site_id'] ?? '') === ($scope['home_site_id'] ?? '')
                && (string) ($op['scope']['agent_id'] ?? '0') === ($scope['agent_id'] ?? '0');
            $state = self::enum($op['state'] ?? null, 'SYMBOL_TRADING_OPERATION_', ['IN_PROGRESS'=>1,'COMPLETED'=>2]);
            $type = self::enum($op['action'] ?? null, 'SYMBOL_TRADING_ACTION_', ['INITIALIZE'=>1,'LIST'=>2,'PAUSE'=>3,'RESUME'=>4,'DELIST'=>5]);
            if ($sameScope && (string) ($op['command_id'] ?? '') === $request['command_id'] && ($op['symbol'] ?? '') === $request['symbol']
                && $type === $actions[$action] && ($state === 2 || ($action === 'delist' && $state === 1))) return;
        }
        throw new DomainException('交易服务未明确确认操作结果，请稍后重试');
    }

    private function call(string $method, array $request): array
    {
        $response = self::normalize(($this->rpc)($method, $request));
        // Protobuf omits the successful zero reject_code in JSON.
        if ((int) ($response['reject_code'] ?? 0) !== 0) throw new DomainException((string) ($response['message'] ?? '交易服务拒绝操作'));
        return $response;
    }

    private static function normalize(array $input): array
    {
        $result = [];
        foreach ($input as $key=>$value) {
            $key = is_string($key) ? strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key)) : $key;
            $result[$key] = is_array($value) ? self::normalize($value) : $value;
        }
        return $result;
    }

    private static function enum($value, string $prefix, array $names): int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) return in_array((int) $value, $names, true) ? (int) $value : 0;
        return is_string($value) && str_starts_with($value, $prefix) ? ($names[substr($value, strlen($prefix))] ?? 0) : 0;
    }

    public static function scope(int $site, int $agent): array
    {
        if ($site === 0) return ['kind'=>1];
        if ($agent === 0) return ['kind'=>2, 'home_site_id'=>(string) $site];
        return ['kind'=>3, 'home_site_id'=>(string) $site, 'agent_id'=>(string) $agent];
    }
}
