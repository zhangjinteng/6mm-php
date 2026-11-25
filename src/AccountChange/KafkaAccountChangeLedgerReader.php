<?php

namespace SixMm\Shared\AccountChange;

use Illuminate\Database\Eloquent\Model;
use Carbon\CarbonImmutable;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\TopicPartition;
use RuntimeException;
use Throwable;

class KafkaAccountChangeLedgerReader
{
    private const TOPIC = 'ledger.delta.user';

    public function findLedgerId(Model $order): ?string
    {
        $timeoutMs = max(1000, min(20000, (int) config('account_change_ledger.scan_timeout_ms', 10000)));
        $deadline = hrtime(true) + $timeoutMs * 1000000;
        $consumer = $this->createConsumer($this->consumerConfig($timeoutMs));

        try {
            $partitions = $this->partitionIds($consumer, $this->remainingMs($deadline));
            $created = CarbonImmutable::parse($order->getRawOriginal('created_at') ?? $order->getAttributes()['created_at'], 'UTC');
            $startMs = $created->getTimestamp() * 1000 + (int) $created->format('v')
                - max(0, (int) config('account_change_ledger.lookback_seconds', 30)) * 1000;
            $watermarks = [];
            $timestamps = [];
            foreach ($partitions as $partition) {
                $low = $high = 0;
                // Snapshot before the timestamp lookup, so a newly published event cannot fall into a gap.
                $consumer->queryWatermarkOffsets(self::TOPIC, $partition, $low, $high, $this->remainingMs($deadline));
                $watermarks[$partition] = [$low, $high];
                $timestamps[] = new TopicPartition(self::TOPIC, $partition, max(0, $startMs));
            }

            $offsets = $consumer->offsetsForTimes($timestamps, $this->remainingMs($deadline));
            $assignment = [];
            foreach ($offsets as $offset) {
                $partition = $offset->getPartition();
                if ($offset->getErr() !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                    throw new RuntimeException('Kafka 查询流水时间位点失败', $offset->getErr());
                }
                if ($offset->getTopic() !== self::TOPIC || !isset($watermarks[$partition])) {
                    throw new RuntimeException('Kafka 返回了无效的流水分区');
                }
                [$low, $high] = $watermarks[$partition];
                $position = $offset->getOffset();
                if ($position === RD_KAFKA_OFFSET_END) {
                    $position = $high;
                } elseif ($position < 0) {
                    throw new RuntimeException('Kafka 无法定位流水起始位点');
                }
                $assignment[$partition] = new TopicPartition(self::TOPIC, $partition, max($low, $position));
            }
            if (count($assignment) !== count($partitions)) {
                throw new RuntimeException('Kafka 返回的流水分区位点不完整');
            }

            // assign() does not join or rebalance any standing consumer group. Never subscribe or commit here.
            $consumer->assign(array_values($assignment));
            $maxMessages = max(1, (int) config('account_change_ledger.max_messages', 100000));
            $seen = 0;
            while ($seen < $maxMessages && hrtime(true) < $deadline) {
                $message = $consumer->consume(min(250, $this->remainingMs($deadline)));
                if (in_array($message->err, [RD_KAFKA_RESP_ERR__TIMED_OUT, RD_KAFKA_RESP_ERR__PARTITION_EOF], true)) {
                    continue;
                }
                if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                    throw new RuntimeException('Kafka 读取账变流水失败：' . $message->errstr(), $message->err);
                }
                $seen++;
                if (!is_string($message->payload)) {
                    continue;
                }
                try {
                    $body = json_decode($message->payload, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                } catch (JsonException $exception) {
                    continue;
                }
                $ledgerId = $this->matchLedgerId($order, $body);
                if ($ledgerId !== null) {
                    return $ledgerId;
                }
            }

            return null;
        } finally {
            try {
                $consumer->assign([]);
            } catch (Throwable $exception) {
                Log::channel('daily')->warning('释放临时 Kafka 分区失败', ['error' => $exception->getMessage()]);
            }
            try {
                $consumer->close();
            } catch (Throwable $exception) {
                Log::channel('daily')->warning('关闭临时 Kafka 读取实例失败', ['error' => $exception->getMessage()]);
            }
        }
    }

    protected function createConsumer(Conf $config): KafkaConsumer
    {
        return new KafkaConsumer($config);
    }

    protected function partitionIds(KafkaConsumer $consumer, int $timeoutMs): array
    {
        $metadata = $consumer->getMetadata(true, null, $timeoutMs);
        foreach ($metadata->getTopics() as $topic) {
            if ($topic->getTopic() !== self::TOPIC) {
                continue;
            }
            if ($topic->getErr() !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                throw new RuntimeException('Kafka 流水主题元数据异常', $topic->getErr());
            }
            $partitions = [];
            foreach ($topic->getPartitions() as $partition) {
                if ($partition->getErr() !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                    throw new RuntimeException('Kafka 流水分区不可用', $partition->getErr());
                }
                $partitions[] = $partition->getId();
            }
            if ($partitions !== []) {
                return $partitions;
            }
        }
        throw new RuntimeException('Kafka 流水主题不存在或没有可读取分区');
    }

    private function consumerConfig(int $timeoutMs): Conf
    {
        $config = new Conf();
        $protocol = (string) config('kafka.security_protocol', 'SSL');
        $options = config('account_change_ledger.kafka_options', []);
        if (!str_contains($protocol, 'SSL')) {
            $options = array_filter($options, static fn ($key): bool => !str_starts_with($key, 'ssl.'), ARRAY_FILTER_USE_KEY);
        }
        $options = array_merge($options, [
            'bootstrap.servers' => config('kafka.brokers'),
            'security.protocol' => $protocol,
            'group.id' => 'account-change-ledger-lookup-' . Str::uuid(),
            'enable.auto.commit' => 'false',
            'enable.auto.offset.store' => 'false',
            'allow.auto.create.topics' => 'false',
            'enable.partition.eof' => 'true',
            'enable.ssl.certificate.verification' => 'true',
            'auto.offset.reset' => 'error',
            'socket.timeout.ms' => $timeoutMs,
            'socket.connection.setup.timeout.ms' => $timeoutMs,
            'fetch.wait.max.ms' => 100,
            'log_level' => 0,
        ]);
        foreach ($options as $key => $value) {
            $config->set($key, (string) $value);
        }
        return $config;
    }

    private function remainingMs(int $deadline): int
    {
        $remaining = (int) (($deadline - hrtime(true)) / 1000000);
        if ($remaining <= 0) {
            throw new RuntimeException('Kafka 流水扫描超时，等待队列重试');
        }
        return $remaining;
    }

    private function matchLedgerId(Model $order, $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }
        $data = $body['data'] ?? $body;
        if (!is_array($data) || !is_array($data['e'] ?? null)
            || ($data['e']['tx'] ?? null) !== $order->order_no || !is_array($data['al'] ?? null)) {
            return null;
        }

        $ids = [];
        foreach ($data['al'] as $ledger) {
            if (!is_array($ledger)
                || !is_string($ledger['b'] ?? null) || strtolower($ledger['b']) !== 'admin_adjust'
                || !is_string($ledger['id'] ?? null) || trim($ledger['id']) === ''
                || (!is_string($ledger['u'] ?? null) && !is_int($ledger['u'] ?? null))
                || (string) $ledger['u'] !== (string) $order->user_id
                || !is_string($ledger['a'] ?? null) || strtoupper($ledger['a']) !== strtoupper($order->asset_type)
                || (!is_string($ledger['wd'] ?? null) && !is_int($ledger['wd'] ?? null))) {
                continue;
            }
            try {
                if (BigDecimal::of($ledger['wd'])->isEqualTo($order->amount)) {
                    $ids[$ledger['id']] = $ledger['id'];
                }
            } catch (NumberFormatException $exception) {
                continue;
            }
        }
        if (count($ids) > 1) {
            throw new RuntimeException('同一账变订单匹配多个 ledger_id，拒绝自动关联');
        }
        return $ids === [] ? null : reset($ids);
    }
}
