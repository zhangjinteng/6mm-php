<?php
declare(strict_types=1);
namespace SixMm\Shared\Prediction\Configuration;

final class ConfigurationFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly array $detail = [],
        public readonly ?int $grpcStatus = null,
        string $message = 'Prediction configuration request failed'
    ) {
        parent::__construct($message);
    }
}
