<?php

namespace SixMm\Shared\ContractVip;

final class ContractVipProgress
{
    /**
     * @param array<int, string> $thresholds VIP 等级对应的 30 日成交量门槛
     * @return array{current_threshold: string, next_threshold: ?string, progress_percent: ?float}
     */
    public static function calculate(int $level, string $volume, array $thresholds): array
    {
        ksort($thresholds);
        $current = '0';
        $next = null;
        foreach ($thresholds as $candidateLevel => $threshold) {
            if ($candidateLevel <= $level) {
                $current = $threshold;
            }
            if ($candidateLevel > $level) {
                $next = $threshold;
                break;
            }
        }

        if ($next === null) {
            return [
                'current_threshold' => $current,
                'next_threshold' => null,
                'progress_percent' => $thresholds === [] ? null : 100.0,
            ];
        }

        $range = (float) $next - (float) $current;
        $progress = $range > 0
            ? max(0.0, min(100.0, (((float) $volume - (float) $current) / $range) * 100))
            : null;

        return [
            'current_threshold' => $current,
            'next_threshold' => $next,
            'progress_percent' => $progress === null ? null : round($progress, 2),
        ];
    }
}
