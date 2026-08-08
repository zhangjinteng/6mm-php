<?php

declare(strict_types=1);

namespace SixMm\Shared\DataScope;

use Illuminate\Database\Query\Builder;
use SixMm\Shared\Contracts\UserDataScope;

final class AgentIdsScope implements UserDataScope
{
    /** @var int[] */
    private array $agentIds;

    /**
     * @param array<int, int|string> $agentIds
     */
    public function __construct(array $agentIds)
    {
        $normalized = array_map('intval', $agentIds);
        $normalized = array_filter($normalized, static fn (int $id): bool => $id > 0);
        $this->agentIds = array_values(array_unique($normalized));
    }

    public function apply(Builder $query, string $agentColumn): void
    {
        if ($this->agentIds === []) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereIn($agentColumn, $this->agentIds);
    }

    /** @return int[] */
    public function ids(): array
    {
        return $this->agentIds;
    }
}
