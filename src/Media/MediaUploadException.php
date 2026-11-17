<?php

declare(strict_types=1);

namespace SixMm\Shared\Media;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/** Safe public diagnostics; the original exception remains available to server logging. */
final class MediaUploadException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 502,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function response(): JsonResponse
    {
        return new JsonResponse(['code'=>0, 'show'=>1, 'msg'=>$this->getMessage(),
            'data'=>['error_code'=>$this->errorCode]], $this->httpStatus, ['Cache-Control'=>'private, no-store']);
    }
}
