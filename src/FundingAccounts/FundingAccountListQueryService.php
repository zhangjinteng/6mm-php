<?php

declare(strict_types=1);

namespace SixMm\Shared\FundingAccounts;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use SixMm\Shared\Contracts\UserDataScope;
use SixMm\Shared\Pagination\PageResult;

final class FundingAccountListQueryService
{
    public function __construct(
        private ConnectionInterface $assetConnection,
        private ConnectionInterface $mainConnection,
        private string $timezone = 'UTC'
    ) {
    }

    /** @return PageResult<array<string, mixed>> */
    public function search(
        FundingAccountListQuery $criteria,
        UserDataScope $accountScope,
        UserDataScope $userScope
    ): PageResult {
        $query = $this->assetConnection
            ->table('funding_balances as balances')
            ->join('funding_accounts as accounts', 'accounts.account_id', '=', 'balances.account_id')
            ->join('funding_assets as assets', 'assets.asset_id', '=', 'balances.asset_id')
            ->where('accounts.status', 'ACTIVE')
            ->where('assets.status', 'ENABLED');

        $accountScope->apply($query, 'accounts.agent_id');

        if ($criteria->keyword() !== '') {
            $matchedUserIds = $this->matchedUserIds($userScope, $criteria->keyword());
            if ($matchedUserIds === []) {
                return new PageResult([], 0, $criteria->page(), $criteria->pageSize());
            }
            $query->whereIn('accounts.user_id', $matchedUserIds);
        }

        if ($criteria->startTime() !== '') {
            $query->where('balances.updated_at', '>=', $criteria->startTime());
        }
        if ($criteria->endTimeExclusive() !== '') {
            $query->where('balances.updated_at', '<', $criteria->endTimeExclusive());
        }

        $total = (int) (clone $query)->count();
        $sortColumns = [
            'currency' => 'assets.asset_code',
            'account_balance' => 'account_balance_atomic',
            'available_balance' => 'balances.available_amount',
            'frozen_balance' => 'balances.held_amount',
            'last_changed_at' => 'balances.updated_at',
        ];

        $rows = $query
            ->select([
                'balances.balance_id',
                'accounts.agent_id',
                'accounts.user_id as platform_user_id',
                'assets.asset_code as currency',
                'assets.internal_scale',
                'balances.available_amount',
                'balances.held_amount',
                'balances.updated_at as last_changed_at',
            ])
            ->selectRaw('(balances.available_amount + balances.held_amount) as account_balance_atomic')
            ->orderBy($sortColumns[$criteria->orderBy()], $criteria->orderDirection())
            ->orderBy('balances.balance_id', 'desc')
            ->forPage($criteria->page(), $criteria->pageSize())
            ->get();

        $users = $this->usersById(
            $userScope,
            $rows->pluck('platform_user_id')->map(static fn ($id): int => (int) $id)->all()
        );

        $items = $rows->map(function (object $row) use ($users): array {
            $platformUserId = (int) $row->platform_user_id;
            $user = $users[$platformUserId] ?? null;
            $scale = max(0, (int) $row->internal_scale);

            return [
                'id' => (int) $row->balance_id,
                'agent_id' => (int) $row->agent_id,
                'platform_user_id' => $platformUserId,
                'user_id' => $user !== null ? (string) $user->public_user_id : (string) $platformUserId,
                'username' => $user !== null ? (string) $user->username : '',
                'nice_name' => $user !== null ? (string) $user->nick_name : '',
                'currency' => (string) $row->currency,
                'account_balance' => self::fromAtomic($row->account_balance_atomic, $scale),
                'available_balance' => self::fromAtomic($row->available_amount, $scale),
                'frozen_balance' => self::fromAtomic($row->held_amount, $scale),
                'last_changed_at' => $this->localizedDateTime($row->last_changed_at),
            ];
        })->values()->all();

        return new PageResult($items, $total, $criteria->page(), $criteria->pageSize());
    }

    /** @return int[] */
    private function matchedUserIds(UserDataScope $scope, string $keyword): array
    {
        $query = $this->mainConnection->table('users')->whereNull('deleted_at');
        $scope->apply($query, 'agent_id');
        $escapedKeyword = '%' . strtolower(self::escapeLike($keyword)) . '%';
        $query->where(static function (Builder $nested) use ($keyword, $escapedKeyword): void {
            if (ctype_digit($keyword)) {
                $nested->where('public_user_id', $keyword)->orWhere('user_id', $keyword);
            }
            $method = ctype_digit($keyword) ? 'orWhereRaw' : 'whereRaw';
            $nested->{$method}('LOWER(COALESCE(username, ?)) LIKE ?', ['', $escapedKeyword]);
        });

        return $query->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter()
            ->values()
            ->all();
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

    private static function fromAtomic($amount, int $scale): string
    {
        return self::shiftAtomicDecimal((string) ($amount ?? '0'), $scale);
    }

    private static function shiftAtomicDecimal(string $amount, int $scale): string
    {
        $amount = trim($amount);
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
