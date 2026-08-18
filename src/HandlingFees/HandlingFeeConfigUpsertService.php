<?php

declare(strict_types=1);

namespace SixMm\Shared\HandlingFees;

use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final class HandlingFeeConfigUpsertService
{
    private const PLATFORM_AGENT_ID = 0;
    private const RATE_SCALE = 18;

    public function __construct(private ConnectionInterface $connection)
    {
    }

    /**
     * Creates a complete agent-owned copy of the platform tiers when needed,
     * then updates one tier while preserving the fee-rate boundaries.
     *
     * @return array<string, mixed>
     */
    public function upsertAgentLevel(
        int $agentId,
        int $level,
        string $makerFeeRate,
        string $takerFeeRate
    ): array {
        if ($agentId <= self::PLATFORM_AGENT_ID) {
            throw new InvalidArgumentException('The target agent ID must be greater than zero.');
        }
        if ($level < 0) {
            throw new InvalidArgumentException('The handling-fee level must be non-negative.');
        }

        $makerFeeRate = $this->normalizeRate($makerFeeRate);
        $takerFeeRate = $this->normalizeRate($takerFeeRate);

        return $this->connection->transaction(function () use (
            $agentId,
            $level,
            $makerFeeRate,
            $takerFeeRate
        ): array {
            $platformRows = $this->activeQuery(self::PLATFORM_AGENT_ID)
                ->lockForUpdate()
                ->orderBy('level')
                ->orderBy('id')
                ->get($this->columns())
                ->map(static fn (object $row): array => (array) $row)
                ->all();

            if ($platformRows === []) {
                throw new DomainException('Platform handling-fee configuration is empty.');
            }

            $platformByLevel = $this->rowsByLevel($platformRows);
            if (!isset($platformByLevel[$level])) {
                throw new DomainException('The platform handling-fee level does not exist.');
            }

            $agentRows = $this->activeQuery($agentId)
                ->lockForUpdate()
                ->get($this->columns())
                ->map(static fn (object $row): array => (array) $row)
                ->all();
            $this->cloneMissingPlatformLevels($agentId, $platformRows, $agentRows);

            $agentRows = $this->activeQuery($agentId)
                ->orderBy('level')
                ->orderBy('id')
                ->get($this->columns())
                ->map(static fn (object $row): array => (array) $row)
                ->all();
            $targetIndex = $this->rowIndexForLevel($agentRows, $level);
            if ($targetIndex === null) {
                throw new DomainException('The agent handling-fee level could not be created.');
            }

            $previous = $targetIndex > 0 ? $agentRows[$targetIndex - 1] : null;
            $next = $targetIndex < count($agentRows) - 1 ? $agentRows[$targetIndex + 1] : null;
            $platform = $platformByLevel[$level];
            $this->assertRateBoundaries(
                'maker_fee_rate',
                $makerFeeRate,
                $platform,
                $previous,
                $next
            );
            $this->assertRateBoundaries(
                'taker_fee_rate',
                $takerFeeRate,
                $platform,
                $previous,
                $next
            );

            $target = $agentRows[$targetIndex];
            $this->activeQuery($agentId)
                ->where('id', $target['id'])
                ->update([
                    'maker_fee_rate' => $makerFeeRate,
                    'taker_fee_rate' => $takerFeeRate,
                    'updated_at' => $this->now(),
                ]);

            $updated = $this->activeQuery($agentId)
                ->where('id', $target['id'])
                ->first($this->columns());

            if ($updated === null) {
                throw new DomainException('The agent handling-fee level could not be updated.');
            }

            return $this->normalizeRow((array) $updated);
        });
    }

    /**
     * @param array<int, array<string, mixed>> $platformRows
     * @param array<int, array<string, mixed>> $agentRows
     */
    private function cloneMissingPlatformLevels(int $agentId, array $platformRows, array $agentRows): void
    {
        $existing = $this->rowsByLevel($agentRows);
        $now = $this->now();

        foreach ($platformRows as $platformRow) {
            $level = (int) $platformRow['level'];
            if (isset($existing[$level])) {
                continue;
            }

            $this->connection->table('handling_fee_level_config')->insert([
                'agent_id' => $agentId,
                'level' => $level,
                'level_name' => (string) $platformRow['level_name'],
                'volume_30d' => (string) $platformRow['volume_30d'],
                'maker_fee_rate' => (string) $platformRow['maker_fee_rate'],
                'taker_fee_rate' => (string) $platformRow['taker_fee_rate'],
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $platform
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed>|null $next
     */
    private function assertRateBoundaries(
        string $field,
        string $rate,
        array $platform,
        ?array $previous,
        ?array $next
    ): void {
        $platformRate = (string) $platform[$field];
        if (bccomp($rate, $platformRate, self::RATE_SCALE) < 0) {
            throw new HandlingFeeConfigRateConstraintViolation(
                $field,
                HandlingFeeConfigRateConstraintViolation::PLATFORM_FLOOR,
                $platformRate
            );
        }

        if ($next !== null) {
            $nextRate = (string) $next[$field];
            if (bccomp($rate, $nextRate, self::RATE_SCALE) < 0) {
                throw new HandlingFeeConfigRateConstraintViolation(
                    $field,
                    HandlingFeeConfigRateConstraintViolation::LOWER_TIER_FLOOR,
                    $nextRate
                );
            }
        }

        if ($previous !== null) {
            $previousRate = (string) $previous[$field];
            if (bccomp($rate, $previousRate, self::RATE_SCALE) > 0) {
                throw new HandlingFeeConfigRateConstraintViolation(
                    $field,
                    HandlingFeeConfigRateConstraintViolation::UPPER_TIER_CEILING,
                    $previousRate
                );
            }
        }
    }

    private function normalizeRate(string $rate): string
    {
        $rate = trim($rate);
        if (!preg_match('/^\d+(?:\.\d+)?$/', $rate) || bccomp($rate, '0', self::RATE_SCALE) < 0) {
            throw new InvalidArgumentException('Handling-fee rates must be non-negative decimals.');
        }

        return $rate;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function rowsByLevel(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row['level']] = $row;
        }

        return $indexed;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function rowIndexForLevel(array $rows, int $level): ?int
    {
        foreach ($rows as $index => $row) {
            if ((int) $row['level'] === $level) {
                return $index;
            }
        }

        return null;
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

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
