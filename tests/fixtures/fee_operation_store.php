<?php
use SixMm\Shared\HandlingFees\{HandlingFeeOperationStore, HandlingFeeOperationRetention};

/** Isolated test journal: host tests must never touch a real Redis or TK. */
class FeeOperationMemoryStore implements HandlingFeeOperationStore
{
    public array $records = [];
    public int $clock;
    public bool $failPrepared = false;
    public bool $failAppliedOnce = false;
    public function __construct() { $this->clock = time(); }
    public function now(): int { return $this->clock; }
    public function withScopeLock(int $site, int $agent, callable $callback): mixed { return $callback(); }
    public function get(int $site, int $agent, string $id): ?array
    {
        $row = $this->records[$id] ?? null;
        return $row && (int)$row['home_site_id'] === $site && (int)$row['agent_id'] === $agent
            && (!isset($row['expires_at']) || $row['expires_at'] > $this->clock) ? $row : null;
    }
    public function byRequest(int $site, int $agent, string $requestId): ?array
    {
        foreach ($this->records as $id => $row) if ($row['request_id'] === $requestId && ($row = $this->get($site,$agent,$id))) return $row;
        return null;
    }
    public function pending(int $site, int $agent): ?array
    {
        foreach ($this->records as $id => $row) {
            $row = $this->get($site,$agent,$id);
            if ($row && in_array($row['status'],['prepared','unknown','confirmed'],true)) return $row;
        }
        return null;
    }
    public function save(array $operation): array
    {
        if ($this->failPrepared && $operation['status'] === 'prepared') throw new RuntimeException('Redis unavailable before TK write');
        if ($this->failAppliedOnce && $operation['status'] === 'applied') {
            $this->failAppliedOnce = false; throw new RuntimeException('Redis reply lost after SQL commit');
        }
        return $this->records[$operation['id']] = HandlingFeeOperationRetention::apply($operation,$this->clock);
    }
    public function vipPending(): array
    {
        return array_values(array_filter($this->records,static fn ($row) => $row['status'] === 'applied' && empty($row['vip_recalculated_at'])));
    }
}

function feeRequestId(string $label, ?int $now = null): string
{
    return 'fee-' . (($now ?? time()) * 1000) . '-' . substr(hash('sha256',$label),0,32);
}
