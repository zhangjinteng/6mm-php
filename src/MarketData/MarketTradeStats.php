<?php

declare(strict_types=1);

namespace SixMm\Shared\MarketData;

use InvalidArgumentException;

final class MarketTradeStats
{
    /** Public market totals, not tenant/user reports. Scope symbol visibility before calling. */
    public function query(array $symbols, string $database, callable $select): array
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $database)) throw new InvalidArgumentException('Invalid market database');
        $symbols = array_values(array_unique(array_filter(array_map('strval', $symbols), static fn ($symbol) => $symbol !== '')));
        if ($symbols === []) return [];
        if (count($symbols) > 40) throw new InvalidArgumentException('Too many market symbols');
        foreach ($symbols as $symbol) {
            if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $symbol)) throw new InvalidArgumentException('Invalid market symbol');
        }
        $values = implode(', ', array_map(static fn ($symbol) => "'" . $symbol . "'", $symbols));
        $rows = $select("SELECT symbol,
            toString(sum(toDecimal256OrZero(notional, 18))) AS trade_volume_24h,
            toString(sum(toDecimal256OrZero(quantity, 18))) AS trade_quantity_24h
            FROM {$database}.history_trades
            WHERE symbol IN ({$values})
              AND traded_at >= now64(3, 'UTC') - INTERVAL 24 HOUR
              AND traded_at < now64(3, 'UTC')
            GROUP BY symbol FORMAT JSONEachRow");
        $result = [];
        foreach ($rows as $row) {
            $symbol = (string) ($row['symbol'] ?? '');
            if (!in_array($symbol, $symbols, true)) continue;
            $result[$symbol] = ['trade_volume_24h' => (string) ($row['trade_volume_24h'] ?? '0'),
                'trade_quantity_24h' => (string) ($row['trade_quantity_24h'] ?? '0')];
        }
        return $result;
    }
}
