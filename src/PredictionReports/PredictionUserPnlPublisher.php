<?php

declare(strict_types=1);

namespace SixMm\Shared\PredictionReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use SixMm\Shared\FuturesReports\SourceDirectory;

/** One platform producer publishes merchant, tenant and platform rows from the same source facts. */
final class PredictionUserPnlPublisher
{
    public function __construct(private ConnectionInterface $database, private ?PredictionOrderSource $source,
        private PredictionUserPnlStore $store) {}

    public function publish(string $date, ?array $sourceFacts = null): int
    {
        try { $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC'); }
        catch (\Throwable $e) { throw new InvalidArgumentException('统计日期格式错误', 0, $e); }
        if (!$day || $day->toDateString() !== $date || $day->greaterThan(CarbonImmutable::now('UTC')->startOfDay())) {
            throw new InvalidArgumentException('统计日期格式错误或在未来');
        }
        if ($sourceFacts === null && $this->source === null) throw new InvalidArgumentException('预测订单数据源未配置');
        $facts = $sourceFacts ?? $this->source->read($day);
        $ids = [];
        foreach ($facts as $fact) $ids[(string) ($fact['user_id'] ?? '')] = true;
        unset($ids['']);
        $directory = (new SourceDirectory($this->database))->load(array_keys($ids));
        $roots = $this->agentRoots($directory['agents']);
        $robots = $this->robotUsers(array_keys($ids));
        $rows = (new PredictionUserPnlAggregator())->build($date, $facts, $directory, $roots, $robots,
            $day->lessThan(CarbonImmutable::now('UTC')->startOfDay()));
        return $this->store->replace($date, $rows);
    }

    private function agentRoots(array $merchants): array
    {
        $table = ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . 'agents';
        $agents = [];
        foreach ($this->database->table($table)->whereNull('deleted_at')->get(['id', 'pid']) as $agent) {
            $agents[(string) $agent->id] = (string) $agent->pid;
        }
        $roots = [];
        foreach ($agents as $id => $parent) {
            $cursor = (string) $id;
            $seen = [];
            while (isset($agents[$cursor]) && $agents[$cursor] !== '0') {
                if (isset($seen[$cursor])) { $cursor = ''; break; }
                $seen[$cursor] = true;
                $cursor = $agents[$cursor];
            }
            if ($cursor !== '' && isset($agents[$cursor], $merchants[$cursor]) && $agents[$cursor] === '0') {
                $roots[(string) $id] = $cursor;
            }
        }
        return $roots;
    }

    private function robotUsers(array $ids): array
    {
        $table = ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . 'users';
        $robots = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($this->database->table($table)->whereIn('user_id', $chunk)->where('user_type', 3)->pluck('user_id') as $id) {
                $robots[(string) $id] = true;
            }
        }
        return $robots;
    }
}
