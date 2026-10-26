<?php
declare(strict_types=1);
namespace SixMm\Shared\Prediction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
class GridResponder {
    public static function success(array $data): JsonResponse {
        return new JsonResponse(['code'=>1,'show'=>0,'msg'=>'','data'=>$data]);
    }
    public static function failure(string $code,int $status): JsonResponse {
        return ReadResponder::failure(new ReadException($code,$status));
    }
    public static function reject(string $code,int $status): void {
        throw new HttpResponseException(self::failure($code,$status));
    }
}
