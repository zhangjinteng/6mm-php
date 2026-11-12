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
                'agent_id'=>(string)$row['agent_id'],'maker_fee_rate'=>(string)$row['maker_fee_rate'],
                'taker_fee_rate'=>(string)$row['taker_fee_rate'],'version'=>(string)$version];
        },$rows)];
    }

    public static function confirm(array $payload, array $response): void
    {
        $response = self::normalize($response);
        $expected = [];
        foreach ($payload['items'] as $item) $expected[self::key($item)] = true;
        $seen = [];
        foreach ($response['results'] ?? [] as $result) {
            $key = self::key($result);
            if (!isset($expected[$key]) || isset($seen[$key]) || (int)($result['reject_code'] ?? 0) !== 0) {
                throw new DomainException('手续费同步结果归属不符、重复或被拒绝');
            }
            $seen[$key] = true;
        }
        $count = count($payload['items']);
        if (count($seen) !== $count || (int)($response['total_count'] ?? -1) !== $count
            || (int)($response['success_count'] ?? -1) !== $count || (int)($response['failed_count'] ?? 0) !== 0) {
            throw new DomainException('手续费同步结果不完整');
        }
    }

    private static function key(array $row): string
    {
        // Protobuf JSON omits default scalar values, including level=0 and agent_id=0.
        return (string)($row['home_site_id'] ?? '').':'.(string)($row['agent_id'] ?? 0).':'.(string)($row['level'] ?? 0);
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
