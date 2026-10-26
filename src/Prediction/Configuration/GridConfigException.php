<?php

declare(strict_types=1);

namespace SixMm\Shared\Prediction\Configuration;

final class GridConfigException extends \RuntimeException
{
    private string $errorCode;

    public function __construct(string $errorCode, string $message = 'Grid configuration operation failed', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public static function invalidInput(): self
    {
        return new self('INVALID_INPUT', 'Invalid Grid configuration input');
    }
}
