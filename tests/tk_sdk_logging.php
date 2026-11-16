<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__, 2).'/agent_admin/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'SixMm\\Shared\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    }
}, true, true);

use SixMm\Shared\RpcLogging\{TkRpcLogger, TkSdkCall};
use TradeKernel\Sdk\{ClientOptions, Endpoint, RpcException, RpcStatus, TradeKernelClient, TransportInterface};

$checks = 0;
$check = static function (bool $ok, string $why) use (&$checks): void {
    ++$checks;
    if (!$ok) throw new RuntimeException($why);
};
$events = [];
$writer = static function (string $level, string $message, array $context) use (&$events): void {
    $events[] = ['level' => $level, 'log_message' => $message] + $context;
};
$logger = static fn(string $method = 'GetAccount') => new TkRpcLogger('admin', $method, 'offline-read', [], $writer);
$client = static function (callable $invoke, int $attempts = 1): TradeKernelClient {
    $transport = new class($invoke) implements TransportInterface {
        public function __construct(private $invoke) {}
        public function invoke(Endpoint $endpoint, string $method, array $request, array $metadata): array {
            return ($this->invoke)($endpoint, $method, $request, $metadata);
        }
    };
    return new TradeKernelClient(ClientOptions::forLoadBalancer('admin', 'offline-write', 'offline-read', $attempts, $attempts > 1 ? 1 : 0, 1),
        $transport, null, null, static fn() => null);
};
$sdk = $client(static fn() => throw new LogicException('Invalid command must never reach transport'));
try {
    TkSdkCall::run('admin', 'CancelOrder', ['command_id' => '0', 'apiSecret' => 'private-sdk-secret'],
        fn() => $sdk->call('CancelOrder', ['command_id' => '0']), [], $writer);
    throw new LogicException('Expected SDK command validation failure');
} catch (InvalidArgumentException $error) {
    $check(count($events) === 1 && $events[0]['stage'] === 'sdk_before_send', 'SDK validation logged before native send');
    $check($events[0]['layer'] === 'sdk' && $events[0]['endpoint'] === null, 'Unsent error does not invent an actual node');
    $check(!str_contains(json_encode($events), 'private-sdk-secret'), 'SDK request credentials redacted');
}
$check(TkSdkCall::current() === null, 'Failure restores SDK context');

$events = [];
$codec = new \TradeKernel\Sdk\ProtobufCodec();
$transport = new \TradeKernel\Sdk\GrpcTransport(
    static fn() => throw new LogicException('Invalid protobuf must not create a channel'),
    [$codec, 'request'], [$codec, 'response']);
$sdk = new TradeKernelClient(ClientOptions::forLoadBalancer('admin', 'offline-write', 'offline-read', 1, 0), $transport);
try {
    TkSdkCall::run('admin', 'GetAccount', ['user_id' => []],
        fn() => $sdk->call('GetAccount', ['user_id' => []]), [], $writer);
    throw new LogicException('Expected real protobuf encoding rejection');
} catch (InvalidArgumentException $error) {
    $check(count($events) === 1 && $events[0]['stage'] === 'sdk_before_send', 'Real protobuf encoding error logged before channel creation');
    $check($events[0]['message'] === $error->getMessage(), 'Original encoding error preserved');
}

$events = [];
$request = ['home_site_id' => '910023', 'agent_id' => '0'];
$sdk = $client(function ($endpoint, $method, $request) use ($logger): array {
    $log = $logger($method); $log->start($request);
    $response = ['orders' => []];
    $log->response($response, ['code' => 0]);
    return $response;
});
try {
    TkSdkCall::run('admin', 'AdminListOpenOrders', $request,
        fn() => $sdk->call('AdminListOpenOrders', $request), [], $writer);
    throw new LogicException('Expected SDK response-scope validation failure');
} catch (RpcException $error) {
    $check($error->status() === RpcStatus::FAILED_PRECONDITION, 'SDK response validation still rejects');
    $check(array_column($events, 'event') === ['request', 'response', 'exception'], 'Post-response validation adds one exception');
    $check($events[2]['stage'] === 'sdk_after_response' && $events[2]['layer'] === 'sdk', 'Post-response stage identified');
    $check(count(array_unique(array_column($events, 'sdk_call_id'))) === 1, 'SDK and transport events share correlation');
    $check($events[2]['call_id'] === $events[1]['call_id'] && $events[2]['endpoint'] === 'offline-read', 'Validation error points to its native attempt');
}

foreach ([4 => RpcStatus::DEADLINE_EXCEEDED, 14 => RpcStatus::UNAVAILABLE, 12 => RpcStatus::UNKNOWN] as $code => $status) {
    $events = [];
    $failure = new RpcException($status);
    $sdk = $client(function ($endpoint, $method, $request) use ($logger, $code, $failure): array {
        $log = $logger($method); $log->start($request);
        $log->response(null, ['code' => $code, 'details' => '']);
        throw $failure;
    });
    try {
        TkSdkCall::run('admin', 'GetAccount', [], fn() => $sdk->call('GetAccount'), [], $writer);
        throw new LogicException('Expected transport rejection');
    } catch (RpcException $actual) {
        $check($actual === $failure, 'Original transport exception preserved for '.$code);
        $check(count(array_filter($events, fn($e) => $e['event'] === 'exception')) === 1, 'Native status error not duplicated for '.$code);
    }
}

$events = [];
$failure = new RpcException(RpcStatus::UNAVAILABLE, 'connection refused');
$mapped = new DomainException('existing business mapping', 0, $failure);
try {
    TkSdkCall::run('admin', 'GetAccount', [], function () use ($logger, $mapped) {
        $log = $logger(); $log->start([]); $log->response(null, ['code' => 14, 'details' => 'connection refused']);
        throw $mapped;
    }, [], $writer);
} catch (DomainException $actual) {
    $check($actual === $mapped && count($events) === 3, 'Existing mapped transport exception is not duplicated');
}

$events = [];
$nativeError = new RuntimeException('native start error');
$failure = new RpcException(RpcStatus::NETWORK_ERROR, 'native start error', 0, $nativeError);
try {
    TkSdkCall::run('admin', 'GetAccount', [], function () use ($logger, $nativeError, $failure) {
        $log = $logger(); $log->start([]); $log->exception($nativeError, 'start');
        throw $failure;
    }, [], $writer);
} catch (RpcException $actual) {
    $check($actual === $failure && count($events) === 2, 'Wrapped native throwable is not logged again');
}

$events = [];
$attempts = 0;
$sdk = $client(function ($endpoint, $method, $request) use ($logger, &$attempts): array {
    ++$attempts;
    $log = $logger($method); $log->start($request);
    if ($attempts === 1) {
        $log->response(null, ['code' => 14, 'details' => 'temporary']);
        throw new RpcException(RpcStatus::UNAVAILABLE, 'temporary');
    }
    $response = ['userId' => '9007199254740993', 'walletBalance' => '0.000000000000000001'];
    $log->response($response, ['code' => 0]);
    return $response;
}, 2);
$result = TkSdkCall::run('admin', 'GetAccount', ['user_id' => '9007199254740993'],
    fn() => $sdk->call('GetAccount', ['user_id' => '9007199254740993']), [], $writer);
$check($attempts === 2 && $result['walletBalance'] === '0.000000000000000001', 'SDK retry count and exact response unchanged');
$requests = array_values(array_filter($events, fn($e) => $e['event'] === 'request'));
$check(count(array_unique(array_column($requests, 'call_id'))) === 2, 'Retries have separate physical IDs');
$check(count(array_unique(array_column($events, 'sdk_call_id'))) === 1, 'Retries share one SDK call ID');
$check(TkSdkCall::current() === null, 'Success restores SDK context');

$events = [];
$failure = new InvalidArgumentException('encode retry failed');
try {
    TkSdkCall::run('admin', 'GetAccount', [], function () use ($logger, $failure) {
        $log = $logger(); $log->start([]); $log->response(null, ['code' => 14, 'details' => 'temporary']);
        throw $failure;
    }, [], $writer);
} catch (InvalidArgumentException $actual) {
    $check($actual === $failure && end($events)['stage'] === 'sdk_between_attempts', 'New encoding error after a failed attempt is not suppressed');
}

$events = [];
$failure = new RuntimeException('response decoder failed');
try {
    TkSdkCall::run('admin', 'GetAccount', [], function () use ($logger, $failure) {
        $log = $logger(); $log->start([]); $log->response([], ['code' => 0]);
        throw $failure;
    }, [], $writer);
} catch (RuntimeException $actual) {
    $check($actual === $failure && end($events)['stage'] === 'sdk_after_response', 'Decoder exception preserved and recorded');
}

$events = [];
try {
    TkSdkCall::run('admin', 'outer', [], fn() => TkSdkCall::run('admin', 'inner', [],
        static fn() => throw new RuntimeException('nested'), [], $writer), [], $writer);
} catch (RuntimeException) {
    $check(count($events) === 1 && TkSdkCall::current() === null, 'Nested wrappers log once and restore parent context');
}

$events = [];
$seen = [];
$makeFiber = static function () use (&$seen): Fiber {
    return new Fiber(static function () use (&$seen) {
        TkSdkCall::run('admin', 'fiber', [], static function () use (&$seen) {
            $seen[] = TkSdkCall::current()->id(); Fiber::suspend(); $seen[] = TkSdkCall::current()->id();
        });
    });
};
$first = $makeFiber(); $second = $makeFiber();
$first->start(); $second->start(); $first->resume(); $second->resume();
$check($seen[0] !== $seen[1] && $seen[0] === $seen[2] && $seen[1] === $seen[3], 'Concurrent fiber contexts stay isolated');
$check(TkSdkCall::current() === null, 'Fiber context never leaks to main execution');

$failure = new RuntimeException('unchanged with broken logger');
try {
    TkSdkCall::run('agent_admin', 'GetAccount', [], static fn() => throw $failure, [], static fn() => throw new RuntimeException('disk unavailable'));
} catch (RuntimeException $actual) {
    $check($actual === $failure, 'Writer failure cannot replace SDK exception');
}
echo "TK SDK logging: {$checks} checks passed\n";
