<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use SixMm\Shared\MarketData\MarketCatalog;
use SixMm\Shared\MarketData\MarketHistory;
use SixMm\Shared\MarketData\MarketTradeStats;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    ++$checks;
    if (!$ok) throw new RuntimeException($message);
};
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
$db->statement('CREATE TABLE symbol_config (id INTEGER PRIMARY KEY, symbol TEXT, sort INTEGER, home_site_id INTEGER)');
$db->table('symbol_config')->insert([
    ['id' => 1, 'symbol' => 'BTCUSDT', 'sort' => 0, 'home_site_id' => 0],
    ['id' => 2, 'symbol' => 'ETHUSDT', 'sort' => 2, 'home_site_id' => 0],
    ['id' => 3, 'symbol' => 'SOLUSDT', 'sort' => null, 'home_site_id' => 0],
    ['id' => 4, 'symbol' => 'OTHERUSDT', 'sort' => -1, 'home_site_id' => 2],
]);
$catalog = new MarketCatalog();
$query = $db->table('symbol_config')->where('home_site_id', 0);
$originalQuery = [$query->toSql(), $query->getBindings()];
$funding = ['ETHUSDT' => ['funding_interval_hours' => 4]];
$page = $catalog->preview($query, ['page_size' => 1, 'page_no' => 2], $funding, 21600);
$check($page['count'] === 3 && array_column($page['lists'], 'symbol') === ['ETHUSDT'], 'Filter and sort must precede pagination');
$check($page['lists'][0]['remain_seconds'] === 7200, 'Funding countdown remains compatible');
$check($catalog->preview($query, ['keyword' => 'eth'], $funding, 0)['count'] === 1, 'Keyword search is case insensitive');
$check($catalog->preview($query, ['sym' => 'OTHERUSDT'], $funding, 0)['count'] === 0, 'Authorized catalog must never broaden');
$check([$query->toSql(), $query->getBindings()] === $originalQuery, 'Preview must not mutate the host catalog query');

CarbonImmutable::setTestNow('2026-11-16 06:22:00+00');
$connection = new class {
    public array $queries = [];
    public function transaction(callable $callback) { return $callback(); }
    public function statement(string $sql) { $this->queries[] = [$sql, []]; }
    public function selectOne(string $sql, array $bindings) { $this->queries[] = [$sql, $bindings]; return (object) ['total' => 90]; }
    public function select(string $sql, array $bindings) {
        $this->queries[] = [$sql, $bindings];
        return [(object) ['bucket' => '2026-11-16 06:00:00+00', 'symbol' => 'BTCUSDT', 'open' => '0.00000001000', 'high' => '2', 'low' => '0', 'close' => '1.50']];
    }
};
$opened = 0;
$history = new MarketHistory(static function ($name) use ($connection, &$opened) { ++$opened; return $connection; }, static fn () => ['BTCUSDT']);
$result = $history->lists(['metric' => 'index_price', 'page_no' => 3, 'page_size' => 30], ['BTCUSDT']);
$check($result['count'] === 90 && $result['lists'][0]['open'] === '0.00000001', 'Total and decimal precision must survive extraction');
$sql = $connection->queries[3];
$check(str_contains($sql[0], 'AND symbol IN (?)') && in_array('BTCUSDT', $sql[1], true), 'Scope filtering must occur inside the history SQL');
$check(array_slice($sql[1], -2) === [30, 60], 'Pagination must execute on the upstream query');
$before = $opened;
$check($history->lists(['metric' => 'index_price'], [])['count'] === 0, 'Empty scope must not mean global scope');
$check($history->lists(['metric' => 'index_price', 'symbol' => 'ETHUSDT'], ['BTCUSDT'])['count'] === 0, 'Unknown scoped symbols return no rows');
$check($opened === $before, 'Denied or empty scope never opens the market connection');
foreach ([['interval' => '1m;DROP'], ['metric' => 'other'], ['range' => '1y']] as $invalid) {
    try { $history->lists($invalid); throw new RuntimeException('Invalid identifier accepted'); }
    catch (InvalidArgumentException $expected) { $check(true, 'Invalid history identifiers rejected'); }
}

$stats = new MarketTradeStats();
$calls = [];
$result = $stats->query(['BTCUSDT'], 'freedex_history', static function ($sql) use (&$calls) {
    $calls[] = $sql;
    return [['symbol' => 'BTCUSDT', 'trade_volume_24h' => '123.456', 'trade_quantity_24h' => '0.0001'], ['symbol' => 'FOREIGN', 'trade_volume_24h' => '1']];
});
$check(array_keys($result) === ['BTCUSDT'] && $result['BTCUSDT']['trade_volume_24h'] === '123.456', 'Only requested symbols and exact decimal values are returned');
$check(str_contains($calls[0], "symbol IN ('BTCUSDT')") && str_contains($calls[0], 'INTERVAL 24 HOUR'), '24h market aggregation remains unchanged');
try { $stats->query(["BTC' OR 1=1"], 'freedex_history', static fn () => []); throw new RuntimeException('SQL injection accepted'); }
catch (InvalidArgumentException $expected) { $check(true, 'Symbol identifiers validated'); }
try { $stats->query(['BTCUSDT'], 'invalid.database', static fn () => []); throw new RuntimeException('Database injection accepted'); }
catch (InvalidArgumentException $expected) { $check(true, 'Database identifiers validated'); }
CarbonImmutable::setTestNow();
echo "market data: {$checks} checks passed\n";
