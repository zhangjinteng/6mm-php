<?php

declare(strict_types=1);

namespace SixMm\Shared\FundingOverview;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use SixMm\Shared\Contracts\UserDataScope;
use SixMm\Shared\Pagination\PageResult;

/** Read-only USDT overview. Occupation is already included in contract equity. */
final class FundingOverviewListService
{
    public function __construct(
        private ConnectionInterface $assetConnection,
        private ConnectionInterface $mainConnection,
        private string $timezone = 'UTC'
    ) {}

    /** @return PageResult<array<string, mixed>> */
    public function search(FundingOverviewListQuery $criteria, UserDataScope $scope, ?int $fundingAgentId = null): PageResult
    {
        $query = $this->mainConnection->table('users')
            ->select(['user_id', 'public_user_id', 'agent_id', 'username', 'nick_name'])->whereNull('users.deleted_at');
        $scope->apply($query, 'users.agent_id');
        if ($criteria->keyword !== '') {
            $keyword = $criteria->keyword;
            $pattern = '%' . strtolower(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword)) . '%';
            $query->where(static function ($nested) use ($keyword, $pattern): void {
                if (ctype_digit($keyword)) $nested->where('public_user_id', $keyword)->orWhere('user_id', $keyword);
                // An explicit escape character behaves consistently on PostgreSQL and SQLite.
                $nested->orWhereRaw("LOWER(username) LIKE ? ESCAPE '\\'", [$pattern]);
            });
        }
        $users = $query->get();
        if ($users->isEmpty()) return new PageResult([], 0, $criteria->page, $criteria->pageSize);

        $ids = $users->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
        $funding = $this->fundingBalances($this->fundingOwners($users, $fundingAgentId));
        $contracts = $this->contractBalances($ids);
        $rows = [];
        $start = self::timestamp($criteria->startTime);
        $end = self::timestamp($criteria->endTimeExclusive);
        foreach ($users as $user) {
            $id = (int) $user->user_id;
            $balance = $funding[$id]['balance'] ?? '0';
            $equity = $contracts[$id]['equity'] ?? '0';
            $changed = self::latest($funding[$id]['updated_at'] ?? null, $contracts[$id]['updated_at'] ?? null);
            $timestamp = self::timestamp($changed);
            if (($start !== null || $end !== null) && ($timestamp === null
                || ($start !== null && $timestamp < $start) || ($end !== null && $timestamp >= $end))) continue;
            $rows[] = [
                'id' => $id, 'platform_user_id' => $id, 'agent_id' => (int) $user->agent_id,
                'user_id' => (string) ($user->public_user_id ?: $id), 'username' => (string) ($user->username ?? ''),
                'nice_name' => (string) ($user->nick_name ?? ''), 'funding_balance' => $balance,
                'contract_equity' => $equity, 'contract_occupied' => $contracts[$id]['occupied'] ?? '0',
                'total_assets' => self::add($balance, $equity),
                'last_changed_at' => $changed === null ? null : CarbonImmutable::parse($changed, 'UTC')
                    ->setTimezone($this->timezone)->format('Y-m-d H:i:s'),
                '_last_changed_at' => $timestamp,
            ];
        }
        $direction = $criteria->orderDirection === 'asc' ? 1 : -1;
        $field = $criteria->orderBy;
        usort($rows, static function (array $a, array $b) use ($direction, $field): int {
            if (in_array($field, ['funding_balance', 'contract_equity', 'contract_occupied', 'total_assets'], true)) {
                $comparison = bccomp($a[$field], $b[$field], max(self::scale($a[$field]), self::scale($b[$field])));
            } elseif ($field === 'last_changed_at') {
                $comparison = ($a['_last_changed_at'] ?? 0) <=> ($b['_last_changed_at'] ?? 0);
            } else {
                $comparison = strnatcasecmp((string) $a[$field], (string) $b[$field]);
            }
            return ($comparison ?: ($a['id'] <=> $b['id'])) * $direction;
        });
        $total = count($rows);
        $items = array_slice($rows, ($criteria->page - 1) * $criteria->pageSize, $criteria->pageSize);
        foreach ($items as &$item) unset($item['_last_changed_at']);
        unset($item);
        return new PageResult(array_values($items), $total, $criteria->page, $criteria->pageSize);
    }

    /** @return array<int, int> user ID => funding wallet owner (main agent or direct user 0) */
    private function fundingOwners(Collection $users, ?int $fixedAgentId): array
    {
        $agents = collect();
        if ($fixedAgentId === null) {
            $ids = $users->pluck('agent_id')->filter(static fn ($id): bool => (int) $id > 0)->unique()->all();
            if ($ids !== []) $agents = $this->mainConnection->table('agents')->whereIn('id', $ids)
                ->whereNull('deleted_at')->get(['id', 'pid'])->keyBy('id');
        }
        $owners = [];
        foreach ($users as $user) {
            $agent = $agents->get((int) $user->agent_id);
            $owners[(int) $user->user_id] = $fixedAgentId ?? ((int) ($agent->pid ?? 0) > 0
                ? (int) $agent->pid : (int) $user->agent_id);
        }
        return $owners;
    }

    private function fundingBalances(array $owners): array
    {
        $result = [];
        foreach (array_chunk(array_keys($owners), 1000) as $ids) {
            $rows = $this->assetConnection->table('funding_balances as balances')
                ->join('funding_accounts as accounts', 'accounts.account_id', '=', 'balances.account_id')
                ->join('funding_assets as assets', 'assets.asset_id', '=', 'balances.asset_id')
                ->where('accounts.status', 'ACTIVE')->where('assets.status', 'ENABLED')->where('assets.asset_code', 'USDT')
                ->whereIn('accounts.user_id', $ids)
                ->whereIn('accounts.agent_id', array_values(array_unique(array_intersect_key($owners, array_flip($ids)))))
                ->get(['accounts.user_id', 'accounts.agent_id', 'assets.internal_scale',
                    'balances.available_amount', 'balances.held_amount', 'balances.updated_at']);
            foreach ($rows as $row) {
                $id = (int) $row->user_id;
                if ((int) $row->agent_id !== $owners[$id]) continue;
                $scale = max(0, (int) $row->internal_scale);
                $balance = self::add(self::atomic((string) $row->available_amount, $scale), self::atomic((string) $row->held_amount, $scale));
                $result[$id] = ['balance' => self::add($result[$id]['balance'] ?? '0', $balance),
                    'updated_at' => self::latest($result[$id]['updated_at'] ?? null, $row->updated_at)];
            }
        }
        return $result;
    }

    private function contractBalances(array $ids): array
    {
        $result = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = $this->mainConnection->table('user_assets')->whereNull('deleted_at')->where('asset_type', 'USDT')
                ->whereIn('user_id', $chunk)->get(['user_id', 'wallet_balance', 'frozen_balance', 'updated_at']);
            foreach ($rows as $row) {
                $id = (int) $row->user_id;
                $result[$id] = ['equity' => self::add($result[$id]['equity'] ?? '0', (string) ($row->wallet_balance ?? '0')),
                    'occupied' => self::add($result[$id]['occupied'] ?? '0', (string) ($row->frozen_balance ?? '0')),
                    'updated_at' => self::latest($result[$id]['updated_at'] ?? null, $row->updated_at)];
            }
        }
        return $result;
    }

    private static function scale(string $value): int
    {
        $dot = strpos($value, '.');
        return $dot === false ? 0 : strlen($value) - $dot - 1;
    }

    private static function add(string $a, string $b): string
    {
        return bcadd($a, $b, max(self::scale($a), self::scale($b)));
    }

    private static function atomic(string $value, int $scale): string
    {
        if ($scale === 0) return $value;
        $negative = str_starts_with($value, '-');
        $digits = str_pad(ltrim($value, '+-'), $scale + 1, '0', STR_PAD_LEFT);
        return ($negative ? '-' : '') . substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
    }

    private static function timestamp(?string $value): ?int
    {
        return $value === null || trim($value) === '' ? null : CarbonImmutable::parse($value, 'UTC')->getTimestamp();
    }

    private static function latest(?string $a, ?string $b): ?string
    {
        if ($a === null || trim($a) === '') return $b === null || trim($b) === '' ? null : $b;
        return (self::timestamp($b) ?? 0) > (self::timestamp($a) ?? 0) ? $b : $a;
    }
}
