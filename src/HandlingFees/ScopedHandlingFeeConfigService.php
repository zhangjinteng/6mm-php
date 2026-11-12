<?php
declare(strict_types=1);

namespace SixMm\Shared\HandlingFees;

use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/** Insert-only account defaults. Rates are raw decimal fractions, never percentages. */
final class ScopedHandlingFeeConfigService
{
    public function __construct(private ConnectionInterface $db) {}

    public function lock(): void
    {
        if ($this->db->getDriverName() === 'pgsql') $this->db->select('SELECT pg_advisory_xact_lock(640061112)');
    }

    public function query(int $site, int $agent = 0): Builder
    {
        if ($site < 0 || $agent < 0 || ($site === 0 && $agent !== 0)) throw new InvalidArgumentException('无效的手续费配置归属');
        return $this->db->table('handling_fee_level_config')->where('home_site_id',$site)
            ->where('agent_id',$agent)->whereNull('deleted_at');
    }

    /** @return list<array<string,mixed>> Newly inserted tiers only. */
    public function ensureScope(int $site, int $agent = 0): array
    {
        $this->query($site,$agent);
        if ($site === 0) return [];
        return $this->db->transaction(function () use ($site,$agent): array {
            $this->lock();
            $platform = $this->query(0)->orderBy('level')->lockForUpdate()->get()->keyBy('level');
            if ($platform->isEmpty() || !$platform->has(0)) throw new DomainException('平台手续费配置不完整，请先配置普通会员及 VIP 费率');
            $parents = $agent > 0 ? $this->query($site)->lockForUpdate()->get()->keyBy('level') : collect();
            $existing = $this->query($site,$agent)->lockForUpdate()->pluck('level')->all();
            $created = [];
            foreach ($platform as $level => $base) {
                if (in_array((int)$level,array_map('intval',$existing),true)) continue;
                $source = $parents->get($level) ?? $base;
                $row = ['home_site_id'=>$site,'agent_id'=>$agent,'level'=>(int)$level,
                    'level_name'=>(string)$source->level_name,'volume_30d'=>(string)$source->volume_30d,
                    'maker_fee_rate'=>(string)$source->maker_fee_rate,'taker_fee_rate'=>(string)$source->taker_fee_rate,
                    'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s'),'deleted_at'=>null];
                $row['id'] = $this->db->table('handling_fee_level_config')->insertGetId($row);
                $created[] = $row;
            }
            return $created;
        });
    }

    /** SQL rolls back on transport/rejection/incomplete confirmation; external TK writes cannot. */
    public function initialize(int $site, int $agent, callable $publish): array
    {
        return $this->db->transaction(function () use ($site,$agent,$publish): array {
            $created = $this->ensureScope($site,$agent);
            if ($created !== []) {
                $payload = FeeLevelBatch::payload($created);
                try { FeeLevelBatch::confirm($payload, $publish($payload)); }
                catch (\Throwable $e) {
                    throw new DomainException('手续费同步失败，本地修改将回滚；TK 可能已部分生效，请核对后重试',0,$e);
                }
            }
            return $created;
        });
    }
}
