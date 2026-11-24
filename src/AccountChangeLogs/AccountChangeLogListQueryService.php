<?php

declare(strict_types=1);

namespace SixMm\Shared\AccountChangeLogs;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use SixMm\Shared\Contracts\UserDataScope;
use SixMm\Shared\Pagination\CursorPageResult;
use SixMm\Shared\TradeFills\TradeFillOwnerScope;

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
        $users = $this->scopedUsers($criteria, $scope);
        if ($users->isEmpty()) {
            return $this->emptyResult($criteria);
        }

        $query = $this->baseQuery($users->keys()->all());

        return $this->paginate($criteria, $query, $users);
    }

    /** Ownership comes from ledger snapshots; identity filters must not reassign historical rows. */
    public function searchByOwner(
        AccountChangeLogListQuery $criteria,
        TradeFillOwnerScope $owner,
        ?UserDataScope $identityFilter = null
    ): CursorPageResult {
        if ($owner->isEmpty()) return $this->emptyResult($criteria);
        $query = $this->connection->table('user_account_change_log AS logs')
            ->whereRaw($owner->predicate('logs'))->whereNull('logs.deleted_at');
        if ($identityFilter !== null || $criteria->keyword() !== '' || $criteria->userType() !== null) {
            $query->whereExists(function (Builder $users) use ($criteria, $identityFilter): void {
                $users->selectRaw('1')->from('users')->whereColumn('users.user_id', 'logs.user_id')
                    ->whereNull('users.deleted_at');
                $this->applyUserFilters($users, $criteria);
                $identityFilter?->apply($users, 'users.agent_id');
            });
        }
        return $this->paginate($criteria, $query, null, null, true);
    }

    /** Site is derived from the authenticated white-label tenant, never from request parameters. */
    public function fundingFeesAtSite(AccountChangeLogListQuery $criteria, int $site): CursorPageResult
    {
        if ($site <= 0) return $this->emptyResult($criteria);
        $query = $this->connection->table('user_account_change_log AS logs')
            ->where('logs.home_site_id', $site)->where('logs.agent_id', 0)
            ->whereRaw('LOWER(logs.change_type) = ?', ['funding_fee_settle'])->whereNull('logs.deleted_at');
        if ($criteria->keyword() !== '' || $criteria->userType() !== null) {
            $query->whereExists(function (Builder $users) use ($criteria): void {
                $users->selectRaw('1')->from('users')->whereColumn('users.user_id', 'logs.user_id')
                    ->whereNull('users.deleted_at');
                $this->applyUserFilters($users, $criteria);
            });
        }
        return $this->paginate($criteria, $query, null, 0, true);
    }

    /**
     * Merchant history is owned by the event snapshot, not the user's current owner.
     * The host MUST derive $agentId from its authenticated merchant identity.
     *
     * @return CursorPageResult<array<string, mixed>>
     */
    public function searchByAgent(AccountChangeLogListQuery $criteria, int $agentId): CursorPageResult
    {
        if ($agentId <= 0) {
            return $this->emptyResult($criteria);
        }

        $query = $this->connection
            ->table('user_account_change_log AS logs')
            ->where('logs.agent_id', $agentId)
            ->whereNull('logs.deleted_at');

        if ($criteria->keyword() !== '' || $criteria->userType() !== null) {
            $query->whereExists(function (Builder $users) use ($criteria): void {
                $users->selectRaw('1')->from('users')
                    ->whereColumn('users.user_id', 'logs.user_id')
                    ->whereNull('users.deleted_at');
                $this->applyUserFilters($users, $criteria);
            });
        }

        return $this->paginate($criteria, $query, null, $agentId);
    }

    /** @return CursorPageResult<array<string, mixed>> */
    private function paginate(
        AccountChangeLogListQuery $criteria,
        Builder $query,
        ?Collection $users = null,
        ?int $bindingAgentId = null,
        bool $eventOwner = false
    ): CursorPageResult {
        $this->applyLogFilters($query, $criteria);

        $cursor = $this->decodeCursor($criteria->cursor(), $criteria);
        $isPreviousRequest = (bool) ($cursor['previous'] ?? false);
        $this->applyCursor($query, $criteria, $cursor, $isPreviousRequest);
        $this->applyOrdering($query, $criteria, $isPreviousRequest);

        $candidateIds = (clone $query)
            ->limit($criteria->pageSize() + 1)
            ->pluck('logs.id');
        $hasExtraItem = $candidateIds->count() > $criteria->pageSize();
        if ($hasExtraItem) {
            $candidateIds->pop();
        }
        if ($isPreviousRequest) {
            $candidateIds = $candidateIds->reverse()->values();
        }

        $rowsById = $candidateIds->isEmpty()
            ? collect()
            : (clone $query)
                ->reorder()
                ->whereIntegerInRaw(
                    'logs.id',
                    $candidateIds->map(static fn ($id): int => (int) $id)->all()
                )
                ->get($this->columns($eventOwner))
                ->keyBy(static fn (object $row): string => (string) $row->id);
        $rows = $candidateIds
            ->map(static fn ($id) => $rowsById->get((string) $id))
            ->filter()
            ->values();

        if ($users === null) {
            // Only hydrate the authorized page; do not enumerate a merchant's users.
            $pageUserIds = $rows->pluck('platform_user_id')->map(static fn ($id): int => (int) $id)->unique()->all();
            $users = $rows->isEmpty() ? collect() : $this->getUsers(
                $this->usersQuery($bindingAgentId, $pageUserIds)->whereIntegerInRaw('users.user_id', $pageUserIds)
            );
        }

        $eventBindings = collect();
        if ($eventOwner && $rows->isNotEmpty()) {
            $eventBindings = $this->connection->table('agent_user_bindings')
                ->whereIn('platform_user_id', $rows->pluck('platform_user_id')->unique()->all())
                ->whereIn('agent_id', $rows->pluck('agent_id')->unique()->all())
                ->whereIn('bind_status', self::ACTIVE_BINDING_STATUSES)
                ->select(['agent_id', 'platform_user_id'])->selectRaw('MAX(agent_user_id) AS agent_user_id')
                ->groupBy('agent_id', 'platform_user_id')->get()
                ->keyBy(static fn (object $binding): string => $binding->agent_id . ':' . $binding->platform_user_id);
        }

        $items = $rows
            ->map(function (object $row) use ($users, $eventOwner, $eventBindings): array {
                $values = (array) $row;
                $user = $users->get((string) $values['platform_user_id']);
                $identity = $user !== null ? (array) $user : [];
                if ($eventOwner) {
                    $identity['agent_user_id'] = $eventBindings->get($values['agent_id'] . ':' . $values['platform_user_id'])?->agent_user_id;
                }
                return $this->mapRow($values, $identity);
            })
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

    /** @return Collection<string, object> */
    private function scopedUsers(AccountChangeLogListQuery $criteria, UserDataScope $scope): Collection
    {
        $query = $this->usersQuery()->whereNull('users.deleted_at');
        $scope->apply($query, 'users.agent_id');
        $this->applyUserFilters($query, $criteria);

        return $this->getUsers($query);
    }

    private function usersQuery(?int $bindingAgentId = null, ?array $pageUserIds = null): Builder
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
        if ($pageUserIds !== null) $bindings->whereIn('platform_user_id', $pageUserIds);

        return $this->connection
            ->table('users')
            ->leftJoinSub($bindings, 'aub', static function (JoinClause $join) use ($bindingAgentId): void {
                $join->on('aub.platform_user_id', '=', 'users.user_id');
                if ($bindingAgentId === null) {
                    $join->on('aub.agent_id', '=', 'users.agent_id');
                } else {
                    $join->where('aub.agent_id', '=', $bindingAgentId);
                }
            });
    }

    private function applyUserFilters(Builder $query, AccountChangeLogListQuery $criteria): void
    {
        if ($criteria->keyword() !== '') {
            $keyword = \SixMm\Shared\UserDetails\PortraitUidFilter::parse($criteria->keyword()) ?? $criteria->keyword();
            if (!ctype_digit($keyword)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('users.public_user_id', $keyword);
            }
        }
        if ($criteria->userType() !== null) {
            $query->where('users.user_type', $criteria->userType());
        }
    }

    /** @return Collection<string, object> */
    private function getUsers(Builder $query): Collection
    {
        return $query
            ->get([
                'users.user_id AS platform_user_id',
                'users.public_user_id AS user_id',
                'users.username',
                'users.nick_name',
                'users.user_type',
                'aub.agent_user_id',
            ])
            ->keyBy(static fn (object $user): string => (string) $user->platform_user_id);
    }

    /** @param array<int, int|string> $platformUserIds */
    private function baseQuery(array $platformUserIds): Builder
    {
        return $this->connection
            ->table('user_account_change_log AS logs')
            ->whereIntegerInRaw('logs.user_id', array_map('intval', $platformUserIds))
            ->whereNull('logs.deleted_at');
    }

    private function applyLogFilters(Builder $query, AccountChangeLogListQuery $criteria): void
    {
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
    private function columns(bool $eventOwner = false): array
    {
        return [
            ...($eventOwner ? ['logs.home_site_id'] : []),
            'logs.id',
            'logs.user_id AS platform_user_id',
            'logs.user_type',
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
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $user
     */
    private function mapRow(array $row, array $user): array
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

        $row['user_type'] = $row['user_type'] ?? $user['user_type'] ?? null;
        $row['user_id'] = $user['user_id'] ?? $row['platform_user_id'];
        $row['username'] = $user['username'] ?? null;
        $row['nice_name'] = $user['nick_name'] ?? null;
        $row['agent_user_id'] = $user['agent_user_id'] ?? null;
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

    /** @return CursorPageResult<array<string, mixed>> */
    private function emptyResult(AccountChangeLogListQuery $criteria): CursorPageResult
    {
        return new CursorPageResult([], $criteria->pageSize(), false, false, null, null);
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
