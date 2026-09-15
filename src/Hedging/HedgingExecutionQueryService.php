<?php

declare(strict_types=1);

namespace SixMm\Shared\Hedging;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use SixMm\Shared\Contracts\UserDataScope;

final class HedgingExecutionQueryService
{
    public const REASONS = [
        'first_trigger',
        'rebalance',
        'exit_hedge',
        'manual_close',
        'position_flip_close',
        'hedge_ratio_adjustment',
    ];

    public const SIDES = ['BUY', 'SELL'];

    public const STATUSES = [
        'planned',
        'skipped',
        'submitted',
        'filled',
        'failed',
        'dry_run',
    ];

    private const LEGACY_REASON_ALIASES = [
        'net exposure reached first trigger threshold' => 'first_trigger',
        'hedge position drift reached rebalance check' => 'rebalance',
        'net exposure is below exit threshold' => 'exit_hedge',
    ];

    public function __construct(private ConnectionInterface $connection)
    {
    }

    /** @return array<string, mixed> */
    public function search(HedgingExecutionQuery $criteria, UserDataScope $scope): array
    {
        $scoped = $this->baseQuery();
        $scope->apply($scoped, 'plan.agent_id');

        $query = clone $scoped;
        if ($criteria->keyword() !== '') {
            $query->whereRaw('LOWER(plan.idempotency_key) LIKE ?', [
                '%' . $this->escapeLike($criteria->keyword()) . '%',
            ]);
        }
        if ($criteria->symbol() !== '') {
            $query->where('plan.symbol', $criteria->symbol());
        }
        if ($criteria->side() !== '') {
            $query->where('plan.side', $criteria->side());
        }
        if ($criteria->reason() !== '') {
            $query->whereIn('plan.reason', $this->reasonDatabaseValues($criteria->reason()));
        }
        if ($criteria->accountId() !== null) {
            $query->where('plan.exchange_account_id', $criteria->accountId());
        }

        $total = (clone $query)->count();
        $rows = $query
            ->select([
                'plan.id',
                'plan.agent_id',
                'plan.idempotency_key',
                'plan.symbol as target_symbol',
                'config.symbol as config_symbol',
                'plan.side',
                'plan.reason',
                'plan.notional_usdt',
                'plan.exchange_account_id',
                'plan.exchange',
                'plan.account_name',
                'plan.planned_at',
                'plan.created_at as plan_created_at',
                'execution.status',
                'execution.error_message',
                'execution.filled_quantity',
                'execution.avg_price',
                'execution.submitted_at',
                'execution.filled_at',
                'execution.failed_at',
                'execution.created_at as execution_created_at',
                'execution.updated_at as execution_updated_at',
            ])
            ->selectRaw('(execution.filled_quantity * execution.avg_price) as filled_notional_usdt')
            ->orderByDesc('plan.created_at')
            ->orderByDesc('plan.id')
            ->forPage($criteria->page(), $criteria->pageSize())
            ->get()
            ->map(fn (object $row): array => $this->mapRow($row))
            ->values()
            ->all();

        return [
            'count' => $total,
            'lists' => $rows,
            'options' => [
                'symbols' => $this->symbolOptions(clone $scoped),
                'directions' => $this->valueOptions(self::SIDES),
                'reasons' => $this->valueOptions(self::REASONS),
                'accounts' => $this->accountOptions(clone $scoped),
            ],
        ];
    }

    private function baseQuery(): Builder
    {
        return $this->connection->table('order_plans as plan')
            ->join('order_executions as execution', 'execution.order_plan_id', '=', 'plan.id')
            ->leftJoin('hedge_configs as config', 'config.id', '=', 'plan.config_id');
    }

    /** @return array<string, mixed> */
    private function mapRow(object $row): array
    {
        $reason = $this->canonicalReason((string) $row->reason);
        $status = (string) $row->status;

        return [
            'id' => (int) $row->id,
            'agent_id' => (int) $row->agent_id,
            'task_no' => (string) $row->idempotency_key,
            'symbol' => $this->displaySymbol((string) ($row->config_symbol ?: $row->target_symbol)),
            'target_symbol' => (string) $row->target_symbol,
            'side' => (string) $row->side,
            'side_label' => (string) $row->side,
            'reason' => $reason,
            'reason_label' => $reason,
            'notional_usdt' => $this->normalizeDecimal($row->notional_usdt),
            'filled_notional_usdt' => $this->normalizeDecimal($row->filled_notional_usdt),
            'exchange_account_id' => (int) $row->exchange_account_id,
            'exchange' => (string) $row->exchange,
            'account_name' => (string) $row->account_name,
            'status' => $status,
            'status_label' => $status,
            'error_message' => trim((string) ($row->error_message ?? '')),
            'executed_at' => $this->executionTime($row),
        ];
    }

    private function executionTime(object $row): ?string
    {
        $value = match ((string) $row->status) {
            'filled' => $row->filled_at ?: $row->execution_updated_at,
            'failed' => $row->failed_at ?: $row->execution_updated_at,
            'submitted' => $row->submitted_at ?: $row->execution_created_at,
            default => $row->execution_created_at ?: $row->planned_at ?: $row->plan_created_at,
        };

        return $value !== null ? (string) $value : null;
    }

    /** @return string[] */
    private function reasonDatabaseValues(string $reason): array
    {
        $values = [$reason];
        foreach (self::LEGACY_REASON_ALIASES as $legacy => $canonical) {
            if ($canonical === $reason) {
                $values[] = $legacy;
            }
        }

        return array_values(array_unique($values));
    }

    private function canonicalReason(string $reason): string
    {
        return self::LEGACY_REASON_ALIASES[$reason] ?? $reason;
    }

    /** @return array<int, array{label: string, value: string}> */
    private function symbolOptions(Builder $query): array
    {
        return $query
            ->select(['plan.symbol as value', 'config.symbol as config_symbol'])
            ->distinct()
            ->orderBy('plan.symbol')
            ->get()
            ->map(fn (object $row): array => [
                'label' => $this->displaySymbol((string) ($row->config_symbol ?: $row->value)),
                'value' => (string) $row->value,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, int|string>> */
    private function accountOptions(Builder $query): array
    {
        return $query
            ->select([
                'plan.agent_id',
                'plan.exchange_account_id as value',
                'plan.exchange',
                'plan.account_name',
            ])
            ->distinct()
            ->orderBy('plan.agent_id')
            ->orderBy('plan.exchange')
            ->orderBy('plan.account_name')
            ->get()
            ->map(static fn (object $row): array => [
                'agent_id' => (int) $row->agent_id,
                'label' => implode(' · ', array_filter([
                    (string) $row->exchange,
                    (string) $row->account_name,
                ], static fn (string $value): bool => $value !== '')),
                'value' => (int) $row->value,
            ])
            ->values()
            ->all();
    }

    /** @param string[] $values */
    private function valueOptions(array $values): array
    {
        return array_map(static fn (string $value): array => [
            'label' => $value,
            'value' => $value,
        ], $values);
    }

    private function displaySymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        if (str_contains($symbol, '/')) {
            $symbol = explode(':', $symbol, 2)[0];
            $symbol = str_replace('/', '', $symbol);
        }

        return $symbol;
    }

    private function normalizeDecimal(mixed $value): string
    {
        $text = (string) $value;
        if (!str_contains($text, '.')) {
            return $text;
        }

        return rtrim(rtrim($text, '0'), '.') ?: '0';
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
