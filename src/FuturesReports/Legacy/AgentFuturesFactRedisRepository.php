<?php

namespace SixMm\Shared\FuturesReports\Legacy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

class AgentFuturesFactRedisRepository
{
    private $redis;

    private string $connection;

    private int $ttlSeconds;

    private string $version;

    private string $keyPrefix;

    public function __construct(
        $redis = null,
        ?int $ttlSeconds = null,
        ?string $version = null,
        ?string $keyPrefix = null
    ) {
        $this->redis = $redis;
        $this->connection = (string) config(
            'statistics.futures_fact_redis.connection',
            'default'
        );
        $this->ttlSeconds = max(
            3600,
            $ttlSeconds ?? (int) config('statistics.futures_fact_redis.ttl_seconds', 259200)
        );
        $this->version = trim(
            $version ?? (string) config('statistics.futures_fact_redis.version', 'v6')
        );
        $this->keyPrefix = trim(
            $keyPrefix ?? (string) config('statistics.futures_fact_redis.key_prefix', 'futures:facts'),
            ':'
        );
    }

    public function putHour(CarbonImmutable $hourStart, array $payload): void
    {
        $finalKey = $this->hourKey($hourStart);
        $temporaryKey = $finalKey . ':tmp:' . bin2hex(random_bytes(8));
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        if (!$this->redis()->setex($temporaryKey, $this->ttlSeconds, $json)) {
            throw new RuntimeException('写入合约事实Redis临时小时桶失败');
        }
        if (!$this->redis()->rename($temporaryKey, $finalKey)) {
            throw new RuntimeException('切换合约事实Redis小时桶失败');
        }
    }

    public function getHour(CarbonImmutable $hourStart): ?array
    {
        return $this->decodeCompleted($this->redis()->get($this->hourKey($hourStart)));
    }

    public function putSnapshot(CarbonImmutable $date, array $payload): void
    {
        $this->putValue($this->snapshotKey($date), $payload);
    }

    public function getSnapshot(CarbonImmutable $date): ?array
    {
        return $this->decodeCompleted($this->redis()->get($this->snapshotKey($date)));
    }

    /** Immutable UTC boundary snapshot, kept separately from the same day's live snapshot. */
    public function putBoundarySnapshot(CarbonImmutable $date, array $payload): void
    {
        $this->putValue($this->boundarySnapshotKey($date), $payload);
    }

    public function getBoundarySnapshot(CarbonImmutable $date): ?array
    {
        return $this->decodeCompleted($this->redis()->get($this->boundarySnapshotKey($date)));
    }

    public function putFinalizationMarker(CarbonImmutable $date): void
    {
        $this->putValue($this->finalizationKey($date), [
            'status' => 'completed',
            'completed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function hasFinalizationMarker(CarbonImmutable $date): bool
    {
        return $this->decodeCompleted(
            $this->redis()->get($this->finalizationKey($date))
        ) !== null;
    }

    public function putSummaryChecksum(
        CarbonImmutable $date,
        string $checksum
    ): void {
        $this->putValue($this->summaryChecksumKey($date), [
            'status' => 'completed',
            'checksum' => $checksum,
            'completed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function getSummaryChecksum(CarbonImmutable $date): ?string
    {
        $payload = $this->decodeCompleted(
            $this->redis()->get($this->summaryChecksumKey($date))
        );
        if ($payload === null) {
            return null;
        }

        $checksum = trim((string) ($payload['checksum'] ?? ''));

        return $checksum === '' ? null : $checksum;
    }

    private function putValue(string $finalKey, array $payload): void
    {
        $temporaryKey = $finalKey . ':tmp:' . bin2hex(random_bytes(8));
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        if (!$this->redis()->setex($temporaryKey, $this->ttlSeconds, $json)) {
            throw new RuntimeException('写入合约事实Redis临时数据失败');
        }
        if (!$this->redis()->rename($temporaryKey, $finalKey)) {
            throw new RuntimeException('切换合约事实Redis数据失败');
        }
    }

    private function redis()
    {
        if ($this->redis === null) {
            $this->redis = Redis::connection($this->connection);
        }

        return $this->redis;
    }

    private function decodeCompleted($raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return null;
        }

        return is_array($payload) && ($payload['status'] ?? null) === 'completed'
            ? $payload
            : null;
    }

    private function hourKey(CarbonImmutable $hourStart): string
    {
        $hour = $hourStart->setTimezone('UTC')->startOfHour()->format('Y-m-d:H');

        return "{$this->keyPrefix}:{$this->version}:hour:{{$hour}}";
    }

    private function snapshotKey(CarbonImmutable $date): string
    {
        $day = $date->setTimezone('UTC')->toDateString();

        return "{$this->keyPrefix}:{$this->version}:snapshot:{{$day}}";
    }

    private function boundarySnapshotKey(CarbonImmutable $date): string
    {
        $day = $date->setTimezone('UTC')->toDateString();
        return "{$this->keyPrefix}:{$this->version}:boundary-snapshot:{{$day}}";
    }

    private function finalizationKey(CarbonImmutable $date): string
    {
        $day = $date->setTimezone('UTC')->toDateString();

        return "{$this->keyPrefix}:{$this->version}:finalized:{{$day}}";
    }

    private function summaryChecksumKey(CarbonImmutable $date): string
    {
        $day = $date->setTimezone('UTC')->toDateString();

        return "{$this->keyPrefix}:{$this->version}:summary:{{$day}}";
    }
}
