<?php
declare(strict_types=1);

require (getenv('SIXMM_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php');

use SixMm\Shared\RpcErrors\TkErrorMessages;
use SixMm\Shared\RpcErrors\TkRpcException;
use SixMm\Shared\HandlingFees\FeeLevelBatch;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    ++$checks;
    if (!$ok) throw new RuntimeException($message);
};
preg_match_all('/^\| `(\d+)` \| `Reject\w+` \| ([^|]+)/m', file_get_contents(dirname(__DIR__).'/docs/tk-reject-code-reference.md'), $rows, PREG_SET_ORDER);
$check(count($rows) === 74 && count(TkErrorMessages::BUSINESS) === 74, 'Exactly the documented 74 values, including success');
foreach ($rows as $row) {
    $code = (int) $row[1];
    $chinese = str_replace('`', '', trim($row[2]));
    $check(TkErrorMessages::business($code) === "TK：{$code}，{$chinese}", 'Exact documented Chinese mapping: '.$code);
    if ($code === 0) continue;
    $e = TkRpcException::business($code, 'TestRpc', [], 'raw upstream text');
    $check($e->getCode() === $code && $e->rejectCode === $code && $e->grpcStatus === null, 'Keep actual business code: '.$code);
    $check($e->rawMessage === 'raw upstream text' && !str_contains($e->getMessage(), 'upstream'), 'Raw diagnostic is separate from display');
}
$check(TkErrorMessages::business(9876) === 'TK：9876，未知业务错误，请联系管理员核对', 'Unknown code is not replaced with 9999');
$check(str_contains(TkErrorMessages::business(7004, 'DelistSymbolTrading'), '暂无有效标记价'), 'Automatic delisting explains absent valid price without claiming a single cause');
$check(str_contains(TkErrorMessages::business(7004, 'DelistSymbolTrading', ['mark_price'=>'1','mark_price_time'=>'1']), '下架价格或价格时间无效'), 'Explicit price/time error');
$check(str_contains(TkErrorMessages::business(7004, 'SettleFundingRate'), '结算价格或价时依据无效'), 'Funding settlement retains generic semantics');
foreach (['InvalidArgument','Unauthenticated','PermissionDenied','NotFound','AlreadyExists','FailedPrecondition','Aborted',
    'Unavailable','ResourceExhausted','DeadlineExceeded','Cancelled','Unknown','Unimplemented','Internal','DataLoss'] as $status) {
    $e = TkRpcException::transport($status, 'TestRpc');
    $check($e->rejectCode === null && $e->getCode() === 0 && $e->grpcStatus === $status, 'Transport is not a business rejection');
    $check(str_starts_with($e->getMessage(), 'TK：gRPC状态码：'.$status.'，'), 'Separate transport namespace');
}
$check(str_contains(TkErrorMessages::transport(5), 'NotFound') && !str_contains(TkErrorMessages::transport(5), '3014'), 'NotFound never becomes order rejection');
$e = TkRpcException::business(6003);
$check(TkRpcException::find(new DomainException('wrapper', 0, $e)) === $e, 'Nested transactions retain original structured rejection');
$payload = ['items'=>[
    ['level'=>0,'home_site_id'=>'10','agent_id'=>'0'],
    ['level'=>1,'home_site_id'=>'10','agent_id'=>'0'],
]];
foreach ([6010,-1] as $code) {
    $response=['results'=>[
        ['homeSiteId'=>'10','level'=>0],
        ['homeSiteId'=>'10','level'=>1,'rejectCode'=>$code,'message'=>'raw text'],
    ],'totalCount'=>2,'successCount'=>1,'failedCount'=>1];
    try { FeeLevelBatch::confirm($payload,$response); throw new LogicException('Rejected batch accepted'); }
    catch (TkRpcException $error) {
        $check($error->rejectCode === $code, 'Mixed batch preserves actual failed item code');
        $check($response['successCount'] === 1, 'Original partial-success response remains unchanged');
    }
}
$calls = 0;
$raw = ['rejectCode'=>6002,'message'=>'raw text'];
$check(TkRpcException::call('TestRpc', function () use (&$calls,$raw) { ++$calls; return $raw; }) === $raw && $calls === 1,
    'Transport wrapper neither retries nor changes business response semantics');
echo "TK RPC errors: {$checks} checks passed\n";
