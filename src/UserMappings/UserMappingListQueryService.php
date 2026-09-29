<?php

declare(strict_types=1);

namespace SixMm\Shared\UserMappings;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use SixMm\Shared\Contracts\UserDataScope;
use SixMm\Shared\Pagination\PageResult;

final class UserMappingListQueryService
{
    private const STATUS_SQL = "CASE WHEN UPPER(b.bind_status) IN ('BOUND', 'LOCKED', 'NORMAL', 'SUCCESS') THEN 'normal' "
        . "WHEN UPPER(b.bind_status) IN ('PROCESSING', 'PENDING', 'SYNCING') THEN 'processing' ELSE 'abnormal' END";
    private const EXCEPTION_SQL = "COALESCE(NULLIF(b.extra_info->>'exception_type', ''), "
        . "NULLIF(b.extra_info->>'exception_code', ''), NULLIF(b.extra_info->>'anomaly_type', ''), "
        . "NULLIF(b.extra_info->>'error_type', ''), NULLIF(b.extra_info->>'last_error', ''), '')";
    private const EXCEPTION_CODE_SQL = "CASE "
        . "WHEN UPPER(" . self::EXCEPTION_SQL . ") LIKE '%DUPLICATE%' OR " . self::EXCEPTION_SQL . " LIKE '%重复映射%' THEN 'duplicate_mapping' "
        . "WHEN UPPER(" . self::EXCEPTION_SQL . ") LIKE '%USER_NOT_FOUND%' OR " . self::EXCEPTION_SQL . " LIKE '%用户不存在%' THEN 'user_not_found' "
        . "WHEN UPPER(" . self::EXCEPTION_SQL . ") LIKE '%SIGNATURE%' OR " . self::EXCEPTION_SQL . " LIKE '%签名失败%' THEN 'signature_failed' "
        . "WHEN UPPER(" . self::EXCEPTION_SQL . ") LIKE '%SYNC%' OR " . self::EXCEPTION_SQL . " LIKE '%同步失败%' THEN 'sync_failed' "
        . "ELSE LOWER(" . self::EXCEPTION_SQL . ") END";

    public function __construct(private ConnectionInterface $connection, private string $timezone = 'UTC')
    {
    }

    /** @return PageResult<array<string, mixed>> */
    public function search(UserMappingListQuery $criteria, UserDataScope $scope): PageResult
    {
        $query = $this->connection->table('agent_user_bindings AS b')
            ->leftJoin('users AS u', static function (JoinClause $join): void {
                $join->on('u.user_id', '=', 'b.platform_user_id')
                    ->on('u.agent_id', '=', 'b.agent_id')
                    ->whereNull('u.deleted_at');
            });
        $scope->apply($query, 'b.agent_id');
        $this->applyFilters($query, $criteria);

        $total = (int) (clone $query)->count('b.id');
        $sortColumns = [
            'mapping_id' => 'b.id',
            'username' => 'u.username',
            'agent_user_id' => 'b.agent_user_id',
            'user_uid' => 'u.public_user_id',
            'mapping_status' => $this->connection->raw(self::STATUS_SQL),
            'exception_type' => $this->connection->raw(self::EXCEPTION_CODE_SQL),
            'recent_sync_at' => 'b.updated_at',
            'agent_id' => 'b.agent_id',
        ];
        $rows = $query->select([
                'b.id AS mapping_id', 'b.agent_id', 'b.agent_user_id',
                'b.platform_user_id', 'b.updated_at AS recent_sync_at',
                'u.public_user_id AS user_uid', 'u.username', 'u.nick_name AS nice_name',
            ])
            ->selectRaw(self::STATUS_SQL . ' AS mapping_status')
            ->selectRaw(self::EXCEPTION_SQL . ' AS exception_type')
            ->selectRaw(self::EXCEPTION_CODE_SQL . ' AS exception_code')
            ->orderBy($sortColumns[$criteria->orderBy()], $criteria->orderDirection())
            ->orderBy('b.id', 'desc')
            ->forPage($criteria->page(), $criteria->pageSize())
            ->get();

        $items = $rows->map(function (object $row): array {
            return [
                'id' => (int) $row->mapping_id,
                'mapping_id' => (int) $row->mapping_id,
                'agent_id' => (int) $row->agent_id,
                'agent_user_id' => (string) $row->agent_user_id,
                'platform_user_id' => (int) $row->platform_user_id,
                'user_uid' => $row->user_uid,
                'username' => $row->username,
                'nice_name' => $row->nice_name,
                'mapping_status' => (string) $row->mapping_status,
                'exception_type' => (string) $row->exception_type,
                'exception_code' => (string) $row->exception_code,
                'recent_sync_at' => $row->recent_sync_at === null ? null
                    : Carbon::parse((string) $row->recent_sync_at, 'UTC')
                        ->setTimezone($this->timezone)->format('Y-m-d H:i:s'),
            ];
        })->all();

        return new PageResult($items, $total, $criteria->page(), $criteria->pageSize());
    }

    private function applyFilters(Builder $query, UserMappingListQuery $criteria): void
    {
        if ($criteria->keyword() !== '') {
            $keyword = $criteria->keyword();
            $pattern = '%' . strtolower(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword)) . '%';
            $query->where(static function (Builder $nested) use ($pattern): void {
                $nested->whereRaw('LOWER(b.agent_user_id) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(COALESCE(u.username, ?)) LIKE ?', ['', $pattern])
                    ->orWhereRaw('LOWER(COALESCE(u.nick_name, ?)) LIKE ?', ['', $pattern])
                    ->orWhereRaw('CAST(b.id AS TEXT) LIKE ?', [$pattern])
                    ->orWhereRaw('CAST(u.public_user_id AS TEXT) LIKE ?', [$pattern]);
            });
        }
        if ($criteria->mappingStatus() !== '') {
            $query->whereRaw(self::STATUS_SQL . ' = ?', [$criteria->mappingStatus()]);
        }
        if ($criteria->exceptionType() !== '') {
            $query->whereRaw(self::EXCEPTION_CODE_SQL . ' = ?', [$criteria->exceptionType()]);
        }
        if ($criteria->startTime() !== null) {
            $query->where('b.updated_at', '>=', $criteria->startTime());
        }
        if ($criteria->endTimeExclusive() !== null) {
            $query->where('b.updated_at', '<', $criteria->endTimeExclusive());
        }
    }
}
