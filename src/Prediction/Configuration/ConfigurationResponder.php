<?php
declare(strict_types=1);
namespace SixMm\Shared\Prediction\Configuration;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

final class ConfigurationResponder
{
    public static function owner(ConfigurationActor $actor, callable $call, array $input, string $method): JsonResponse
    {
        return self::merchant($call, $input, $method === 'save', static function (array $data) use ($actor, $input, $method): void {
            (new OwnerConfigValidator())->validate($actor, $data, $input, $method);
        });
    }

    public static function site(ConfigurationActor $actor, callable $call, ?array $input, bool $save): JsonResponse
    {
        return self::merchant($call, $input ?? [], $save, static function (array $data) use ($actor, $input, $save): void {
            if ($input !== null) (new SiteConfigValidator())->validate($actor, $data, $input, $save);
        });
    }

    private static function merchant(callable $call, array $input, bool $save, callable $validate): JsonResponse
    {
        try {
            $data = $call();
            $validate($data);
            return self::response(true, $data);
        } catch (HttpResponseException $error) {
            throw $error;
        } catch (ConfigurationFailure $error) {
            $data = ['error_code' => $error->errorCode];
            // Only the protocol control flag is public. Never leak transport internals.
            if (isset($error->detail['control']) && is_array($error->detail['control'])) {
                $data['control'] = $error->detail['control'];
            }
        } catch (\Throwable $error) {
            // A timeout or invalid success receipt cannot prove that a write did not commit.
            $data = ['error_code' => $save ? 'OUTCOME_UNKNOWN' : 'INTERNAL'];
        }
        if ($save) $data['client_request_id'] = $input['client_request_id'] ?? '';
        return self::response(false, $data, '预测配置请求未确认成功');
    }

    public static function platform(callable $call, string $traceId, bool $save = false): JsonResponse
    {
        try {
            return self::response(true, $call(), 'success');
        } catch (HttpResponseException $error) {
            throw $error;
        } catch (ConfigurationFailure $error) {
            $public = in_array($error->errorCode, [
                'ERROR_CODE_INVALID_ARGUMENT', 'ERROR_CODE_NOT_FOUND', 'ERROR_CODE_CONFLICT',
                'ERROR_CODE_OUTCOME_UNKNOWN', 'ERROR_CODE_MARK_PRICE_UNAVAILABLE',
                'ERROR_CODE_UNAUTHENTICATED', 'ERROR_CODE_PERMISSION_DENIED',
                'ERROR_CODE_UNAVAILABLE', 'ERROR_CODE_CANCELED',
            ], true);
            $data = ['error_code' => $error->errorCode, 'grpc_status_code' => $error->grpcStatus,
                'detail' => $public ? $error->detail : [], 'request_id' => $traceId];
            if ($public && isset($error->detail['control'])) $data['control'] = $error->detail['control'];
            return self::response(false, $data, $public ? $error->getMessage() : 'Prediction gRPC 调用失败');
        } catch (\Throwable $error) {
            return self::response(false, ['error_code' => $save ? 'ERROR_CODE_OUTCOME_UNKNOWN' : 'ERROR_CODE_UNAVAILABLE',
                'request_id' => $traceId], '预测配置请求未确认成功');
        }
    }

    private static function response(bool $success, array $data, string $message = ''): JsonResponse
    {
        return new JsonResponse(['code' => $success ? 1 : 0, 'show' => $success ? 0 : 1, 'msg' => $message, 'data' => $data]);
    }
}
