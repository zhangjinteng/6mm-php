<?php

declare(strict_types=1);

namespace SixMm\Shared\UserDetails;

use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use SixMm\Shared\Contracts\UserDataScope;

/** Narrow read model. Callers supply the authenticated scope, never an owner from a request. */
final class UserPortraitQuery
{
    public function __construct(private ConnectionInterface $db, private DateTimeZone $timezone)
    {
    }

    public function byPublicUid(string $uid, UserDataScope $scope): ?array
    {
        return $this->find('public_user_id', $uid, $scope);
    }

    public function byInternalId(int $id, UserDataScope $scope): ?array
    {
        return $this->find('user_id', (string) $id, $scope);
    }

    private function find(string $column, string $id, UserDataScope $scope): ?array
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id) || bccomp($id, (string) PHP_INT_MAX) > 0) {
            return null;
        }
        $query = $this->db->table('users')->leftJoin('agents', 'agents.id', '=', 'users.agent_id')
            ->whereNull('users.deleted_at')->where('users.'.$column, $id);
        $scope->apply($query, 'users.agent_id');
        $user = $query->first([
            'users.user_id', 'users.public_user_id', 'users.home_site_id', 'users.username', 'users.nick_name',
            'users.vip_level', 'users.online_status', 'users.risk_status', 'users.risk_label',
            'users.created_at', 'users.last_login_at', 'users.last_login_ip', 'agents.user_name AS agent_name_parse',
        ]);
        if (!$user) {
            return null;
        }
        $detail = (array) $user;
        foreach (['created_at', 'last_login_at'] as $key) {
            $detail[$key] = $detail[$key] !== null
                ? Carbon::parse($detail[$key], $this->timezone)->setTimezone($this->timezone)->format('Y-m-d H:i:s') : null;
        }
        $userId = $detail['user_id'];
        return [
            'detail' => $detail,
            'volume_30d' => (string) $this->db->table('user_daily_volume')->where('user_id', $userId)
                ->where('trade_date', '>=', Carbon::now($this->timezone)->subDays(30)->toDateString())->sum('volume'),
            // Match the existing Platform portrait's daily-balance aggregation.
            'realized_pnl' => (string) $this->db->table('user_daily_balance')->where('user_id', $userId)->sum('realized_pnl_cumulative'),
        ];
    }
}
