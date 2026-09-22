<?php

declare(strict_types=1);

namespace SixMm\Shared\FundingChangeLogs;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use SixMm\Shared\Contracts\UserDataScope;
use SixMm\Shared\Pagination\PageResult;

final class FundingChangeLogListQueryService
{
    private const ACTIVE_BINDING_STATUSES = ['BOUND', 'LOCKED'];

    public function __construct(
        private ConnectionInterface $assetConnection,
        private ConnectionInterface $mainConnection,
        private string $timezone = 'UTC'
    ) {
    }

    /** @return PageResult<array<string, mixed>> */
    public function search(
        FundingChangeLogListQuery $criteria,
        UserDataScope $accountScope,
        UserDataScope $userScope
    ): PageResult {
        $query = $this->assetConnection
            ->table('funding_ledger_entries as ledger')
            ->join('funding_accounts as accounts', 'accounts.account_id', '=', 'ledger.account_id')
            ->join('funding_assets as assets', 'assets.asset_id', '=', 'ledger.asset_id')
            ->where('ledger.business_type', '<>', 'DEPLOY_SMOKE');

        $accountScope->apply($query, 'accounts.agent_id');
        $this->applyKeywordFilter($query, $userScope, $criteria->keyword());
        $this->applyChangeTypeFilter($query, $criteria->changeType());

        if ($criteria->game() !== '') {
            $query->where('ledger.business_scope', $criteria->game());
        }
        if ($criteria->startTime() !== '') {
            $query->where('ledger.created_at', '>=', $criteria->startTime());
        }
        if ($criteria->endTimeExclusive() !== '') {
            $query->where('ledger.created_at', '<', $criteria->endTimeExclusive());
        }

        $total = (int) (clone $query)->count();
        $rows = $query
            ->select([
                'ledger.ledger_id',
                'accounts.agent_id',
                'ledger.user_id as platform_user_id',
                'ledger.entry_type',
                'ledger.business_type',
                'ledger.business_scope',
                'ledger.business_id',
                'ledger.business_operation_id',
                'ledger.related_operation_id',
                'ledger.created_at',
                'assets.asset_code as currency',
                'assets.internal_scale',
            ])
            ->selectRaw('(ledger.available_delta + ledger.held_delta) as balance_change_atomic')
            ->selectRaw('(ledger.available_before + ledger.held_before) as balance_before_atomic')
            ->selectRaw('(ledger.available_after + ledger.held_after) as balance_after_atomic');

        $this->applyOrder($rows, $criteria->orderBy(), $criteria->orderDirection());
        $rows = $rows->forPage($criteria->page(), $criteria->pageSize())->get();
        $userIds = $rows->pluck('platform_user_id')->map(static fn ($id): int => (int) $id)->all();
        $users = $this->usersById($userScope, $userIds);
        $externalUserIds = $this->externalUserIdsByPlatformUserId($userScope, $userIds);

        $items = $rows->map(function (object $row) use ($users, $externalUserIds): array {
            $platformUserId = (int) $row->platform_user_id;
            $user = $users[$platformUserId] ?? null;
            $scale = max(0, (int) $row->internal_scale);

            return [
                'id' => (string) $row->ledger_id,
                'ledger_id' => (string) $row->ledger_id,
                'agent_id' => (int) $row->agent_id,
                'platform_user_id' => $platformUserId,
                'user_id' => $user !== null ? (string) $user->public_user_id : (string) $platformUserId,
                'username' => $user !== null ? (string) ($user->username ?? '') : '',
                'nice_name' => $user !== null ? (string) ($user->nick_name ?? '') : '',
                'agent_user_id' => (string) ($externalUserIds[$platformUserId] ?? ''),
                'entry_type' => (string) $row->entry_type,
                'business_type' => (string) $row->business_type,
                'business_scope' => (string) ($row->business_scope ?? ''),
                'business_id' => (string) $row->business_id,
                'business_operation_id' => $row->business_operation_id === null ? null : (string) $row->business_operation_id,
                'related_operation_id' => $row->related_operation_id === null ? null : (string) $row->related_operation_id,
                'currency' => (string) $row->currency,
                'balance_change' => self::fromAtomic($row->balance_change_atomic, $scale),
                'balance_before' => self::fromAtomic($row->balance_before_atomic, $scale),
                'balance_after' => self::fromAtomic($row->balance_after_atomic, $scale),
                'created_at' => $this->localizedDateTime($row->created_at),
            ];
        })->values()->all();

        return new PageResult($items, $total, $criteria->page(), $criteria->pageSize());
    }

    private function applyKeywordFilter(Builder $query, UserDataScope $scope, string $keyword): void
    {
        if ($keyword === '') return;

        $userIds = $this->matchedUserIds($scope, $keyword);
        $ledgerId = $this->parseLedgerId($keyword);
        $query->where(static function (Builder $nested) use ($userIds, $ledgerId): void {
            if ($userIds !== []) $nested->whereIn('ledger.user_id', $userIds);
            if ($ledgerId !== null) {
                $method = $userIds === [] ? 'where' : 'orWhere';
                $nested->{$method}('ledger.ledger_id', $ledgerId);
            }
            if ($userIds === [] && $ledgerId === null) $nested->whereRaw('1 = 0');
        });
    }

    private function applyChangeTypeFilter(Builder $query, string $changeType): void
    {
        if ($changeType === 'deposit') $query->where('ledger.business_type', 'DEPOSIT');
        if ($changeType === 'stake') $query->whereIn('ledger.business_type', ['SECONDS_BET_STAKE', 'SECONDS_BET_DEBIT']);
        if ($changeType === 'payout') $query->where('ledger.business_type', 'SECONDS_BET_PAYOUT');
        if ($changeType === 'refund') $query->where('ledger.business_type', 'SECONDS_BET_REFUND');

        $ledgerTypes = [
            'transfer_hold_created' => ['TRANSFER', 'TRANSFER_HOLD_CREATED'],
            'transfer_hold_released' => ['TRANSFER', 'TRANSFER_HOLD_RELEASED'],
            'transfer_out' => ['TRANSFER', 'TRANSFER_OUT'],
            'transfer_in' => ['TRANSFER', 'TRANSFER_IN'],
            'agent_transfer_in' => ['AGENT_TRANSFER_IN', 'CREDIT'],
            'agent_transfer_out' => ['AGENT_TRANSFER_OUT', 'DEBIT'],
        ];
        if (isset($ledgerTypes[$changeType])) {
            [$businessType, $entryType] = $ledgerTypes[$changeType];
            $query->where('ledger.business_type', $businessType)
                ->where('ledger.entry_type', $entryType);
        }
    }

    private function applyOrder(Builder $query, string $orderBy, string $direction): void
    {
        $columns = [
            'ledger_id' => 'ledger.ledger_id',
            'created_at' => 'ledger.created_at',
            'balance_change' => 'balance_change_atomic',
            'balance_before' => 'balance_before_atomic',
            'balance_after' => 'balance_after_atomic',
        ];
        $column = $columns[$orderBy];
        $query->orderBy($column, $direction);
        if ($column !== 'ledger.ledger_id') $query->orderBy('ledger.ledger_id', 'desc');
    }

    /** @return int[] */
    private function matchedUserIds(UserDataScope $scope, string $keyword): array
    {
        $escaped = '%' . strtolower(self::escapeLike($keyword)) . '%';
        $users = $this->mainConnection->table('users')->whereNull('deleted_at');
        $scope->apply($users, 'agent_id');
        $users->where(static function (Builder $nested) use ($keyword, $escaped): void {
            if (ctype_digit($keyword)) {
                $nested->where('public_user_id', $keyword)->orWhere('user_id', $keyword);
            }
            $method = ctype_digit($keyword) ? 'orWhereRaw' : 'whereRaw';
            $nested->{$method}('LOWER(COALESCE(username, ?)) LIKE ?', ['', $escaped])
                ->orWhereRaw('LOWER(COALESCE(nick_name, ?)) LIKE ?', ['', $escaped]);
        });

        $bindings = $this->mainConnection
            ->table('agent_user_bindings')
            ->whereIn('bind_status', self::ACTIVE_BINDING_STATUSES)
            ->whereRaw('LOWER(agent_user_id) LIKE ?', [$escaped]);
        $scope->apply($bindings, 'agent_id');

        return array_values(array_unique(array_merge(
            $users->pluck('user_id')->map(static fn ($id): int => (int) $id)->all(),
            $bindings->pluck('platform_user_id')->map(static fn ($id): int => (int) $id)->all()
        )));
    }

    /** @param int[] $userIds @return array<int, object> */
    private function usersById(UserDataScope $scope, array $userIds): array
    {
        if ($userIds === []) return [];
        $query = $this->mainConnection
            ->table('users')
            ->select(['user_id', 'public_user_id', 'username', 'nick_name'])
            ->whereNull('deleted_at')
            ->whereIn('user_id', $userIds);
        $scope->apply($query, 'agent_id');
        return $query->get()->keyBy(static fn ($user): int => (int) $user->user_id)->all();
    }

    /** @param int[] $userIds @return array<int, string> */
    private function externalUserIdsByPlatformUserId(UserDataScope $scope, array $userIds): array
    {
        if ($userIds === []) return [];
        $query = $this->mainConnection
            ->table('agent_user_bindings')
            ->whereIn('platform_user_id', $userIds)
            ->whereIn('bind_status', self::ACTIVE_BINDING_STATUSES);
        $scope->apply($query, 'agent_id');
        return $query->pluck('agent_user_id', 'platform_user_id')
            ->mapWithKeys(static fn ($value, $key): array => [(int) $key => (string) $value])
            ->all();
    }

    private function parseLedgerId(string $keyword): ?int
    {
        if (!preg_match('/^(?:AFL)?(\d+)$/i', trim($keyword), $matches)) return null;
        $value = (int) $matches[1];
        return $value > 0 ? $value : null;
    }

    private static function fromAtomic($amount, int $scale): string
    {
        $amount = trim((string) ($amount ?? '0'));
        $negative = str_starts_with($amount, '-');
        $digits = ltrim($amount, '+-');
        if ($digits === '' || !ctype_digit($digits)) $digits = '0';
        $digits = ltrim($digits, '0') ?: '0';
        if ($scale <= 0) return ($negative && $digits !== '0' ? '-' : '') . $digits;

        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $integer = substr($digits, 0, -$scale);
        $fraction = substr($digits, -$scale);

        return ($negative && $digits !== str_repeat('0', strlen($digits)) ? '-' : '')
            . $integer . '.' . $fraction;
    }

    private function localizedDateTime($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : Carbon::parse($value)->setTimezone($this->timezone)->format('Y-m-d H:i:s');
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
