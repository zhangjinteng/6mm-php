<?php
declare(strict_types=1);

require getenv('PREDICTION_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'SixMm\\Shared\\Prediction\\Configuration\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__.'/../src/Prediction/Configuration/'.substr($class, strlen($prefix)).'.php';
    }
}, true, true);

use SixMm\Shared\Prediction\Configuration\ConfigurationActor;
use SixMm\Shared\Prediction\Configuration\ConfigurationFailure;
use SixMm\Shared\Prediction\Configuration\ConfigurationResponder as Responder;
use SixMm\Shared\Prediction\Configuration\GridConfigException;
use SixMm\Shared\Prediction\Configuration\GridConfigInput;
use SixMm\Shared\Prediction\Configuration\GridConfigResponder;
use SixMm\Shared\Prediction\Configuration\SiteConfigValidator;

function check(bool $condition, string $name): void {
    if (!$condition) throw new RuntimeException($name);
}
$actor = new class implements ConfigurationActor {
    public function operatorId(): string { return '100'; }
    public function agentId(): string { return '100'; }
    public function traceId(): string { return 'new-trace'; }
};
$input = ['game_type'=>'HIGH_LOW', 'symbol'=>'BTCUSDT', 'site_id'=>'site-A',
    'client_request_id'=>'original-request', 'reason'=>'fixture', 'based_on_public_version'=>'8',
    'based_on_site_version'=>'1', 'enabled'=>false, 'overrides'=>[]];
$receipt = ['operation_id'=>'operation-1', 'operator_id'=>'100', 'client_request_id'=>'original-request',
    'capability'=>'prediction.site.config.publish', 'action'=>'ADMIN_ACTION_SITE_CONFIG_PUBLISH',
    'target_type'=>'site_config', 'target_id'=>SiteConfigValidator::targetID($input), 'reason'=>'fixture',
    'before_json'=>'', 'after_json'=>base64_encode('{}'), 'result'=>'OPERATION_RESULT_SUCCEEDED',
    'error_code'=>'', 'created_at_ms'=>'1000', 'completed_at_ms'=>'1001', 'source_service'=>'agent_admin',
    'trace_id'=>'original-trace'];
$saved = ['stored'=>['site_id'=>'site-A', 'game_type'=>'GAME_TYPE_HIGH_LOW', 'symbol'=>'BTCUSDT',
    'version'=>'2', 'based_on_public_version'=>'8', 'enabled'=>false, 'overrides'=>[]], 'receipt'=>$receipt];
$response = Responder::site($actor, fn()=>$saved, $input, true)->getData(true);
check($response['code'] === 1 && $response['data']['receipt']['trace_id'] === 'original-trace', 'replay retains original receipt');
foreach (['operator_id'=>'101', 'client_request_id'=>'replacement', 'target_id'=>'other-site', 'source_service'=>'platform_admin'] as $key=>$value) {
    $bad = $saved;
    $bad['receipt'][$key] = $value;
    $result = Responder::site($actor, fn()=>$bad, $input, true)->getData(true);
    check($result['code'] === 0 && $result['data']['error_code'] === 'OUTCOME_UNKNOWN', 'invalid receipt fails closed: '.$key);
    check($result['data']['client_request_id'] === 'original-request', 'original request retained');
}
$bad = $saved;
$bad['stored']['site_id'] = 'site-B';
check(Responder::site($actor, fn()=>$bad, $input, true)->getData(true)['code'] === 0, 'cross-site result rejected');

foreach ([false, true] as $save) {
    $response = Responder::platform(fn()=>throw new RuntimeException('secret SQL password'), 'trace-1', $save);
    check(!str_contains($response->getContent(), 'secret'), 'unknown exception sanitized');
    check($response->getData(true)['data']['error_code'] === ($save ? 'ERROR_CODE_OUTCOME_UNKNOWN' : 'ERROR_CODE_UNAVAILABLE'), 'write ambiguity preserved');
}
$response = Responder::owner($actor, fn()=>throw new ConfigurationFailure('INTERNAL', ['password'=>'secret']), $input, 'save');
check(!str_contains($response->getContent(), 'secret'), 'merchant internal detail sanitized');
try {
    Responder::platform(fn()=>throw new Illuminate\Http\Exceptions\HttpResponseException(new Illuminate\Http\JsonResponse([], 403)), 'trace-1');
    throw new RuntimeException('permission failure swallowed');
} catch (Illuminate\Http\Exceptions\HttpResponseException $error) {
    check($error->getResponse()->getStatusCode() === 403, 'host authorization retained');
}

$gridActor = ['source_service'=>'agent_admin', 'operator_id'=>'100', 'root_agent_id'=>'100'];
$command = ['site_id'=>'site-A', 'symbol'=>'BTCUSDT', 'based_on_layout_version'=>'1', 'based_on_site_version'=>'1',
    'client_request_id'=>'original-request', 'reason'=>'fixture', 'changes'=>['enabled'=>false, 'target_rtp'=>'0.9600']];
$normalized = GridConfigInput::command($gridActor, $command);
check($normalized['changes']['target_rtp'] === '0.96' && $normalized['changes']['enabled'] === false, 'exact decimals and false retained');
$legacy = ['actor'=>['source_service'=>'agent_admin', 'operator_id'=>'100', 'root_agent_id'=>'100'],
    'site_id'=>'site-A', 'symbol'=>'BTCUSDT', 'based_on_layout_version'=>'1', 'based_on_site_version'=>'1',
    'client_request_id'=>'original-request', 'reason'=>'fixture', 'changes'=>['enabled'=>false, 'target_rtp'=>'0.96']];
check(GridConfigInput::requestHash($gridActor, $normalized) === hash('sha256', json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 'historical request hash unchanged');
foreach (['price_decimals'=>3, 'price_band_width'=>'1', 'column_duration_seconds'=>'10', 'min_multiplier'=>'1.01', 'max_multiplier'=>'5'] as $key=>$value) {
    try {
        GridConfigInput::command($gridActor, array_replace($command, ['changes'=>[$key=>$value]]));
        throw new RuntimeException('merchant field accepted: '.$key);
    } catch (GridConfigException $error) {
        check($error->errorCode() === 'FIELD_FORBIDDEN', 'merchant field restriction');
    }
}
foreach (['COMMIT_UNKNOWN', 'DATABASE_ERROR', 'DATA_INTEGRITY'] as $code) {
    check(GridConfigResponder::failure('grid.config.save', $code)->getData(true)['data']['operation_status'] === 'UNKNOWN', 'grid uncertainty retained');
}
echo "Prediction configuration scope, recovery and error checks passed\n";
