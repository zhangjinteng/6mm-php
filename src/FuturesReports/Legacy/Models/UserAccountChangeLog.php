<?php

namespace SixMm\Shared\FuturesReports\Legacy\Models;

final class UserAccountChangeLog
{
    public const TYPE_HANDLING_FEE = 'HANDLING_FEE';
    public const TYPE_FUNDING_FEE = 'FUNDING_FEE_SETTLE';
    public const TYPE_LIQUIDATION = 'LIQUIDATION_FEE';
    public const TYPE_REALIZED_PNL = 'REALIZED_PNL';
}
