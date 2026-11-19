<?php
declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use SixMm\Shared\DataScope\BusinessOwnerScope;
use SixMm\Shared\TradeFills\TradeFillOwnerScope;

final class RollingTradeVolumeQuery
{
    public function __construct(private ConnectionInterface $db) {}

    /** One grouped read for every requested rolling window. */
    public function search(array $days, TradeFillOwnerScope $owner, ?int $userType = null): array
    {
        if ($days === [] || $owner->isEmpty()) return [];
        $days = array_values(array_unique(array_map(static fn ($day): string => CarbonImmutable::parse($day)->toDateString(), $days)));
        sort($days);
        $query = $this->db->table('user_daily_volume as volume')
            ->whereBetween('volume.trade_date', [CarbonImmutable::parse($days[0])->subDays(29)->toDateString(), $days[count($days) - 1]]);
        (new BusinessOwnerScope($owner, 'volume.home_site_id'))->apply($query, 'volume.agent_id');
        if ($userType !== null) $query->whereExists(static function (Builder $users) use ($userType): void {
            $users->selectRaw('1')->from('users')->whereColumn('users.user_id', 'volume.user_id')
                ->where('users.user_type', $userType)->whereNull('users.deleted_at');
        });
        $daily = $query->select('volume.trade_date')->selectRaw('SUM(volume.volume) AS total')
            ->groupBy('volume.trade_date')->get();
        $result = [];
        foreach ($days as $day) {
            $start30 = CarbonImmutable::parse($day)->subDays(29)->toDateString();
            $start29 = CarbonImmutable::parse($day)->subDays(28)->toDateString();
            $sum30 = BigDecimal::zero(); $sum29 = BigDecimal::zero();
            foreach ($daily as $row) {
                if ($row->trade_date > $day || $row->trade_date < $start30) continue;
                $sum30 = $sum30->plus((string) $row->total);
                if ($row->trade_date >= $start29) $sum29 = $sum29->plus((string) $row->total);
            }
            $result[$day] = ['trade_volume_30d' => (string) $sum30, 'trade_volume_29d' => (string) $sum29];
        }
        return $result;
    }
}
