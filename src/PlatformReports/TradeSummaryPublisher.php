<?php
declare(strict_types=1);
namespace SixMm\Shared\PlatformReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use SixMm\Shared\FuturesReports\{SourceDirectory, TradeReportSchema};
use SixMm\Shared\PredictionReports\PredictionOrderSource;

/** Publishes from the existing futures report and read-only prediction orders, never ClickHouse. */
final class TradeSummaryPublisher
{
    public function __construct(private ConnectionInterface $db, private TradeSummaryStore $store,
        private ?PredictionOrderSource $predictionSource) {}

    public function publish(string $date, ?array $predictionFacts = null): int
    {
        try { $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC'); }
        catch (\Throwable $e) { throw new InvalidArgumentException('统计日期格式错误', 0, $e); }
        if (!$day || $day->toDateString() !== $date || $day->greaterThan(CarbonImmutable::now('UTC')->startOfDay())) throw new InvalidArgumentException('统计日期格式错误或在未来');
        if ($predictionFacts === null && $this->predictionSource === null) throw new InvalidArgumentException('预测订单数据源未配置');
        $facts = $predictionFacts ?? $this->predictionSource->read($day);
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $row): string => (string) ($row['user_id'] ?? ''), $facts),
            static fn (string $id): bool => preg_match('/^[1-9]\d*$/D', $id) === 1)));
        $directory = (new SourceDirectory($this->db))->load($ids);
        $public = $this->db->getDriverName() === 'pgsql' ? 'public.' : '';
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($this->db->table($public . 'users')->whereIn('user_id', $chunk)->get(['user_id', 'user_type']) as $user) {
                if (isset($directory['users'][(string) $user->user_id])) $directory['users'][(string) $user->user_id]['user_type'] = (int) $user->user_type;
            }
        }
        $agents = $this->db->table($public . 'agents')->whereNull('deleted_at')->pluck('pid', 'id')->all();
        $directory['roots'] = [];
        foreach ($agents as $id => $_) {
            $root = (string) $id; $seen = [];
            while (isset($agents[$root]) && (string) $agents[$root] !== '0') {
                if (isset($seen[$root])) { $root = ''; break; }
                $seen[$root] = true; $root = (string) $agents[$root];
            }
            if (isset($directory['agents'][$root])) $directory['roots'][(string) $id] = $root;
        }
        $query = $this->db->table($public . TradeReportSchema::TABLE)->where('stat_date', $date)->where('symbol', '')
            ->select(['source_type', 'source_id', 'trading_user_count', 'fill_count', 'status'])
            ->selectRaw('CAST(trade_amount AS TEXT) AS trade_amount');
        // Project only user identities, never decode the very large execution/order JSON maps.
        $query->selectRaw($this->db->getDriverName() === 'pgsql'
            ? "(identities->'users')::text AS contract_users" : "json_extract(identities, '$.users') AS contract_users");
        $contracts = $query->get()->map(static fn (object $row): array => (array) $row)->all();
        $rows = (new TradeSummaryAggregator())->build($date, $contracts, $facts, $directory,
            $day->lessThan(CarbonImmutable::now('UTC')->startOfDay()));
        return $this->store->replace($date, $rows);
    }
}
