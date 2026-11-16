<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$host = dirname(__DIR__, 2).'/agent_admin/vendor/autoload.php';
if (is_file($host)) require $host;
spl_autoload_register(static function (string $class): void {
    $prefix = 'SixMm\\Shared\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    }
}, true, true);

use SixMm\Shared\RpcLogging\TkRpcLogger;
use SixMm\Shared\RpcTickets\{RecordedUnaryCall, WriteJournal};

$checks = 0;
$check = static function (bool $ok, string $why) use (&$checks): void {
    ++$checks;
    if (!$ok) throw new RuntimeException($why);
};
$events = [];
$writer = static function (string $level, string $message, array $context) use (&$events): void {
    $events[] = ['level' => $level, 'message' => $message] + $context;
};
$makeLogger = static fn(string $method = 'DelistSymbolTrading', string $identity = 'admin', ?callable $sink = null) =>
    new TkRpcLogger($identity, '/trade_kernel.TradeKernel/'.$method, 'offline:9090', ['timeout' => 8000000], $sink ?? $writer);

$request = ['commandId' => '9007199254740993', 'symbol' => 'SOXSUSDT', 'scope' => ['kind' => 1],
    'apiSecret' => 'private-request', 'nested' => ['password' => 'private-password'],
    'items' => [['command_id' => '9007199254740994']], 'amount' => '0.000000000000000001'];
$original = $request;
$log = $makeLogger();
$log->start($request);
$log->response(['rejectCode' => 7004, 'message' => 'invalid settlement_price'], (object) ['code' => 0, 'details' => '']);
$log->response([], (object) ['code' => 0]);
$check(count($events) === 2, 'One request and response, without duplicate finish');
$check($events[0]['call_id'] === $events[1]['call_id'] && strlen($events[0]['call_id']) === 32, 'Correlated physical call ID');
$check($events[0]['application'] === 'platform' && $events[0]['method'] === 'DelistSymbolTrading', 'Stable application and method');
$check($events[0]['endpoint'] === 'offline:9090' && $events[0]['timeout_ms'] === 8000, 'Actual node and timeout');
$check($events[0]['command_ids'] === ['9007199254740993', '9007199254740994'], 'Exact root and batch command IDs');
$check($events[0]['request']['amount'] === $request['amount'] && $request === $original, 'Payload and decimal precision unchanged');
$check($events[1]['level'] === 'warning' && $events[1]['reject_code'] === 7004 && $events[1]['result'] === 'business_rejected', 'TK rejection is not recorded as successful business');
$check($events[1]['duration_ms'] >= 0, 'Elapsed duration recorded');
$check(!str_contains(json_encode($events), 'private-request') && !str_contains(json_encode($events), 'private-password'), 'Nested credentials redacted');

$events = [];
$batch = $makeLogger('BatchUpdateFeeLevelConfig', 'agent_admin');
$batch->start(['items' => [['command_id' => '12']]]);
$batch->response(['results' => [[], ['rejectCode' => 7004]]], ['code' => 0]);
$check($events[0]['application'] === 'agent' && $events[1]['result'] === 'batch_rejected', 'Agent and partial batch rejection visible');
$check($events[1]['batch_failures'] === [['index' => 1, 'reject_code' => 7004]], 'Failed result indices and codes recorded');

$events = [];
$log = $makeLogger();
$log->start([]);
$error = new RuntimeException('Authorization: Bearer hidden-token; password=hidden-password');
$log->exception($error, 'wait');
$check($events[1]['event'] === 'exception' && $events[1]['stage'] === 'wait', 'Thrown error has dedicated event');
$check(!str_contains(json_encode($events), 'hidden-token') && !str_contains(json_encode($events), 'hidden-password'), 'Exception credentials redacted');

$events = [];
$log = $makeLogger('ListSymbolTradingConfigs');
$log->start([]);
$log->response(null, ['code' => 4, 'details' => 'deadline exceeded']);
$check(array_column($events, 'event') === ['request', 'response', 'exception'], 'Nonzero gRPC status recorded as transport error');
$check($events[1]['status_code'] === 4 && $events[1]['result'] === 'transport_error', 'Transport and business errors remain distinct');

$events = [];
$log = $makeLogger();
$log->start(['body' => str_repeat('x', 1048577)]);
$log->response(['body' => str_repeat('y', 1048577)], ['code' => 0]);
$check($events[0]['request']['truncated'] && $events[1]['response']['truncated'], 'Oversized payloads bounded');

$events = [];
$log = $makeLogger();
$log->start(new class { public function serializeToJsonString(): string { throw new RuntimeException('bad proto'); } });
$log->response([], ['code' => 0]);
$check($events[0]['request']['unavailable'] === true && $events[1]['result'] === 'rpc_ok', 'Serialization error cannot affect call observation');

if (!class_exists(Grpc\UnaryCall::class)) {
    throw new RuntimeException('Native gRPC runtime is required for wire-preservation checks');
}
require __DIR__.'/fixtures/rpc_ticket_store.php';
use TradeKernel\Sdk\Generated\Message\{AccountChangeRequest, AccountChangeResponse, GetAccountRequest};
$store = new MemoryRpcTicketStore();
$journal = new WriteJournal($store, 'agent');
$channel = new Grpc\Channel('127.0.0.1:1', ['credentials' => Grpc\ChannelCredentials::createInsecure()]);
$makeCall = static function (string $method, string $service = 'tk', ?TkRpcLogger $logger = null) use ($channel, $journal, $makeLogger): array {
    $call = new RecordedUnaryCall($channel, '/trade_kernel.TradeKernel/'.$method,
        [AccountChangeResponse::class, 'decode'], ['timeout' => 8000000], $journal, $service, 'agent_admin', 'offline:9090',
        $logger ?? $makeLogger($method, 'agent_admin'));
    $transport = new class {
        public array $batches = [];
        public ?Throwable $startError = null;
        public ?Throwable $waitError = null;
        public int $code = 0;
        public function startBatch(array $batch): object {
            $this->batches[] = $batch;
            $send = isset($batch[Grpc\OP_SEND_MESSAGE]);
            if ($send && $this->startError) throw $this->startError;
            if (!$send && $this->waitError) throw $this->waitError;
            return $send ? (object) [] : (object) ['message' => $this->code === 0 ? (new AccountChangeResponse())->serializeToString() : null,
                'metadata' => [], 'status' => (object) ['code' => $this->code, 'details' => '', 'metadata' => []]];
        }
    };
    (new ReflectionProperty(Grpc\UnaryCall::class, 'call'))->setValue($call, $transport);
    return [$call, $transport];
};
try {
    $events = [];
    [$query, $wire] = $makeCall('GetAccount');
    $proto = new GetAccountRequest(['user_id' => '9007199254740993']);
    $metadata = ['authorization' => ['Bearer wire-secret']];
    $query->start($proto, $metadata);
    [$response, $status] = $query->wait();
    $check($wire->batches[0][Grpc\OP_SEND_MESSAGE]['message'] === $proto->serializeToString(), 'Query wire bytes unchanged');
    $check($wire->batches[0][Grpc\OP_SEND_INITIAL_METADATA]['authorization'] === $metadata['authorization'], 'Authentication metadata unchanged');
    $check($response instanceof AccountChangeResponse && $status->code === 0 && $store->tickets === [], 'Query return and write-ticket policy unchanged');
    $check(array_column($events, 'event') === ['request', 'response'] && !str_contains(json_encode($events), 'wire-secret'), 'Query gets logs without persisting metadata secrets');

    $events = [];
    [$write] = $makeCall('AccountChange');
    $write->start(new AccountChangeRequest(['command_id' => '9007199254740993', 'user_id' => '9007199254740993']));
    $write->wait();
    $check(count($store->tickets) === 1 && array_values($store->tickets)[0]['rpc_state'] === 'confirmed', 'Existing write receipt still completed');
    $check(count($events) === 2, 'Write emits one request-response pair');

    foreach (['start', 'wait'] as $stage) {
        $events = [];
        [$call, $wire] = $makeCall('GetAccount');
        $failure = new RuntimeException('fixture '.$stage);
        $wire->{$stage.'Error'} = $failure;
        try {
            $call->start($proto);
            $call->wait();
            throw new LogicException('Expected original exception');
        } catch (RuntimeException $actual) {
            $check($actual === $failure, 'Original '.$stage.' exception preserved');
            $check($events[1]['event'] === 'exception' && $events[1]['stage'] === $stage, $stage.' error logged');
        }
    }

    $events = [];
    [$call, $wire] = $makeCall('GetAccount');
    $wire->code = 4;
    $call->start($proto);
    [$response, $status] = $call->wait();
    $check($response === null && $status->code === 4 && $events[1]['status_code'] === 4, 'Transport failure returned unchanged');

    $events = [];
    $store->failCreate = true;
    [$call, $wire] = $makeCall('AccountChange');
    try {
        $call->start(new AccountChangeRequest(['command_id' => '123']));
        throw new LogicException('Write unexpectedly sent');
    } catch (RuntimeException) {
        $check($wire->batches === [] && $events[1]['stage'] === 'start', 'Ticket storage failure still blocks send and is logged');
    } finally { $store->failCreate = false; }

    $events = [];
    [$other] = $makeCall('GetOrder', 'condition');
    $other->start($proto); $other->wait();
    $check($events === [], 'Non-TK services unchanged');

    $brokenLog = $makeLogger('GetAccount', 'agent_admin', static fn() => throw new RuntimeException('log unavailable'));
    [$call] = $makeCall('GetAccount', 'tk', $brokenLog);
    $call->start($proto);
    [$response, $status] = $call->wait();
    $check($response instanceof AccountChangeResponse && $status->code === 0, 'Logger failure cannot alter successful RPC');
} finally { $channel->close(); }

echo "TK RPC logging: {$checks} checks passed\n";
