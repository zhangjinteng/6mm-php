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
        $roots = []; // Merchant attribution is users.agent_id, never an ancestor.
        $robots = []; // All user types belong to the report cohort.
        $rows = (new PredictionUserPnlAggregator())->build($date, $facts, $directory, $roots, $robots,
            $day->lessThan(CarbonImmutable::now('UTC')->startOfDay()));
        return $this->store->replace($date, $rows);
    }

}
