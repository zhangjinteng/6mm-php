<?php

declare(strict_types=1);

namespace SixMm\Shared\CurrentPositions;

use Brick\Math\BigDecimal;
use Closure;
use SixMm\Shared\TradeFills\TradeFillOwnerScope;

final class PositionSummaryQuery
{
    public function __construct(private Closure $select, private string $database, private Closure $quote)
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $database)) {
            throw new \InvalidArgumentException('Invalid history database');
        }
    }

    public function search(array $params, TradeFillOwnerScope $owner): array
    {
        $pageSize = min(40, max((int)($params["page_size"] ?? 15), 1));
        $pageNo = max((int)($params["page_no"] ?? 1), 1);
        $offset = ($pageNo - 1) * $pageSize;
        $userType = $params['user_type'] ?? null;

        $where = [
            'o.status IN (1, 3)',
            $owner->predicate(),
        ];
        if ($userType !== null && $userType !== '') {
            $where[] = 'o.user_type = ' . (int) $userType;
        }

        if (($params['sym'] ?? 'All') != 'All') {
            $where[] = 'o.symbol = ' . ($this->quote)($params['sym']);
        }

        if (($params['position_side'] ?? 'All') != 'All') {
            $where[] = 'lowerUTF8(o.position_side) = ' . ($this->quote)(strtolower($params['position_side']));
        }

        $whereSql = implode("\n              AND ", $where);
        $database = $this->database;

        $countSql = "
            SELECT count() AS total
            FROM (
                SELECT o.symbol
                FROM {$database}.current_position_query AS o
                WHERE {$whereSql}
                GROUP BY o.symbol
            )
            FORMAT JSONEachRow
        ";
        $countRows = ($this->select)($countSql);
        $total = (int)($countRows[0]['total'] ?? 0);

        $sortField = $params['sort_field'] ?? '';
        $sortOrder = in_array(strtolower($params['sort_order'] ?? ''), ['asc', 'desc'], true)
            ? strtoupper($params['sort_order']) : 'DESC';
        $orderBy = $sortField === 'total_count' ? "total_count {$sortOrder}, symbol ASC" : 'symbol ASC';

        $sql = "
            SELECT
                o.symbol,
                count() AS total_count,
                uniqExact(o.user_id) AS account_count,
                toString(sum(toDecimal256OrZero(o.quantity, 18))) AS total_quantity,
                countIf(lowerUTF8(o.position_side) = 'long') AS long_count,
                toString(sumIf(toDecimal256OrZero(o.quantity, 18), lowerUTF8(o.position_side) = 'long')) AS long_quantity,
                countIf(lowerUTF8(o.position_side) = 'short') AS short_count,
                toString(sumIf(toDecimal256OrZero(o.quantity, 18), lowerUTF8(o.position_side) = 'short')) AS short_quantity,
                toString(sumIf(toDecimal256OrZero(o.quantity, 18) * toDecimal256OrZero(o.entry_price, 18), lowerUTF8(o.position_side) = 'long')) AS long_entry_value,
                toString(sumIf(toDecimal256OrZero(o.quantity, 18) * toDecimal256OrZero(o.entry_price, 18), lowerUTF8(o.position_side) = 'short')) AS short_entry_value
            FROM {$database}.current_position_query AS o
            WHERE {$whereSql}
            GROUP BY o.symbol
            ORDER BY {$orderBy}
            LIMIT {$pageSize}
            OFFSET {$offset}
            FORMAT JSONEachRow
        ";

        $rows = ($this->select)($sql);
        foreach ($rows as &$row) {
            $row['total_count'] = (int)($row['total_count'] ?? 0);
            $row['account_count'] = (int)($row['account_count'] ?? 0);
            $row['long_count'] = (int)($row['long_count'] ?? 0);
            $row['short_count'] = (int)($row['short_count'] ?? 0);
            $row['total_quantity'] = BigDecimal::of($row['total_quantity'] ?? 0)->toFloat();
            $row['long_quantity'] = BigDecimal::of($row['long_quantity'] ?? 0)->toFloat();
            $row['short_quantity'] = BigDecimal::of($row['short_quantity'] ?? 0)->toFloat();
            $row['long_entry_value'] = BigDecimal::of($row['long_entry_value'] ?? 0)->toFloat();
            $row['short_entry_value'] = BigDecimal::of($row['short_entry_value'] ?? 0)->toFloat();
        }
        unset($row);

        return [
            "lists" => $rows,
            "page_no" => $pageNo,
            "page_size" => $pageSize,
            "count" => $total,
            "extend" => [],
        ];
    }
}
