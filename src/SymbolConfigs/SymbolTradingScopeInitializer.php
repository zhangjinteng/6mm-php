<?php
declare(strict_types=1);

namespace SixMm\Shared\SymbolConfigs;

use Closure;
use DomainException;
use Throwable;

/** Explicit business activation only; never called by local skeleton initialization. */
final class SymbolTradingScopeInitializer
{
    private Closure $rpc;
    private Closure $journal;

    public function __construct(callable $rpc, callable $journal)
    {
        $this->rpc = Closure::fromCallable($rpc);
        $this->journal = Closure::fromCallable($journal);
    }

    public function initialize(int $site, int $agent = 0): array
    {
        if ($site <= 0 || $agent < 0) throw new DomainException('经营范围参数无效');
        $scope = SymbolTradingLifecycle::scope($site, $agent);
        $current = $this->snapshot($scope);
        if ($current['mode'] !== 0) return $current + ['initialized'=>false];

        // Persist the entire payload before sending: retries must not rebuild the parent's selection.
        $request = ($this->journal)(hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR)), function () use ($scope, $site, $agent): array {
            $parent = $this->snapshot(SymbolTradingLifecycle::scope($agent > 0 ? $site : 0, 0));
            if ($parent['mode'] === 0) throw new DomainException('上级经营范围尚未登记，请先登记上级范围');
            $symbols = [];
            foreach ($parent['configs'] as $config) {
                if (($config['selected'] ?? false) === true && $this->state($config['state'] ?? 0) === 1
                    && $this->state($config['trading_access']['effective_state'] ?? 0) === 1
                    && ($config['trading_access']['can_open'] ?? false) === true) $symbols[] = $config['symbol'];
            }
            sort($symbols, SORT_STRING);
            return ['command_id'=>(string) random_int(1, PHP_INT_MAX), 'scope'=>$scope,
                'expected_version'=>'0', 'mode'=>1, 'initial_symbols'=>$symbols, 'reason'=>'首次开通合约经营范围'];
        });
        if (!is_array($request) || ($request['scope'] ?? null) !== $scope || ($request['mode'] ?? null) !== 1
            || ($request['expected_version'] ?? null) !== '0' || !ctype_digit((string) ($request['command_id'] ?? ''))
            || (string) $request['command_id'] === '0' || !is_array($request['initial_symbols'] ?? null)) {
            throw new DomainException('经营范围初始化记录异常，请联系管理员');
        }
        try {
            $response = $this->normalize(($this->rpc)('InitializeSymbolTradingScope', $request));
        } catch (Throwable $e) {
            $response = $this->confirmation($request, $e);
        }
        $this->accepted($response);
        if (!$this->completed($response['operation'] ?? [], $request)) {
            $response = $this->confirmation($request);
        }
        if (!$this->completed($response['operation'] ?? [], $request)) {
            throw new DomainException('TK 经营范围初始化结果待确认，请稍后重试');
        }
        $current = $this->snapshot($scope);
        if ($current['mode'] !== 1 || (int) $current['scope_version'] < (int) $response['operation']['applied_version']) {
            throw new DomainException('TK 经营范围初始化结果待确认，请稍后重试');
        }
        return $current + ['initialized'=>true];
    }

    private function confirmation(array $request, ?Throwable $previous = null): array
    {
        try {
            $response = $this->call('GetSymbolTradingOperation', ['command_id'=>$request['command_id']]);
            if (($response['found'] ?? false) === true && $this->completed($response['operation'] ?? [], $request)) return $response;
        } catch (Throwable $e) { $previous = $e; }
        throw new DomainException('TK 经营范围初始化结果待确认，请稍后重试；请勿更换命令号', 0, $previous);
    }

    private function completed(array $operation, array $request): bool
    {
        $scope = $operation['scope'] ?? [];
        return (string) ($operation['command_id'] ?? '') === $request['command_id']
            && $this->enum($scope['kind'] ?? 0, 'SYMBOL_TRADING_SCOPE_', ['PLATFORM'=>1,'SITE'=>2,'AGENT'=>3]) === $request['scope']['kind']
            && (string) ($scope['home_site_id'] ?? '') === $request['scope']['home_site_id']
            && (string) ($scope['agent_id'] ?? '0') === ($request['scope']['agent_id'] ?? '0')
            && $this->enum($operation['action'] ?? 0, 'SYMBOL_TRADING_ACTION_', ['INITIALIZE'=>1]) === 1
            && $this->enum($operation['state'] ?? 0, 'SYMBOL_TRADING_OPERATION_', ['COMPLETED'=>2]) === 2
            && ctype_digit((string) ($operation['applied_version'] ?? '')) && (int) $operation['applied_version'] > 0;
    }

    private function snapshot(array $scope): array
    {
        $cursor = ''; $seen = []; $configs = []; $version = null; $mode = null;
        for ($page = 0; $page < 1000; ++$page) {
            $result = $this->call('ListSymbolTradingConfigs', ['scope'=>$scope, 'limit'=>200, 'cursor'=>$cursor]);
            $nextMode = $this->enum($result['mode'] ?? 0, 'SYMBOL_TRADING_MODE_', ['UNSPECIFIED'=>0,'WHITELIST'=>1,'LEGACY'=>2]);
            $nextVersion = (string) ($result['scope_version'] ?? '0');
            if ($nextMode < 0 || !preg_match('/^(0|[1-9][0-9]*)$/D', $nextVersion) || (($nextMode === 0) !== ($nextVersion === '0'))
                || ($version !== null && ($version !== $nextVersion || $mode !== $nextMode))
                || !is_array($result['configs'] ?? [])) throw new DomainException('暂时无法确认经营范围状态，请稍后重试');
            $mode = $nextMode; $version = $nextVersion;
            foreach ($result['configs'] ?? [] as $config) {
                $symbol = $config['symbol'] ?? '';
                if (!is_string($symbol) || $symbol === '' || isset($configs[$symbol]) || $this->state($config['state'] ?? 0) < 0) {
                    throw new DomainException('TK 经营范围交易对数据异常');
                }
                $configs[$symbol] = $config;
            }
            $cursor = (string) ($result['next_cursor'] ?? '');
            if ($cursor === '') {
                if ($mode === 0 && $configs !== []) throw new DomainException('TK 未登记范围返回了异常配置');
                return ['mode'=>$mode, 'scope_version'=>$version, 'configs'=>array_values($configs)];
            }
            if (isset($seen[$cursor])) break;
            $seen[$cursor] = true;
        }
        throw new DomainException('TK 范围分页未完整读取，请稍后重试');
    }

    private function call(string $method, array $request): array
    {
        $response = $this->normalize(($this->rpc)($method, $request));
        $this->accepted($response);
        return $response;
    }
    private function accepted(array $response): void
    {
        if ((int) ($response['reject_code'] ?? 0) !== 0) throw new DomainException((string) ($response['message'] ?? 'TK 拒绝经营范围初始化'));
    }
    private function state($value): int { return $this->enum($value, 'SYMBOL_TRADING_STATE_', ['UNSPECIFIED'=>0,'LISTED'=>1,'PAUSED'=>2,'DELISTING'=>3,'DELISTED'=>4]); }
    private function enum($value, string $prefix, array $values): int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) return in_array((int)$value, $values, true) ? (int)$value : -1;
        return is_string($value) && str_starts_with($value, $prefix) ? ($values[substr($value, strlen($prefix))] ?? -1) : -1;
    }
    private function normalize(array $input): array
    {
        $result = [];
        foreach ($input as $key=>$value) {
            $key = is_string($key) ? strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key)) : $key;
            $result[$key] = is_array($value) ? $this->normalize($value) : $value;
        }
        return $result;
    }
}
