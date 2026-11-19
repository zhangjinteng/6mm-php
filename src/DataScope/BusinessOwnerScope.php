<?php
declare(strict_types=1);

namespace SixMm\Shared\DataScope;

use Illuminate\Database\Query\Builder;
use SixMm\Shared\Contracts\UserDataScope;
use SixMm\Shared\TradeFills\TradeFillOwnerScope;

/** SQL adapter for the same trusted owners used by the ClickHouse queries. */
final class BusinessOwnerScope implements UserDataScope
{
    public function __construct(
        public readonly TradeFillOwnerScope $owner,
        private string $siteColumn,
        private bool $textSite = false,
        private bool $textAgent = false
    ) {}

    public function apply(Builder $query, string $agentColumn): void
    {
        foreach ([$this->siteColumn, $agentColumn] as $column) {
            if (!preg_match('/^[a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)?$/iD', $column)) {
                throw new \InvalidArgumentException('Invalid owner column');
            }
        }
        if ($this->owner->isEmpty()) { $query->whereRaw('1 = 0'); return; }
        $pairs = $this->owner->owners();
        if ($pairs !== []) {
            $query->where(function (Builder $union) use ($pairs, $agentColumn): void {
                foreach ($pairs as [$site, $agent]) {
                    $union->orWhere(function (Builder $pair) use ($site, $agent, $agentColumn): void {
                        $pair->where($this->siteColumn, $this->textSite ? (string) $site : $site)
                            ->where($agentColumn, $this->textAgent ? (string) $agent : $agent);
                    });
                }
            });
            return;
        }
        $predicate = $this->owner->predicate('owner');
        foreach ([['owner.home_site_id', $this->siteColumn, $this->textSite], ['owner.agent_id', $agentColumn, $this->textAgent]] as [$placeholder, $column, $text]) {
            $wrapped = $query->getGrammar()->wrap($column);
            if ($text) {
                $positive = $query->getConnection()->getDriverName() === 'pgsql'
                    ? "$wrapped ~ '^[1-9][0-9]*$'"
                    : "($wrapped GLOB '[1-9]*' AND $wrapped NOT GLOB '*[^0-9]*')";
                $predicate = str_replace([$placeholder . ' > 0', $placeholder . ' = 0'], [$positive, "$wrapped = '0'"], $predicate);
            } else $predicate = str_replace($placeholder, $wrapped, $predicate);
        }
        $query->whereRaw($predicate);
    }
}
