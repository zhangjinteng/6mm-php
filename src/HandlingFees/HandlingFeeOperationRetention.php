<?php
declare(strict_types=1);

namespace SixMm\Shared\HandlingFees;

final class HandlingFeeOperationRetention
{
    public static function apply(array $operation, int $now): array
    {
        $finished = $operation['status'] === 'rejected'
            || ($operation['status'] === 'applied' && !empty($operation['vip_recalculated_at']));
        if ($finished) {
            // Reading/retrying a receipt must never extend its seven-day lifetime.
            $operation['completed_at'] ??= gmdate('Y-m-d H:i:s', $now);
            $operation['expires_at'] ??= $now + HandlingFeeOperationStore::RETENTION_SECONDS;
        } else {
            unset($operation['completed_at'], $operation['expires_at']);
        }
        $operation['updated_at'] = gmdate('Y-m-d H:i:s', $now);
        return $operation;
    }
}
