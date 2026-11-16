<?php

declare(strict_types=1);

namespace SixMm\Shared\ContractVip;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use SixMm\Shared\Contracts\UserDataScope;

/** Read-only VIP reporting; identity, source metadata and threshold ownership remain host-owned. */
final class ContractVipListService
{
    public function __construct(private ConnectionInterface $db) {}

    public static function rules(): array
    {
        return [
            'page_no' => 'nullable|integer|min:1|max:2147483647',
            'page_size' => 'nullable|integer|between:1,100',
            'keyword' => 'nullable|string|max:100', 'uid' => 'nullable|integer|min:1',
            'username' => 'nullable|string|max:100', 'vip_level' => 'nullable|integer|min:0|max:100',
            'sort_field' => 'nullable|in:volume_30d,vip_level,last_calculated_at',
            'sort_order' => 'nullable|in:asc,desc',
        ];
    }

    /** @param callable(array): array<int, array<int, string>> $thresholdsForUsers Internal user IDs as keys. */
    public function search(UserDataScope $scope, array $params, callable $thresholdsForUsers): array
    {
        $page = max(1, min(2147483647, (int) ($params['page_no'] ?? 1)));
        $size = max(1, min(100, (int) ($params['page_size'] ?? 20)));
        $users = $this->db->table('users')->whereNull('users.deleted_at');
        $scope->apply($users, 'users.agent_id');
        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $pattern = '%' . strtr(mb_strtolower($keyword, 'UTF-8'), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $users->where(static function (Builder $query) use ($keyword, $pattern): void {
                $query->whereRaw("LOWER(COALESCE(users.username, '')) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(users.nick_name, '')) LIKE ? ESCAPE '!'", [$pattern]);
                if (ctype_digit($keyword) && filter_var($keyword, FILTER_VALIDATE_INT) !== false) {
                    $query->orWhere('users.public_user_id', $keyword);
                }
            });
        }
        if (!empty($params['uid'])) $users->where('users.public_user_id', (int) $params['uid']);
        if (($params['username'] ?? '') !== '') {
            $users->where('users.username', 'like', '%' . addcslashes(trim($params['username']), '%_\\') . '%');
        }
        foreach (['agent_id', 'vip_level'] as $field) {
            if (isset($params[$field]) && $params[$field] !== '') $users->where('users.' . $field, (int) $params[$field]);
        }
        // Authorize before aggregation, COUNT, sorting and pagination, not after fetching a global page.
        $volumes = $this->db->table('user_daily_volume')
            ->whereIn('user_id', (clone $users)->select('users.user_id'))
            ->where('trade_date', '>=', Carbon::now()->subDays(30)->toDateString())
            ->selectRaw('user_id, SUM(volume) AS volume_30d, MAX(updated_at) AS last_calculated_at')->groupBy('user_id');
        $total = (clone $users)->count();
        $query = $users->leftJoinSub($volumes, 'vip_volume', 'vip_volume.user_id', '=', 'users.user_id');
        $field = $params['sort_field'] ?? '';
        $direction = $params['sort_order'] ?? '';
        if (in_array($direction, ['asc', 'desc'], true)) {
            if ($field === 'vip_level') $query->orderBy('users.vip_level', $direction);
            elseif ($field === 'volume_30d') $query->orderByRaw("COALESCE(vip_volume.volume_30d, 0) {$direction}");
            elseif ($field === 'last_calculated_at') {
                $query->orderByRaw('CASE WHEN vip_volume.last_calculated_at IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('vip_volume.last_calculated_at', $direction);
            }
        }
        $items = $query->orderByDesc('users.user_id')->forPage($page, $size)
            ->get(['users.user_id', 'users.public_user_id', 'users.username', 'users.nick_name',
                'users.agent_id', 'users.home_site_id', 'users.vip_level', 'vip_volume.volume_30d', 'vip_volume.last_calculated_at'])
            ->map(static fn ($row) => (array) $row)->all();
        $thresholds = $items === [] ? [] : $thresholdsForUsers($items);
        $rows = array_map(static function (array $user) use ($thresholds): array {
            $id = (int) $user['user_id'];
            $volume = (string) ($user['volume_30d'] ?? '0');
            $level = (int) $user['vip_level'];
            return array_merge([
                'id' => $id, 'user_id' => (string) $user['public_user_id'],
                'username' => (string) $user['username'], 'nice_name' => $user['nick_name'],
                'agent_id' => (int) $user['agent_id'], 'vip_level' => $level, 'volume_30d' => $volume,
                // Do not misrepresent users.updated_at as a VIP-level update timestamp.
                'vip_level_updated_at' => null,
                'last_calculated_at' => $user['last_calculated_at'] === null ? null
                    : Carbon::parse($user['last_calculated_at'])->format('Y-m-d H:i:s'),
            ], ContractVipProgress::calculate($level, $volume, $thresholds[$id] ?? []));
        }, $items);
        return ['lists' => $rows, 'count' => $total, 'page' => $page, 'limit' => $size];
    }
}
