<?php

declare(strict_types=1);

namespace SixMm\Shared\Hedging;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use SixMm\Shared\Contracts\UserDataScope;

final class HedgingSymbolConfigQueryService
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    /**
     * Lists persisted symbol configurations only. Market catalogue rows that have
     * never been configured are deliberately excluded from this shared query.
     *
     * @return array<string, mixed>
     */
    public function search(HedgingSymbolConfigQuery $criteria, UserDataScope $scope): array
    {
        $query = $this->connection->table('hedge_configs as config')
            ->leftJoin('exchange_accounts as account', function ($join): void {
                $join->on('account.id', '=', 'config.exchange_account_id')
                    ->whereNull('account.deleted_at');
            })
            ->whereNull('config.deleted_at')
            ->where('config.source', 'platform');
        $scope->apply($query, 'config.agent_id');

        if ($criteria->keyword() !== '') {
            $like = '%' . $this->escapeLike($criteria->keyword()) . '%';
            $query->where(static function (Builder $nested) use ($like): void {
                $nested->whereRaw('UPPER(config.symbol) LIKE ?', [$like])
                    ->orWhereRaw('UPPER(config.target_symbol) LIKE ?', [$like])
                    ->orWhereRaw('UPPER(account.name) LIKE ?', [$like]);
            });
        }
        if ($criteria->exchange() !== '') {
            $query->where('account.exchange', $criteria->exchange());
        }
        if ($criteria->accountId() !== null) {
            $query->where('config.exchange_account_id', $criteria->accountId());
        }
        if ($criteria->hedgeUnit() !== '') {
            $query->where('config.hedge_unit', $criteria->hedgeUnit());
        }
        if ($criteria->enabled() !== '') {
            $query->where('config.enabled', $criteria->enabled() === '1');
        }

        $total = (clone $query)->count();
        $rows = $query
            ->select([
                'config.id', 'config.agent_id', 'config.exchange_account_id',
                'config.symbol', 'config.target_symbol', 'config.target_hedge_ratio',
                'config.hedge_unit', 'config.first_trigger_usdt', 'config.rebalance_usdt',
                'config.exit_usdt', 'config.first_trigger_quantity',
                'config.rebalance_quantity', 'config.exit_quantity',
                'config.max_slippage_bps', 'config.enabled', 'config.lifecycle_status',
                'config.updated_at', 'account.name as account_name',
                'account.exchange', 'account.sandbox', 'account.status as account_status',
            ])
            ->orderBy('config.agent_id')
            ->orderBy('config.symbol')
            ->orderByDesc('config.updated_at')
            ->forPage($criteria->page(), $criteria->pageSize())
            ->get()
            ->map(fn (object $row): array => $this->mapRow($row))
            ->values()
            ->all();

        $optionQuery = $this->connection->table('hedge_configs as config')
            ->join('exchange_accounts as account', function ($join): void {
                $join->on('account.id', '=', 'config.exchange_account_id')
                    ->whereNull('account.deleted_at');
            })
            ->whereNull('config.deleted_at')
            ->where('config.source', 'platform');
        $scope->apply($optionQuery, 'config.agent_id');
        $options = $optionQuery->select([
            'account.id', 'account.name', 'account.exchange', 'account.sandbox',
            'account.is_primary',
        ])->distinct()->orderBy('account.exchange')->orderBy('account.name')->get();

        return [
            'count' => $total,
            'lists' => $rows,
            'options' => [
                'accounts' => $options->map(static fn (object $account): array => [
                    'id' => (int) $account->id,
                    'name' => (string) $account->name,
                    'exchange' => (string) $account->exchange,
                    'sandbox' => (bool) $account->sandbox,
                    'is_primary' => (bool) $account->is_primary,
                ])->all(),
                'exchanges' => $options->pluck('exchange')->filter()->unique()->sort()->values()->all(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function mapRow(object $row): array
    {
        return [
            'agent_id' => (int) $row->agent_id,
            'config_id' => (int) $row->id,
            'configured' => true,
            'symbol' => (string) $row->symbol,
            'target_symbol' => (string) $row->target_symbol,
            'account_id' => (int) $row->exchange_account_id,
            'account_name' => $row->account_name !== null ? (string) $row->account_name : null,
            'exchange' => $row->exchange !== null ? (string) $row->exchange : null,
            'sandbox' => (bool) $row->sandbox,
            'account_status' => $row->account_status !== null ? (string) $row->account_status : null,
            'target_hedge_ratio' => round(((float) $row->target_hedge_ratio) * 100, 4),
            'hedge_unit' => (string) ($row->hedge_unit ?: 'base'),
            'first_trigger_usdt' => (float) $row->first_trigger_usdt,
            'rebalance_usdt' => (float) $row->rebalance_usdt,
            'exit_usdt' => (float) $row->exit_usdt,
            'first_trigger_quantity' => (float) $row->first_trigger_quantity,
            'rebalance_quantity' => (float) $row->rebalance_quantity,
            'exit_quantity' => (float) $row->exit_quantity,
            'max_slippage_percent' => round(((int) $row->max_slippage_bps) / 100, 4),
            'enabled' => (bool) $row->enabled,
            'lifecycle_status' => (string) ($row->lifecycle_status ?: ((bool) $row->enabled ? 'active' : 'disabled')),
            'updated_at' => (string) $row->updated_at,
        ];
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
