<?php
declare(strict_types=1);
namespace SixMm\Shared\Prediction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Throwable;
final class ReadResponder {
    private const ERRORS=[
        'INVALID_REQUEST'=>[422,'请求参数无效'],
        'INVALID_ARGUMENT'=>[422,'请求参数无效'],
        'USER_FILTER_TOO_BROAD'=>[422,'匹配用户超过500个，请缩小筛选条件'],
        'SCOPE_UNAVAILABLE'=>[503,'站点授权暂不可用'],
        'FORBIDDEN'=>[403,'无权访问'],
        'PERMISSION_DENIED'=>[403,'无权访问'],
        'UNAUTHENTICATED'=>[401,'登录状态已失效，请重新登录'],
        'ORDER_NOT_FOUND'=>[404,'订单不存在'],
        'NOT_FOUND'=>[404,'订单不存在'],
        'QUERY_CONTRACT_MISMATCH'=>[503,'查询服务版本不匹配'],
        'DEPENDENCY_UNAVAILABLE'=>[503,'服务暂不可用'],
        'UNAVAILABLE'=>[503,'服务暂不可用'],
        'DEADLINE_EXCEEDED'=>[503,'查询超时，请稍后重试'],
        'CANCELED'=>[503,'查询已取消，请重试'],
    ];
    public static function run(callable $read,?string $traceId=null): JsonResponse {
        try { return GridResponder::success($read()); }
        catch (HttpResponseException $error) {
            $response=$error->getResponse();
            $body=$response instanceof JsonResponse ? $response->getData(true) : [];
            $code=$body['data']['error_code']??'DEPENDENCY_UNAVAILABLE';
            return self::failure(new ReadException(is_string($code)?$code:'DEPENDENCY_UNAVAILABLE',$response->getStatusCode()),$traceId);
        }
        catch (Throwable $error) { return self::failure($error,$traceId); }
    }
    public static function failure(Throwable $error,?string $traceId=null): JsonResponse {
        $code=$error instanceof ReadException ? $error->errorCode :
            (method_exists($error,'errorCode') ? $error->errorCode() : 'DEPENDENCY_UNAVAILABLE');
        $code=is_string($code)?preg_replace('/^ERROR_CODE_/','',$code):'DEPENDENCY_UNAVAILABLE';
        if (!isset(self::ERRORS[$code])) { $code='DEPENDENCY_UNAVAILABLE'; }
        [$status,$message]=self::ERRORS[$code];
        // Existing host authentication chains use FORBIDDEN for both login and permission failures.
        if ($error instanceof ReadException && $code==='FORBIDDEN' && $error->httpStatus===401) {
            $status=401;
            $message=self::ERRORS['UNAUTHENTICATED'][1];
        }
        // Never serialize transport details, SQL, credentials or arbitrary exception messages.
        return new JsonResponse(['code'=>0,'show'=>1,'msg'=>$message,
            'data'=>['error_code'=>$code,'trace_id'=>$traceId ?? (string)Str::uuid()]],$status);
    }
}
