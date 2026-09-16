<?php

declare(strict_types=1);

namespace SixMm\Shared\Hedging;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use SixMm\Shared\Contracts\UserDataScope;

final class HedgingSubjectConfigQueryService
{
    public const STATUS_LABELS = [
        'running' => '运行中',
        'disabled' => '已停用',
        'pending_config' => '待配置',
        'account_abnormal' => '账户异常',
    ];

    public function __construct(private ConnectionInterface $connection)
    {
    }

    /** @return array<string, mixed> */
    public function search(HedgingSubjectConfigQuery $criteria, UserDataScope $scope): array
    {
        $query = $this->connection->table('hedging_settings as setting');
        $scope->apply($query, 'setting.agent_id');
        if ($criteria->enabled() !== '') {
            $query->where('setting.enabled', $criteria->enabled() === '1');
        }

        $settings = $query->select(['setting.agent_id', 'setting.enabled', 'setting.updated_at'])
            ->orderBy('setting.agent_id')
            ->get();
        $agentIds = $settings->pluck('agent_id')->map(static fn ($id): int => (int) $id)->all();
        $accounts = $this->accounts($agentIds)->groupBy('agent_id');
        $configs = $this->configs($agentIds)->groupBy('agent_id');

        $rows = $settings->map(fn (object $setting): array => $this->mapRow(
            $setting,
            $accounts->get($setting->agent_id, collect()),
            $configs->get($setting->agent_id, collect())
        ));
        if ($criteria->status() !== '') {
            $rows = $rows->where('status', $criteria->status());
        }

        $total = $rows->count();
        $rows = $rows->slice(($criteria->page() - 1) * $criteria->pageSize(), $criteria->pageSize())->values()->all();

        return ['count' => $total, 'lists' => $rows];
    }

    private function accounts(array $agentIds): Collection
    {
        if ($agentIds === []) return collect();

        return $this->connection->table('exchange_accounts')
            ->whereIn('agent_id', $agentIds)
            ->whereNull('deleted_at')
            ->get(['id', 'agent_id', 'exchange', 'name', 'status', 'metadata', 'updated_at']);
    }

    private function configs(array $agentIds): Collection
    {
        if ($agentIds === []) return collect();

        return $this->connection->table('hedge_configs')
            ->whereIn('agent_id', $agentIds)
            ->where('source', 'platform')
            ->where('enabled', true)
            ->whereNull('deleted_at')
            ->get(['agent_id', 'exchange_account_id', 'symbol', 'updated_at']);
    }

    /** @return array<string, mixed> */
    private function mapRow(object $setting, Collection $accounts, Collection $configs): array
    {
        $accountMap = $accounts->keyBy('id');
        $executionAccountIds = $configs->pluck('exchange_account_id')->map(static fn ($id): int => (int) $id)->unique();
        $executionAccounts = $executionAccountIds->map(fn (int $id) => $accountMap->get($id))->filter()->values();
        $connectedCount = $accounts->filter(fn (object $account): bool =>
            (string) $account->status === 'active' && $this->connectionStatus($account->metadata) === 'connected'
        )->count();
        $enabled = (bool) $setting->enabled;
        $status = !$enabled
            ? 'disabled'
            : ($configs->isEmpty()
                ? 'pending_config'
                : ($executionAccounts->count() !== $executionAccountIds->count()
                    || $executionAccounts->contains(fn (object $account): bool =>
                        (string) $account->status !== 'active' || $this->connectionStatus($account->metadata) !== 'connected'
                    ) ? 'account_abnormal' : 'running'));

        $updatedAt = collect([$setting->updated_at])
            ->merge($accounts->pluck('updated_at'))
            ->merge($configs->pluck('updated_at'))
            ->filter()
            ->max();

        return [
            'agent_id' => (int) $setting->agent_id,
            'global_enabled' => $enabled,
            'exchanges' => $executionAccounts->pluck('exchange')->filter()->unique()->values()->all(),
            'accounts' => $executionAccounts->map(static fn (object $account): array => [
                'id' => (int) $account->id,
                'exchange' => (string) $account->exchange,
                'name' => (string) $account->name,
            ])->all(),
            'connected_account_count' => $connectedCount,
            'enabled_symbol_count' => $configs->pluck('symbol')->filter()->unique()->count(),
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status],
            'updated_at' => $updatedAt !== null ? (string) $updatedAt : null,
        ];
    }

    private function connectionStatus(mixed $metadata): string
    {
        if (is_string($metadata)) $metadata = json_decode($metadata, true);
        if (is_object($metadata)) $metadata = (array) $metadata;

        return strtolower((string) (($metadata['connection_status'] ?? 'unchecked')));
    }
}
