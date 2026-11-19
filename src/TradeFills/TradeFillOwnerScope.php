<?php

declare(strict_types=1);

namespace SixMm\Shared\TradeFills;

use InvalidArgumentException;

/** Trusted event-time owners, never a list of users from the current user directory. */
final class TradeFillOwnerScope
{
    private array $owners;
    private ?string $sourceType = null;

    /** @param list<array{0:int,1:int}> $owners Empty means deny all. */
    public function __construct(array $owners)
    {
        $unique = [];
        foreach ($owners as $owner) {
            if (count($owner) !== 2 || !is_int($owner[0]) || !is_int($owner[1])
                || $owner[0] < 0 || $owner[1] < 0 || ($owner[0] === 0 && $owner[1] !== 0)) {
                throw new InvalidArgumentException('Invalid trade-fill owner.');
            }
            $unique[$owner[0] . ':' . $owner[1]] = $owner;
        }
        ksort($unique);
        $this->owners = array_values($unique);
    }

    public function owners(): array { return $this->owners; }
    /** Only the trusted total-admin adapter may select a whole source category. */
    public static function category(string $type): self
    {
        if (!in_array($type, ['all', 'merchant', 'white_label'], true)) throw new InvalidArgumentException('Invalid trade-fill source category.');
        $scope = new self([]); $scope->sourceType = $type; return $scope;
    }
    public static function all(): self { return self::category('all'); }
    public function isEmpty(): bool { return $this->sourceType === null && $this->owners === []; }
    public function cacheKey(): string { return hash('sha256', json_encode([$this->sourceType, $this->owners])); }

    /** RPC snapshots must carry a canonical owner; absent identity is never platform data. */
    public function matches($site, $agent): bool
    {
        foreach ([$site, $agent] as $id) {
            if (!is_int($id) && !is_string($id)) return false;
            if (!preg_match('/^(?:0|[1-9][0-9]{0,18})$/D', (string) $id)
                || bccomp((string) $id, (string) PHP_INT_MAX) > 0) return false;
        }
        $site = (int) $site; $agent = (int) $agent;
        if ($site === 0 && $agent > 0) return false;
        return match ($this->sourceType) {
            'all' => true,
            'merchant' => $site > 0 && $agent > 0,
            'white_label' => $site > 0 && $agent === 0,
            default => in_array([$site, $agent], $this->owners, true),
        };
    }

    public function predicate(string $alias = 'o'): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias)) throw new InvalidArgumentException('Invalid table alias.');
        $merchant = "({$alias}.home_site_id > 0 AND {$alias}.agent_id > 0)";
        $whiteLabel = "({$alias}.home_site_id > 0 AND {$alias}.agent_id = 0)";
        if ($this->sourceType === 'merchant') return $merchant;
        if ($this->sourceType === 'white_label') return $whiteLabel;
        if ($this->sourceType === 'all') return "({$merchant} OR {$whiteLabel} OR ({$alias}.home_site_id = 0 AND {$alias}.agent_id = 0))";
        if ($this->isEmpty()) return '0';
        return '(' . implode(' OR ', array_map(static fn (array $owner): string =>
            "({$alias}.home_site_id = {$owner[0]} AND {$alias}.agent_id = {$owner[1]})", $this->owners)) . ')';
    }
}
