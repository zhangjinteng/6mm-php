<?php

declare(strict_types=1);

namespace SixMm\Shared\Prediction\Configuration;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

final class GridConfigResponder
{
    private const STATUS = [
        'INVALID_LAYOUT' => 422, 'LAYOUT_PRICE_UNAVAILABLE' => 503, 'LAYOUT_PRICE_READ_FAILED' => 503,
        'INVALID_INPUT' => 422, 'FIELD_FORBIDDEN' => 403, 'FORBIDDEN' => 403,
        'AUTHORITY_UNAVAILABLE' => 503, 'NOT_FOUND' => 404,
        'VERSION_CONFLICT' => 409, 'IDEMPOTENCY_CONFLICT' => 409, 'VERSION_EXHAUSTED' => 409,
        'COMMIT_UNKNOWN' => 503, 'RECEIPT_UNCONFIRMED' => 503, 'DATABASE_ERROR' => 503,
        'DEPENDENCY_UNAVAILABLE' => 503, 'DATA_INTEGRITY' => 503,
    ];
    private const MESSAGES = [
        'INVALID_LAYOUT' => '网格区间无效：请检查价格间距、整秒时长及预测范围',
        'LAYOUT_PRICE_UNAVAILABLE' => '最新标记价暂不可用，暂不能修改公共网格区间，请稍后重试',
        'LAYOUT_PRICE_READ_FAILED' => '行情读取暂时失败，请稍后重试',
        'INVALID_INPUT' => '请求参数无效', 'FIELD_FORBIDDEN' => '配置字段不可编辑', 'FORBIDDEN' => '无权访问',
        'AUTHORITY_UNAVAILABLE' => '配置授权暂不可用', 'NOT_FOUND' => '配置不存在',
        'VERSION_CONFLICT' => '配置版本冲突', 'IDEMPOTENCY_CONFLICT' => '请求与原回执冲突',
        'VERSION_EXHAUSTED' => '配置版本已用尽', 'COMMIT_UNKNOWN' => '提交结果待确认',
        'RECEIPT_UNCONFIRMED' => '原操作回执待确认', 'DATABASE_ERROR' => '配置服务暂不可用',
        'DEPENDENCY_UNAVAILABLE' => '配置服务暂不可用', 'DATA_INTEGRITY' => '配置服务暂不可用',
    ];

    public static function success(string $routeName, array $data): JsonResponse
    {
        unset($data['operation_status']);
        if (in_array($routeName, ['grid.config.save', 'grid.config.receipt'], true)) {
            $data['operation_status'] = 'SUCCEEDED';
        }
        return new JsonResponse(['code' => 1, 'show' => 0, 'msg' => '', 'data' => $data]);
    }

    public static function failure(string $routeName, string $code, ?int $forbiddenStatus = null): JsonResponse
    {
        if (!isset(self::STATUS[$code])) {
            $code = 'DEPENDENCY_UNAVAILABLE';
        }
        $status = $code === 'FORBIDDEN' && $forbiddenStatus === 401 ? 401 : self::STATUS[$code];
        $data = ['error_code' => $code, 'trace_id' => (string) Str::uuid()];
        if ($routeName === 'grid.config.receipt') {
            $data['operation_status'] = 'UNKNOWN';
        } elseif ($routeName === 'grid.config.save') {
            $data['operation_status'] = in_array($code, ['COMMIT_UNKNOWN', 'RECEIPT_UNCONFIRMED', 'DATABASE_ERROR', 'DEPENDENCY_UNAVAILABLE', 'DATA_INTEGRITY'], true)
                ? 'UNKNOWN' : 'FAILED';
        }
        return new JsonResponse(['code' => 0, 'show' => 1, 'msg' => self::MESSAGES[$code], 'data' => $data], $status);
    }

    public static function reject(string $routeName, string $code, ?int $forbiddenStatus = null): void
    {
        throw new HttpResponseException(self::failure($routeName, $code, $forbiddenStatus));
    }
}
