<?php

declare(strict_types=1);

namespace SixMm\Shared\Prediction;

enum PredictionGameType: string
{
    case UP_DOWN = 'UP_DOWN';
    case HIGH_LOW = 'HIGH_LOW';
}
