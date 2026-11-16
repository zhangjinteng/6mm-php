<?php
declare(strict_types=1);

namespace SixMm\Shared\HandlingFees;

use DomainException;

/** Transport-independent TK batch construction and strict owner/result verification. */
final class FeeLevelBatch
{
    public static function payload(array $rows): array
    {
        $version = (int)(microtime(true)*1000000);
        return ['items'=>array_map(static function (array $row) use ($version): array {
            foreach (['maker_fee_rate','taker_fee_rate'] as $field) {
                if (!preg_match('/^\d+(?:\.\d+)?$/D',(string)$row[$field])) throw new DomainException('手续费必须为非负十进制数');
            }
            return ['level'=>(int)$row['level'],'home_site_id'=>(int)$row['home_site_id'] === 0 ? '' : (string)$row['home_site_id'],
                'agent_id'=>(string)$row['agent_id'],'maker_fee_rate'=>self::normalizeRate((string)$row['maker_fee_rate']),
                'taker_fee_rate'=>self::normalizeRate((string)$row['taker_fee_rate']),'version'=>(string)($row['tk_version'] ?? $version),
                'command_id'=>(string)($row['command_id'] ?? random_int(1_000_000_000_000_000_000,8_999_999_999_999_999_999))];
        },$rows)];
    }

    public static function confirm(array $payload, array $response): void
    {
        foreach (self::results($payload, $response) as $result) {
            if ((int) ($result['reject_code'] ?? 0) !== 0) {
                throw \SixMm\Shared\RpcErrors\TkRpcException::business((int) $result['reject_code'], 'BatchUpdateFeeLevelConfig', [], (string) ($result['message'] ?? ''));
            }
        }
    }

    /** Validate ownership, uniqueness and counters before interpreting any item as a terminal result. */
    public static function results(array $payload, array $response): array
    {
        $response = self::normalize($response);
        $expected = [];
        foreach ($payload['items'] as $item) {
            $key = self::key($item);
            if (isset($expected[$key])) throw new DomainException('手续费请求等级重复');
            $expected[$key] = true;
        }
        $seen = []; $results = []; $success = 0;
        foreach ($response['results'] ?? [] as $result) {
            if (!is_array($result)) throw new DomainException('手续费同步结果格式无效');
            $key = self::key($result);
            if (!isset($expected[$key]) || isset($seen[$key])) {
                throw new DomainException('手续费同步结果归属不符、重复或被拒绝');
            }
            $seen[$key] = true;
            $results[] = $result;
            if ((int) ($result['reject_code'] ?? 0) === 0) ++$success;
        }
        $count = count($payload['items']);
        if (count($seen) !== $count || (int) ($response['total_count'] ?? -1) !== $count
            || (int) ($response['success_count'] ?? 0) !== $success
            || (int) ($response['failed_count'] ?? 0) !== $count - $success) {
            throw new DomainException('手续费同步结果不完整');
        }
        return $results;
    }

    public static function normalizeRate(string $rate): string
    {
        if (!preg_match('/^\d+(?:\.\d+)?$/D', $rate)) throw new DomainException('手续费必须为非负十进制数');
        [$integer, $fraction] = array_pad(explode('.', $rate, 2), 2, '');
        $fraction = rtrim($fraction, '0');
        if (strlen($fraction) > 8) throw new DomainException('手续费率去除末尾零后仍超过 8 位小数，请调整费率；系统不会截断或四舍五入');
        return (ltrim($integer, '0') ?: '0') . ($fraction === '' ? '' : '.' . $fraction);
    }

    private static function key(array $row): string
    {
        // Protobuf JSON omits default scalar values, including level=0 and agent_id=0.
        return ((string) ($row['home_site_id'] ?? '') ?: '0').':'.((string) ($row['agent_id'] ?? '') ?: '0').':'.(string) ($row['level'] ?? 0);
    }

    private static function normalize(array $input): array
    {
        $result=[];
        foreach ($input as $key=>$value) {
            $key=is_string($key) ? strtolower(preg_replace('/(?<!^)[A-Z]/','_$0',$key)) : $key;
            $result[$key]=is_array($value) ? self::normalize($value) : $value;
        }
        return $result;
    }
}
