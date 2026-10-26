<?php
declare(strict_types=1);
namespace SixMm\Shared\Prediction;
final class ReadException extends \RuntimeException {
    public function __construct(public readonly string $errorCode,public readonly int $httpStatus=503) {
        parent::__construct($errorCode);
    }
}
