<?php

declare(strict_types=1);

namespace SixMm\Shared\HandlingFees;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use SixMm\Shared\Pagination\PageResult;

final class HandlingFeeConfigQueryService
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    /** @return PageResult<array<string, mixed>> */
    public function search(HandlingFeeConfigListQuery $criteria): PageResult
    {
        $query = $this->activeQuery($criteria->agentId());
        $total = (int) (clone $query)->count('id');

        if ($total === 0) {
            return new PageResult([], 0, $criteria->page(), $criteria->pageSize());
        }

        $rows = $query
            ->orderBy('volume_30d')
            ->orderBy('id')
            ->offset(($criteria->page() - 1) * $criteria->pageSize())
            ->limit($criteria->pageSize())
            ->get($this->columns())
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        $previousThreshold = $this->previousThreshold($criteria);
        $rows = array_map(function (array $row) use (&$previousThreshold): array {
            $row = $this->normalizeRow($row);
            $row['volume_30d_min'] = $previousThreshold;
            $previousThreshold = $row['volume_30d'];

            return $row;
        }, $rows);

        return new PageResult($rows, $total, $criteria->page(), $criteria->pageSize());
    }

    /** @return array<string, mixed>|null */
    public function detail(int $id, int $agentId = 0): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->activeQuery(max(0, $agentId))->where('id', $id)->first($this->columns());

        return $row === null ? null : $this->normalizeRow((array) $row);
    }

    /** @return array{level_name: string, volume_30d_min: string} */
    public function nextLevel(int $agentId = 0): array
    {
        $row = $this->activeQuery(max(0, $agentId))
            ->orderByDesc('level')
            ->orderByDesc('id')
            ->first(['level', 'volume_30d']);

        if ($row === null) {
            return ['level_name' => 'VIP1', 'volume_30d_min' => '0'];
        }

        return [
            'level_name' => 'VIP' . (max(0, (int) $row->level) + 1),
            'volume_30d_min' => (string) $row->volume_30d,
        ];
    }

    private function activeQuery(int $agentId): Builder
    {
        return $this->connection
            ->table('handling_fee_level_config')
            ->where('agent_id', $agentId)
            ->whereNull('deleted_at');
    }

    /** @return array<int, string> */
    private function columns(): array
    {
        return [
            'id',
            'agent_id',
            'level',
            'level_name',
            'volume_30d',
            'maker_fee_rate',
            'taker_fee_rate',
            'created_at',
            'updated_at',
        ];
    }

    private function previousThreshold(HandlingFeeConfigListQuery $criteria): string
    {
        $offset = ($criteria->page() - 1) * $criteria->pageSize();
        if ($offset === 0) {
            return '0';
        }

        $value = $this->activeQuery($criteria->agentId())
            ->orderBy('volume_30d')
            ->orderBy('id')
            ->offset($offset - 1)
            ->value('volume_30d');

        return $value === null ? '0' : (string) $value;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeRow(array $row): array
    {
        foreach (['id', 'agent_id', 'level'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (int) $row[$field];
            }
        }

        foreach (['volume_30d', 'maker_fee_rate', 'taker_fee_rate'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (string) $row[$field];
            }
        }

        return $row;
    }
}
