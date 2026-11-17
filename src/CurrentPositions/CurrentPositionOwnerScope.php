<?php

declare(strict_types=1);

namespace SixMm\Shared\CurrentPositions;

use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use SixMm\Shared\Contracts\UserDataScope;

/** An exact current-position owner, supplied by trusted host identity code only. */
final class CurrentPositionOwnerScope implements UserDataScope
{
    private function __construct(private readonly int $homeSiteId, private readonly int $agentId)
    {
    }

    public static function platform(): self
    {
        return new self(0, 0);
    }

    public static function whiteLabel(int $homeSiteId): self
    {
        if ($homeSiteId <= 0) throw new InvalidArgumentException('A white-label site is required.');
        return new self($homeSiteId, 0);
    }

    public static function merchant(int $homeSiteId, int $agentId): self
    {
        if ($homeSiteId <= 0 || $agentId <= 0) throw new InvalidArgumentException('A merchant site and main merchant ID are required.');
        return new self($homeSiteId, $agentId);
    }

    public function homeSiteId(): int { return $this->homeSiteId; }
    public function agentId(): int { return $this->agentId; }

    /** @return list<string> CK ownership is strict equality, including the platform zero/zero pair. */
    public function clickHousePredicates(string $alias = 'o'): array
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias)) {
            throw new InvalidArgumentException('Invalid current-position table alias.');
        }
        return ["{$alias}.home_site_id = {$this->homeSiteId}", "{$alias}.agent_id = {$this->agentId}"];
    }

    /** Used for scoped keyword lookups and page-only user enrichment, never to preload all users. */
    public function apply(Builder $query, string $agentColumn): void
    {
        if (!preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\.agent_id$/D', $agentColumn, $matches)) {
            throw new InvalidArgumentException('Invalid current-position user directory alias.');
        }
        $this->applyDirectoryId($query, $matches[1] . '.home_site_id', $this->homeSiteId);
        $this->applyDirectoryId($query, $agentColumn, $this->agentId);
    }

    private function applyDirectoryId(Builder $query, string $column, int $id): void
    {
        if ($id > 0) {
            $query->where($column, $id);
            return;
        }
        // Legacy directory NULL means direct/platform owner. CK remains strict '= 0'.
        $query->where(static function (Builder $nested) use ($column): void {
            $nested->where($column, 0)->orWhereNull($column);
        });
    }
}
