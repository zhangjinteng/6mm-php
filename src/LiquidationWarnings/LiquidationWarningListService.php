<?php

declare (strict_types=1);
namespace SixMm\Shared\LiquidationWarnings;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use RuntimeException;
use SixMm\Shared\Contracts\UserDataScope;
use SixMm\Shared\CurrentPositions\CurrentPositionListQuery;
use SixMm\Shared\CurrentPositions\CurrentPositionUserContextService;
use SixMm\Shared\Pagination\PageResult;
use SixMm\Shared\TradeFills\TradeFillOwnerScope;
use SixMm\Shared\HistoryOrders\HistoryOrderUserContextService;
final class LiquidationWarningListService
{
    private Closure $riskBatch;
    private Closure $select;
    /** riskBatch(float threshold, int limit, int lastUserId): iterable<array|object>. */
    public function __construct(private ConnectionInterface $connection, callable $riskBatch, callable $select, private string $database = 'freedex_history', private string $timezone = 'UTC', private int $batchSize = 500)
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $database) || $batchSize < 2) {
            throw new InvalidArgumentException('Invalid liquidation warning query configuration.');
        }
        new DateTimeZone($timezone);
        $this->riskBatch = Closure::fromCallable($riskBatch);
        $this->select = Closure::fromCallable($select);
    }
    public function search(CurrentPositionListQuery $query, UserDataScope $scope, float $threshold, ?TradeFillOwnerScope $owner = null): PageResult
    {
        if (!is_finite($threshold) || $threshold <= 0 || $threshold >= 1) {
            throw new InvalidArgumentException('Invalid risk threshold.');
        }
        $empty = fn() => new PageResult([], 0, $query->page(), $query->pageSize());
        // Resolve authorization before any ClickHouse selection. Never trust caller-supplied agent IDs.
        $userContext = new CurrentPositionUserContextService($this->connection);
        $users = null;
        if ($owner !== null) {
            if ($owner->isEmpty()) return $empty();
        } else {
            $users = $this->connection->table('users')->whereNull('users.deleted_at');
            $scope->apply($users, 'users.agent_id');
            if ($query->userType() !== null) $users->where('users.user_type', $query->userType());
            if (!(clone $users)->exists()) return $empty();
        }
        $candidates = $owner === null ? $this->fetchRisk($threshold) : $this->fetchOwnerRisk($threshold, $owner);
        if ($candidates === []) {
            return $empty();
        }
        // Platform's all-users scope can contain millions of accounts. Only
        // materialize authorization IDs for the current RPC risk candidates.
        $candidateIds = array_values(array_unique(array_column($candidates, 'user_id')));
        $allowed = $owner === null ? array_fill_keys(array_map('strval', $users->whereIn('users.user_id', $candidateIds)->pluck('users.user_id')->all()), true) : null;
        $risk = [];
        foreach ($candidates as $position) {
            if ($owner !== null ? !$owner->matches($position['home_site_id'] ?? null, $position['agent_id'] ?? null)
                : !isset($allowed[(string) $position['user_id']])) {
                continue;
            }
            if ($query->symbol() !== '' && stripos($position['symbol'], $query->symbol()) === false) {
                continue;
            }
            $risk[self::key($position, $owner !== null)] = $position;
        }
        if ($risk === []) {
            return $empty();
        }
        $tuples = [];
        foreach ($risk as $p) {
            $tuples[] = '(' . ($owner === null ? '' : (int) $p['home_site_id'] . ', ' . (int) $p['agent_id'] . ', ')
                . (int) $p['user_id'] . ', ' . self::quote($p['symbol']) . ', ' . (int) $p['margin_mode'] . ', ' . self::quote($p['position_side']) . ')';
        }
        $ownerTuple = $owner === null ? '' : 'o.home_site_id, o.agent_id, ';
        $where = ['o.status = 1', '(' . $ownerTuple . 'o.user_id, upperUTF8(o.symbol), o.margin_mode, lowerUTF8(o.position_side)) IN (' . implode(', ', $tuples) . ')'];
        if ($owner !== null) {
            $where[] = $owner->predicate('o');
            if ($query->userType() !== null) $where[] = 'o.user_type = ' . $query->userType();
        }
        if ($query->marginMode() !== null) {
            $where[] = 'o.margin_mode = ' . $query->marginMode();
        }
        if ($query->positionSide() !== '') {
            $where[] = 'lowerUTF8(o.position_side) = ' . self::quote($query->positionSide());
        }
        if ($query->leverage() !== null) {
            $where[] = 'o.leverage = ' . $query->leverage();
        }
        if ($query->keyword() !== '') {
            $matches = $owner === null ? $userContext->matchingPlatformUserIds($query->keyword(), $scope)
                : (new HistoryOrderUserContextService($this->connection))->matchingHistoricalPlatformUserIds($query->keyword());
            $parts = [];
            if ($matches !== []) {
                $parts[] = 'o.user_id IN (' . implode(', ', array_map('intval', $matches)) . ')';
            }
            if (ctype_digit($query->keyword()) && strlen($query->keyword()) <= 19 && bccomp($query->keyword(), (string) PHP_INT_MAX) <= 0) {
                $parts[] = 'o.position_id = ' . (int) $query->keyword();
                $parts[] = 'o.user_id = ' . (int) $query->keyword();
            }
            $where[] = $parts === [] ? '0' : '(' . implode(' OR ', $parts) . ')';
        }
        foreach ([$query->createdAtStart(), $query->createdAtEnd()] as $i => $value) {
            if ($value === '') {
                continue;
            }
            $date = (new DateTimeImmutable($value, new DateTimeZone($this->timezone)))->setTimezone(new DateTimeZone('UTC'));
            $where[] = 'o.created_at ' . ($i === 0 ? '>=' : '<=') . ' toDateTime64(' . self::quote($date->format('Y-m-d H:i:s.v')) . ", 3, 'UTC')";
        }
        $whereSql = implode(' AND ', $where);
        $table = $this->database . '.current_position_query';
        $count = ($this->select)("SELECT count() AS total FROM {$table} AS o WHERE {$whereSql} FORMAT JSONEachRow");
        $total = (int) ($count[0]['total'] ?? 0);
        if ($total === 0) {
            return $empty();
        }
        $tz = self::quote($this->timezone);
        $order = 'o.' . $query->orderBy() . ' ' . strtoupper($query->orderDirection());
        if ($query->orderBy() !== 'position_id') {
            $order .= ', o.position_id DESC';
        }
        $limit = $query->pageSize();
        $offset = ($query->page() - 1) * $limit;
        $ownerSelect = $owner === null ? '' : 'toString(o.home_site_id) AS home_site_id, ';
        $rows = ($this->select)("SELECT {$ownerSelect}toString(o.position_id) AS id, toString(o.position_id) AS position_id,\n            toString(o.user_id) AS user_id, o.user_type, toString(o.agent_id) AS agent_id,\n            o.symbol, o.margin_mode, o.position_side, o.position_mode, o.status, o.leverage,\n            o.quantity, o.frozen_quantity, o.entry_price, o.margin_price, o.funding_fee_accum,\n            NULL AS break_even_price, NULL AS next_funding_fee,\n            formatDateTime(toTimeZone(o.created_at, {$tz}), '%F %T') AS created_at,\n            formatDateTime(toTimeZone(o.updated_at, {$tz}), '%F %T') AS updated_at\n            FROM {$table} AS o WHERE {$whereSql} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset} FORMAT JSONEachRow");
        $rows = $owner === null ? $userContext->hydrateRows($rows, $scope)
            : (new HistoryOrderUserContextService($this->connection))->hydrateHistoricalRows($rows);
        foreach ($rows as &$row) {
            $identity = $row;
            $identity['user_id'] = $row['platform_user_id'];
            $p = $risk[self::key($identity, $owner !== null)] ?? null;
            if ($p === null) {
                throw new RuntimeException('Unexpected non-risk position in warning results.');
            }
            foreach (['quantity', 'entry_price', 'wallet_balance', 'margin_ratio', 'maintenance_margin'] as $field) {
                $row[$field] = $p[$field];
            }
            $row['margin_price'] = $p['margin_balance'];
            $row['pnl'] = $row['unrealized_pnl'] = $p['unrealized_pnl'];
            $row['liq_price'] = $p['liq_price'] === '' ? null : $p['liq_price'];
            $row['mark_price'] = $p['mark_price'] === '' ? null : $p['mark_price'];
            $row['position_value'] = $p['notional'] === '' ? null : $p['notional'];
        }
        unset($row);
        return new PageResult($rows, $total, $query->page(), $query->pageSize());
    }
    private function fetchOwnerRisk(float $threshold, TradeFillOwnerScope $owner): array
    {
        $pairs = $owner->owners();
        if (count($pairs) !== 1 || $pairs[0][0] === 0) return $this->fetchRisk($threshold, true);
        $cursor = ''; $positions = []; $seen = [];
        do {
            $batch = ($this->riskBatch)($threshold, $this->batchSize, 0, $pairs[0], $cursor);
            if (($batch['scope_applied'] ?? false) !== true) throw new RuntimeException('Risk service did not apply owner scope');
            foreach ($batch['positions'] ?? [] as $row) {
                $positions[] = $this->riskRow($row, true);
            }
            if (!($batch['has_more'] ?? false)) break;
            $next = $batch['next_cursor'] ?? '';
            if (!is_string($next) || $next === '' || $next === $cursor || isset($seen[$next])) throw new RuntimeException('Risk owner cursor cannot advance');
            $seen[$next] = true; $cursor = $next;
        } while (true);
        return $positions;
    }

    private function riskRow($raw, bool $owner): array
    {
        $read = static function (string $field) use ($raw) {
            if (is_array($raw)) return $raw[$field] ?? null;
            $method = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $field)));
            return method_exists($raw, $method) ? $raw->{$method}() : null;
        };
        $p = ['user_id' => (string) $read('user_id'), 'symbol' => strtoupper((string) $read('symbol')),
            'margin_mode' => (int) $read('margin_mode'), 'position_side' => strtolower((string) ($read('position_side') ?? ((int) $read('side') === 2 ? 'short' : 'long')))];
        foreach (['quantity', 'entry_price', 'margin_ratio', 'wallet_balance', 'margin_balance', 'liq_price', 'unrealized_pnl', 'maintenance_margin', 'mark_price', 'notional'] as $field) $p[$field] = (string) ($read($field) ?? '');
        if ($owner) { $p['home_site_id'] = $read('home_site_id'); $p['agent_id'] = $read('agent_id') ?? 0; }
        return $p;
    }

    private function fetchRisk(float $threshold, bool $owner = false): array
    {
        $cursor = 0;
        $positions = [];
        do {
            $batch = ($this->riskBatch)($threshold, $this->batchSize, $cursor);
            $count = 0;
            $max = $cursor;
            foreach ($batch as $raw) {
                ++$count;
                $read = static function (string $field) use ($raw) {
                    if (is_array($raw)) {
                        return $raw[$field] ?? null;
                    }
                    $method = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $field)));
                    return method_exists($raw, $method) ? $raw->{$method}() : null;
                };
                $id = (int) $read('user_id');
                if ($id <= $cursor) {
                    throw new RuntimeException('GetRiskPositions returned an invalid cursor.');
                }
                $side = $read('position_side') ?? ((int) $read('side') === 2 ? 'short' : 'long');
                $p = ['user_id' => (string) $id, 'symbol' => strtoupper((string) $read('symbol')), 'margin_mode' => (int) $read('margin_mode'), 'position_side' => strtolower((string) $side)];
                foreach (['quantity', 'entry_price', 'margin_ratio', 'wallet_balance', 'margin_balance', 'liq_price', 'unrealized_pnl', 'maintenance_margin', 'mark_price', 'notional'] as $f) {
                    $p[$f] = (string) ($read($f) ?? '');
                }
                if ($owner) $p = $this->riskRow($raw, true);
                $positions[self::key($p, $owner)] = $p;
                $max = max($max, $id);
            }
            if ($count < $this->batchSize) {
                break;
            }
            $next = $max - 1;
            if ($next <= $cursor) {
                throw new RuntimeException('GetRiskPositions cursor cannot advance safely.');
            }
            $cursor = $next;
        } while (true);
        return array_values($positions);
    }
    private static function key(array $row, bool $owner = false): string
    {
        return implode(':', [...($owner ? [(string) ($row['home_site_id'] ?? ''), (string) ($row['agent_id'] ?? '')] : []), (string) $row['user_id'], strtoupper((string) $row['symbol']), (string) $row['margin_mode'], strtolower((string) $row['position_side'])]);
    }
    private static function quote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}
