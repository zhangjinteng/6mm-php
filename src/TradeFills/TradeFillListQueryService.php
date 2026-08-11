<?php

declare(strict_types=1);

namespace SixMm\Shared\TradeFills;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SixMm\Shared\Pagination\PageResult;
use Throwable;

final class TradeFillListQueryService
{
    private const USER_TYPES = [1, 2, 3];

    public function __construct(
        private TradeFillQueryExecutor $executor,
        private string $database = 'freedex_history',
        private string $timezone = 'UTC'
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->database)) {
            throw new InvalidArgumentException('Invalid ClickHouse database identifier.');
        }
        if (!in_array($this->timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('Invalid time zone.');
        }
    }

    /**
     * @param array<int, int|string> $scopedUserIds
     * @param array<int, int|string> $currentUserTypeUserIds
     * @param array<int, int|string> $keywordUserIds
     * @return PageResult<array<string, mixed>>
     */
    public function search(
        TradeFillListQuery $query,
        array $scopedUserIds,
        array $currentUserTypeUserIds = [],
        array $keywordUserIds = []
    ): PageResult {
        $scopedUserIds = $this->normalizeIds($scopedUserIds);
        if ($scopedUserIds === []) {
            return new PageResult([], 0, $query->page(), $query->pageSize());
        }

        $currentUserTypeUserIds = array_values(array_intersect(
            $this->normalizeIds($currentUserTypeUserIds),
            $scopedUserIds
        ));
        $keywordUserIds = array_values(array_intersect(
            $this->normalizeIds($keywordUserIds),
            $scopedUserIds
        ));
        $where = $this->whereConditions($query, $scopedUserIds, $currentUserTypeUserIds, $keywordUserIds);
        $hasUserTypeFilter = $query->userType() !== null;
        $countJoins = $hasUserTypeFilter ? $this->identityJoins($scopedUserIds, true) : '';
        $listJoins = $this->identityJoins($scopedUserIds, $hasUserTypeFilter);
        $selectedUserType = $hasUserTypeFilter
            ? $this->historicalUserTypeExpression()
            : 'nullIf(order_identity.user_type, 0)';
        $whereSql = implode("\n              AND ", $where);
        $countSql = "
            SELECT count() AS aggregate
            FROM {$this->database}.history_trade_fills AS o
            {$countJoins}
            WHERE {$whereSql}
            FORMAT JSONEachRow
        ";
        $total = (int) ($this->executor->select($countSql)[0]['aggregate'] ?? 0);

        if ($total === 0) {
            return new PageResult([], 0, $query->page(), $query->pageSize());
        }

        [$orderExpression, $orderDirection] = $this->orderBy($query);
        $timezone = $this->quote($this->timezone);
        $limit = $query->pageSize();
        $offset = ($query->page() - 1) * $limit;
        $listSql = "
            SELECT
                toString(o.fill_id) AS fill_id,
                toString(o.trade_id) AS trade_id,
                toString(o.order_id) AS order_id,
                toString(o.user_id) AS user_id,
                {$selectedUserType} AS user_type,
                o.symbol,
                toString(o.position_id) AS position_id,
                o.side,
                o.position_side,
                o.price,
                o.quantity,
                o.notional AS trade_value,
                toString(abs(toDecimal128OrZero(o.fee, 18))) AS handling_fee,
                o.realized_pnl,
                o.role_type,
                formatDateTime(toTimeZone(o.traded_at, {$timezone}), '%F %T') AS trade_time
            FROM {$this->database}.history_trade_fills AS o
            {$listJoins}
            WHERE {$whereSql}
            ORDER BY isNull({$orderExpression}) ASC, {$orderExpression} {$orderDirection}, o.fill_id DESC
            LIMIT {$limit} OFFSET {$offset}
            FORMAT JSONEachRow
        ";

        $rows = $this->executor->select($listSql);
        $this->appendPositionRelations($rows);

        return new PageResult($rows, $total, $query->page(), $query->pageSize());
    }

    /** @return string[] */
    private function whereConditions(
        TradeFillListQuery $query,
        array $scopedUserIds,
        array $currentUserTypeUserIds,
        array $keywordUserIds
    ): array {
        $where = ['o.user_id IN (' . implode(', ', $scopedUserIds) . ')'];

        if ($query->positionId() !== null) {
            $where[] = 'o.position_id = ' . $query->positionId();
        }
        if ($query->placeType() === 'LIQUIDATION') {
            $where[] = "upperUTF8(o.place_type) = 'LIQUIDATION'";
        }
        if ($query->keyword() !== '') {
            $keywordConditions = [];
            if ($keywordUserIds !== []) {
                $keywordConditions[] = 'o.user_id IN (' . implode(', ', $keywordUserIds) . ')';
            }
            if (ctype_digit($query->keyword())) {
                $identifier = (int) $query->keyword();
                $keywordConditions[] = "o.order_id = {$identifier}";
                $keywordConditions[] = "o.position_id = {$identifier}";
            }
            $where[] = $keywordConditions === [] ? '0' : '(' . implode(' OR ', $keywordConditions) . ')';
        }
        if ($query->symbol() !== '') {
            $where[] = 'positionCaseInsensitive(o.symbol, ' . $this->quote($query->symbol()) . ') > 0';
        }
        if ($query->marginMode() !== null) {
            $where[] = "o.position_id IN (
                SELECT position_id
                FROM {$this->database}.current_position_query
                WHERE margin_mode = {$query->marginMode()}
            )";
        }
        if ($query->side() !== '') {
            $where[] = 'lowerUTF8(o.side) = ' . $this->quote($query->side());
        }
        if ($query->roleType() !== '') {
            $where[] = 'lowerUTF8(o.role_type) = ' . $this->quote($query->roleType());
        }

        [$startUtc, $endExclusiveUtc] = $this->utcTimeRange(
            $query->tradeTimeStart(),
            $query->tradeTimeEnd()
        );
        if ($startUtc !== null) {
            $where[] = 'o.traded_at >= toDateTime64(' . $this->quote($startUtc) . ", 3, 'UTC')";
        }
        if ($endExclusiveUtc !== null) {
            $where[] = 'o.traded_at < toDateTime64(' . $this->quote($endExclusiveUtc) . ", 3, 'UTC')";
        }

        if ($query->userType() !== null) {
            $historicalUserType = $this->historicalUserTypeExpression();
            $conditions = ["{$historicalUserType} = {$query->userType()}"];
            if ($currentUserTypeUserIds !== []) {
                $conditions[] = sprintf(
                    '(isNull(%s) AND o.user_id IN (%s))',
                    $historicalUserType,
                    implode(', ', $currentUserTypeUserIds)
                );
            }
            $where[] = '(' . implode(' OR ', $conditions) . ')';
        }

        return $where;
    }

    private function identityJoins(array $userIds, bool $includePositionIdentities): string
    {
        $ids = implode(', ', $userIds);
        $joins = "
            ANY LEFT JOIN (
                SELECT h.user_id, h.order_id, argMax(h.user_type, h.updated_at) AS user_type
                FROM {$this->database}.history_order_query AS h
                WHERE h.user_id IN ({$ids})
                GROUP BY h.user_id, h.order_id
            ) AS order_identity
                ON order_identity.user_id = o.user_id
               AND order_identity.order_id = o.order_id
        ";
        if (!$includePositionIdentities) {
            return $joins;
        }

        return $joins . "
            ANY LEFT JOIN (
                SELECT p.user_id, p.position_id,
                    argMax(p.user_type, tuple(p.updated_at, p.ingested_at)) AS user_type
                FROM {$this->database}.current_position_query AS p
                WHERE p.user_id IN ({$ids})
                GROUP BY p.user_id, p.position_id
            ) AS current_position_identity
                ON current_position_identity.user_id = o.user_id
               AND current_position_identity.position_id = o.position_id
            ANY LEFT JOIN (
                SELECT p.user_id, p.position_id,
                    argMax(p.user_type, tuple(p.source_position_version, p.materialized_at)) AS user_type
                FROM {$this->database}.history_position_query AS p
                WHERE p.user_id IN ({$ids})
                GROUP BY p.user_id, p.position_id
            ) AS history_position_identity
                ON history_position_identity.user_id = o.user_id
               AND history_position_identity.position_id = o.position_id
        ";
    }

    private function historicalUserTypeExpression(): string
    {
        return 'coalesce('
            . 'nullIf(order_identity.user_type, 0), '
            . 'nullIf(current_position_identity.user_type, 0), '
            . 'nullIf(history_position_identity.user_type, 0)'
            . ')';
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function appendPositionRelations(array &$rows): void
    {
        $positionIds = $this->rowIds($rows, 'position_id');
        $positions = $this->positionsByIds($positionIds);

        foreach ($rows as &$row) {
            $position = $positions[(string) ($row['position_id'] ?? '')] ?? null;
            $row['user_type'] = $this->validUserType($row['user_type'] ?? null)
                ?? $this->validUserType($position['user_type'] ?? null);
            $row['position'] = $position ?: [
                'id' => $row['position_id'] ?? null,
                'position_id' => $row['position_id'] ?? null,
                'user_id' => $row['user_id'] ?? null,
                'margin_mode' => null,
                'leverage' => null,
            ];
        }
        unset($row);
    }

    /** @return array<string, array<string, mixed>> */
    private function positionsByIds(array $positionIds): array
    {
        $positionIds = $this->normalizeIds($positionIds);
        if ($positionIds === []) {
            return [];
        }
        $ids = implode(', ', $positionIds);
        $currentSql = "
            SELECT
                toString(position_id) AS id,
                toString(position_id) AS position_id,
                toString(user_id) AS user_id,
                toString(public_user_id) AS public_user_id,
                user_type, margin_mode, leverage
            FROM {$this->database}.current_position_query
            WHERE position_id IN ({$ids})
            FORMAT JSONEachRow
        ";
        $positions = $this->keyPositions($this->executor->select($currentSql), true);
        $missingIds = array_values(array_diff(array_map('strval', $positionIds), array_keys($positions)));
        if ($missingIds === []) {
            return $positions;
        }

        $historySql = "
            SELECT
                toString(position_id) AS id,
                toString(position_id) AS position_id,
                toString(user_id) AS user_id,
                user_type, margin_mode, leverage
            FROM {$this->database}.history_position_query FINAL
            WHERE position_id IN (" . implode(', ', array_map('intval', $missingIds)) . ")
            FORMAT JSONEachRow
        ";

        return $positions + $this->keyPositions($this->executor->select($historySql), false);
    }

    /** @return array<string, array<string, mixed>> */
    private function keyPositions(array $rows, bool $hasPublicUserId): array
    {
        $positions = [];
        foreach ($rows as $row) {
            $positionId = (string) ($row['position_id'] ?? $row['id'] ?? '');
            if ($positionId === '') {
                continue;
            }
            $positions[$positionId] = [
                'id' => $row['id'] ?? $positionId,
                'position_id' => $positionId,
                'user_id' => $row['user_id'] ?? null,
                'public_user_id' => $hasPublicUserId ? ($row['public_user_id'] ?? null) : null,
                'user_type' => $row['user_type'] ?? null,
                'margin_mode' => $row['margin_mode'] ?? null,
                'leverage' => $row['leverage'] ?? null,
            ];
        }
        return $positions;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function utcTimeRange(string $start, string $end): array
    {
        $start = trim($start);
        $end = trim($end);
        $startUtc = $start === '' ? null : $this->parseTime($start)->setTimezone(new DateTimeZone('UTC'));
        $endExclusiveUtc = null;
        if ($end !== '') {
            $parsedEnd = $this->parseTime($end);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
                $parsedEnd = $parsedEnd->modify('+1 day')->setTime(0, 0);
            } elseif (preg_match('/\.\d+(?:Z|[+-]\d{2}:?\d{2})?$/i', $end)) {
                $parsedEnd = $parsedEnd->modify('+1 microsecond');
            } else {
                $parsedEnd = $parsedEnd->modify('+1 second');
            }
            $endExclusiveUtc = $parsedEnd->setTimezone(new DateTimeZone('UTC'));
        }
        if ($startUtc !== null && $endExclusiveUtc !== null && $startUtc >= $endExclusiveUtc) {
            throw new InvalidArgumentException('The time range start must be before its end.');
        }
        return [
            $startUtc?->format('Y-m-d H:i:s.v'),
            $endExclusiveUtc?->format('Y-m-d H:i:s.v'),
        ];
    }

    private function parseTime(string $value): DateTimeImmutable
    {
        try {
            $timezone = preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $value)
                ? null
                : new DateTimeZone($this->timezone);
            return new DateTimeImmutable($value, $timezone);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Invalid time value.', 0, $exception);
        }
    }

    /** @return array{0: string, 1: string} */
    private function orderBy(TradeFillListQuery $query): array
    {
        $expressions = [
            'trade_time' => 'o.traded_at', 'order_id' => 'o.order_id',
            'position_id' => 'o.position_id', 'symbol' => 'o.symbol', 'side' => 'o.side',
            'quantity' => 'o.quantity', 'price' => 'o.price', 'trade_value' => 'o.notional',
            'handling_fee' => 'abs(toDecimal128OrZero(o.fee, 18))',
            'role_type' => 'o.role_type', 'realized_pnl' => 'o.realized_pnl',
        ];
        return [$expressions[$query->orderBy()] ?? $expressions['trade_time'], strtoupper($query->orderDirection())];
    }

    private function validUserType(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        $value = (int) $value;
        return in_array($value, self::USER_TYPES, true) ? $value : null;
    }

    /** @return int[] */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
    }

    /** @return string[] */
    private function rowIds(array $rows, string $key): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => (string) ($row[$key] ?? ''),
            $rows
        ))));
    }

    private function quote(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }
}
