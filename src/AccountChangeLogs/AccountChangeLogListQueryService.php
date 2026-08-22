<?php

declare(strict_types=1);

namespace SixMm\Shared\AccountChangeLogs;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use SixMm\Shared\Contracts\UserDataScope;
use SixMm\Shared\Pagination\CursorPageResult;

final class AccountChangeLogListQueryService
{
    private const ACTIVE_BINDING_STATUSES = ['BOUND', 'LOCKED'];
    private const TRANSFER_IN = 'agent_transfer';
    private const TRANSFER_OUT = 'agent_transfer_all_out';

    public function __construct(private ConnectionInterface $connection)
    {
    }

    /** @return CursorPageResult<array<string, mixed>> */
    public function search(AccountChangeLogListQuery $criteria, UserDataScope $scope): CursorPageResult
    {
        $query = $this->baseQuery();
        $scope->apply($query, 'users.agent_id');
        $this->applyFilters($query, $criteria);

        $cursor = $this->decodeCursor($criteria->cursor(), $criteria);
        $isPreviousRequest = (bool) ($cursor['previous'] ?? false);
        $this->applyCursor($query, $criteria, $cursor, $isPreviousRequest);
        $this->applyOrdering($query, $criteria, $isPreviousRequest);

        $rows = $query
            ->limit($criteria->pageSize() + 1)
            ->get($this->columns());
        $hasExtraItem = $rows->count() > $criteria->pageSize();
        if ($hasExtraItem) {
            $rows->pop();
        }
        if ($isPreviousRequest) {
            $rows = $rows->reverse()->values();
        }

        $items = $rows
            ->map(fn (object $row): array => $this->mapRow((array) $row))
            ->all();
        $hasPrevious = $isPreviousRequest ? $hasExtraItem : $cursor !== null;
        $hasMore = $isPreviousRequest ? $items !== [] : $hasExtraItem;
        $first = $items[0] ?? null;
        $last = $items !== [] ? $items[array_key_last($items)] : null;

        return new CursorPageResult(
            $items,
            $criteria->pageSize(),
            $hasMore,
            $hasPrevious,
            $hasMore && $last !== null ? $this->encodeCursor($last, $criteria, false) : null,
            $hasPrevious && $first !== null ? $this->encodeCursor($first, $criteria, true) : null
        );
    }

    private function baseQuery(): Builder
    {
        $bindings = $this->connection
            ->table('agent_user_bindings')
            ->select([
                'agent_id',
                'platform_user_id',
                $this->connection->raw('MAX(agent_user_id) AS agent_user_id'),
            ])
            ->whereIn('bind_status', self::ACTIVE_BINDING_STATUSES)
            ->groupBy('agent_id', 'platform_user_id');

        return $this->connection
            ->table('user_account_change_log AS logs')
            ->join('users', 'users.user_id', '=', 'logs.user_id')
            ->leftJoinSub($bindings, 'aub', static function (JoinClause $join): void {
                $join->on('aub.agent_id', '=', 'users.agent_id')
                    ->on('aub.platform_user_id', '=', 'users.user_id');
            })
            ->whereNull('logs.deleted_at')
            ->whereNull('users.deleted_at');
    }

    private function applyFilters(Builder $query, AccountChangeLogListQuery $criteria): void
    {
        if ($criteria->keyword() !== '') {
            $keyword = $criteria->keyword();
            if (!ctype_digit($keyword)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('users.public_user_id', $keyword);
            }
        }

        if ($criteria->userType() !== null) {
            $query->where('users.user_type', $criteria->userType());
        }

        if ($criteria->changeType() !== '') {
            if ($criteria->changeType() === self::TRANSFER_IN) {
                $query->whereRaw('LOWER(logs.change_type) = ?', [self::TRANSFER_IN])
                    ->where('logs.amount', '>', 0);
            } elseif ($criteria->changeType() === self::TRANSFER_OUT) {
                $query->whereIn($this->connection->raw('LOWER(logs.change_type)'), [
                    self::TRANSFER_IN,
                    self::TRANSFER_OUT,
                ])->where('logs.amount', '<', 0);
            } else {
                $query->whereRaw('LOWER(logs.change_type) = ?', [$criteria->changeType()]);
            }
        }

        if ($criteria->symbol() !== '') {
            $query->whereRaw('UPPER(logs.symbol) LIKE ?', ['%' . $criteria->symbol() . '%']);
        }
        if ($criteria->createdAtStart() !== '') {
            $query->where('logs.created_at', '>=', $criteria->createdAtStart());
        }
        if ($criteria->createdAtEndExclusive() !== '') {
            $query->where('logs.created_at', '<', $criteria->createdAtEndExclusive());
        }
    }

    /** @param array{value: mixed, id: int, previous: bool}|null $cursor */
    private function applyCursor(
        Builder $query,
        AccountChangeLogListQuery $criteria,
        ?array $cursor,
        bool $isPreviousRequest
    ): void {
        if ($cursor === null) {
            return;
        }

        $column = $this->orderColumn($criteria->orderBy());
        $nextOperator = $criteria->orderDirection() === 'asc' ? '>' : '<';
        $operator = $isPreviousRequest ? $this->invertOperator($nextOperator) : $nextOperator;

        if ($criteria->orderBy() === 'id') {
            $query->where('logs.id', $operator, $cursor['id']);
            return;
        }

        $tieOperator = $isPreviousRequest ? '>' : '<';
        $query->where(static function (Builder $nested) use ($column, $operator, $tieOperator, $cursor): void {
            $nested->where($column, $operator, $cursor['value'])
                ->orWhere(static function (Builder $tied) use ($column, $tieOperator, $cursor): void {
                    $tied->where($column, '=', $cursor['value'])
                        ->where('logs.id', $tieOperator, $cursor['id']);
                });
        });
    }

    private function applyOrdering(
        Builder $query,
        AccountChangeLogListQuery $criteria,
        bool $isPreviousRequest
    ): void {
        $direction = $isPreviousRequest
            ? $this->invertDirection($criteria->orderDirection())
            : $criteria->orderDirection();
        $query->orderBy($this->orderColumn($criteria->orderBy()), $direction);

        if ($criteria->orderBy() !== 'id') {
            $query->orderBy('logs.id', $isPreviousRequest ? 'asc' : 'desc');
        }
    }

    private function orderColumn(string $orderBy): string
    {
        return match ($orderBy) {
            'amount' => 'logs.amount',
            'created_at' => 'logs.created_at',
            default => 'logs.id',
        };
    }

    /** @return array<int, string> */
    private function columns(): array
    {
        return [
            'logs.id',
            'logs.user_id AS platform_user_id',
            $this->connection->raw('COALESCE(logs.user_type, users.user_type) AS user_type'),
            'logs.agent_id',
            'logs.profile_version',
            'logs.symbol',
            'logs.asset_type',
            'logs.amount',
            'logs.wallet_balance_before',
            'logs.wallet_balance_after',
            'logs.frozen_balance_before',
            'logs.frozen_balance_after',
            'logs.change_type',
            'logs.reference_id',
            'logs.description',
            'logs.created_at',
            'logs.updated_at',
            'users.public_user_id AS user_id',
            'users.username',
            'users.nick_name',
            'aub.agent_user_id',
        ];
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): array
    {
        foreach ([
            'amount',
            'wallet_balance_before',
            'wallet_balance_after',
            'frozen_balance_before',
            'frozen_balance_after',
        ] as $field) {
            $row[$field] = (string) ($row[$field] ?? '0');
        }

        $row['nice_name'] = $row['nick_name'] ?? null;
        $row['user'] = [
            'platform_user_id' => $row['platform_user_id'],
            'user_id' => $row['user_id'],
            'username' => $row['username'] ?? null,
            'nice_name' => $row['nice_name'],
            'user_type' => $row['user_type'] ?? null,
            'agent_user_id' => $row['agent_user_id'] ?? null,
        ];

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function encodeCursor(array $row, AccountChangeLogListQuery $criteria, bool $previous): string
    {
        $valueKey = $criteria->orderBy() === 'id' ? 'id' : $criteria->orderBy();
        $payload = json_encode([
            'order_by' => $criteria->orderBy(),
            'order_dir' => $criteria->orderDirection(),
            'value' => $row[$valueKey] ?? null,
            'id' => (int) $row['id'],
            'previous' => $previous,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /** @return array{value: mixed, id: int, previous: bool}|null */
    private function decodeCursor(?string $cursor, AccountChangeLogListQuery $criteria): ?array
    {
        if ($cursor === null) {
            return null;
        }

        $padding = strlen($cursor) % 4;
        if ($padding > 0) {
            $cursor .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }
        $payload = json_decode($decoded, true);
        if (
            !is_array($payload)
            || ($payload['order_by'] ?? null) !== $criteria->orderBy()
            || ($payload['order_dir'] ?? null) !== $criteria->orderDirection()
            || !is_numeric($payload['id'] ?? null)
        ) {
            return null;
        }

        return [
            'value' => $payload['value'] ?? null,
            'id' => (int) $payload['id'],
            'previous' => (bool) ($payload['previous'] ?? false),
        ];
    }

    private function invertDirection(string $direction): string
    {
        return $direction === 'asc' ? 'desc' : 'asc';
    }

    private function invertOperator(string $operator): string
    {
        return $operator === '>' ? '<' : '>';
    }
}
