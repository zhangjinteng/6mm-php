<?php

declare(strict_types=1);

namespace SixMm\Shared\IpLocations;

use RuntimeException;

final class IpInfoRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?int $curlErrorNumber = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
