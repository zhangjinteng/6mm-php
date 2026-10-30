<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/** One fail-closed policy for publication, legacy facts and position snapshots. */
final class ReportUserScope
{
    public function __construct(private array $directory)
    {
    }

    public static function fromRows(ConnectionInterface $database, array $rows): self
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (string) ($row['user_id'] ?? $row['platform_user_id'] ?? '');
        }
        $directory = new SourceDirectory($database);
        return new self(['agents' => $directory->merchants(), 'users' => $directory->users($ids)]);
    }

    public function user(array $row): ?array
    {
        $id = (string) ($row['user_id'] ?? $row['platform_user_id'] ?? '');
        $user = $this->directory['users'][$id] ?? null;
        return is_array($user) && self::positiveId((string) ($user['site_id'] ?? '')) ? $user : null;
    }

    public function merchant(array $row): ?array
    {
        $user = $this->user($row);
        $id = (string) ($user['agent_id'] ?? '0');
        $merchant = $this->directory['agents'][$id] ?? null;
        if ($user === null || !self::positiveId($id)) {
            return null;
        }
        return $merchant ?? ['source_type' => 'merchant', 'source_id' => $id,
            'source_code' => $id, 'source_name' => $id, 'site_id' => '0'];
    }

    public function filterMerchants(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => $this->merchant($row) !== null));
    }

    public function fingerprint(): string
    {
        $directory = $this->directory;
        foreach ($directory as &$entries) {
            ksort($entries);
        }
        unset($entries);
        return hash('sha256', json_encode($directory, JSON_THROW_ON_ERROR));
    }

    /** Aggregate eligible user execution identities, not unfilterable pre-aggregated merchant totals. */
    public static function executionRows(array $rows): array
    {
        $groups = [];
        self::mergeExecutions($groups, $rows);
        return self::finishExecutions($groups);
    }

    /** Merge one filtered hour without retaining the original fact rows. */
    public static function mergeExecutions(array &$groups, array $rows): void
    {
        foreach ($rows as $row) {
            $details = $row['execution_details'] ?? [];
            if ((int) ($row['fill_count'] ?? 0) > 0 && $details === []) {
                throw new InvalidArgumentException('合约小时桶缺少成交去重明细，不能按用户站点过滤成交汇总');
            }
            foreach ($details as $detail) {
                if (!is_array($detail) || count($detail) !== 2
                    || !preg_match('/^-?\d+$/D', (string) $detail[0])
                    || !preg_match('/^\d+(\.\d+)?$/D', (string) $detail[1])) {
                    throw new InvalidArgumentException('成交去重明细无效');
                }
                foreach (array_unique([0, (int) $row['user_type']]) as $type) {
                    $key = implode(':', [$row['agent_id'], $type, $row['symbol'], $row['settle_asset'] ?? 'USDT']);
                    $groups[$key] ??= ['agent_id' => (int) $row['agent_id'], 'user_type' => $type,
                        'symbol' => $row['symbol'], 'settle_asset' => $row['settle_asset'] ?? 'USDT', 'executions' => []];
                    $id = (string) $detail[0];
                    $amount = (string) $detail[1];
                    if (!isset($groups[$key]['executions'][$id]) || bccomp($amount, $groups[$key]['executions'][$id], 16) > 0) {
                        $groups[$key]['executions'][$id] = $amount;
                    }
                }
            }
        }
    }

    public static function finishExecutions(array $groups): array
    {
        $result = [];
        foreach ($groups as $group) {
            $group['trade_ids'] = array_map('strval', array_keys($group['executions']));
            $group['fill_count'] = count($group['trade_ids']);
            $group['trade_amount'] = '0';
            foreach ($group['executions'] as $amount) {
                $group['trade_amount'] = bcadd($group['trade_amount'], $amount, 16);
            }
            unset($group['executions']);
            $result[] = $group;
        }
        return $result;
    }

    public static function positiveId(string $value): bool
    {
        return preg_match('/^[1-9]\d*$/D', $value) === 1;
    }
}
