<?php
declare(strict_types=1);

namespace SixMm\Shared\RpcLogging;

use Closure;
use Illuminate\Support\Facades\Log;
use SixMm\Shared\RpcTickets\TicketPayload;
use Throwable;

/** Observes one physical gRPC attempt without changing its payload, result or exception. */
final class TkRpcLogger
{
    private array $context = [];
    private int $started = 0;
    private bool $finished = false;
    private Closure $writer;
    private ?TkSdkCall $sdkScope = null;

    public function __construct(
        private string $identity,
        private string $method,
        private string $endpoint,
        private array $options = [],
        ?callable $writer = null
    ) {
        $this->writer = $writer === null
            ? static fn(string $level, string $event, array $context) => Log::channel('tk_rpc')->log($level, $event, $context)
            : Closure::fromCallable($writer);
    }

    public function start(mixed $request): void
    {
        try {
            $data = $this->prepare($request);
            $this->sdkScope = TkSdkCall::current();
            if ($this->sdkScope !== null) {
                $this->context['sdk_call_id'] = $this->sdkScope->id();
                $this->sdkScope->beginAttempt($this->context);
            }
            $this->write('info', 'request', ['request' => TicketPayload::response($data)]);
        } catch (Throwable) {
            // Logging must never prevent a financial request from being sent.
        }
    }

    private function prepare(mixed $request): mixed
    {
        $this->started = hrtime(true);
        $data = $this->payload($request);
        $this->context = [
            'schema_version' => 1,
            'call_id' => $this->options['call_id'] ?? bin2hex(random_bytes(16)),
            'application' => $this->identity === 'admin' ? 'platform' : 'agent',
            'service' => 'tk',
            'service_identity' => $this->identity,
            'method' => basename($this->method),
            'rpc_method' => $this->method,
            'endpoint' => $this->endpoint === '' ? null : (preg_match('/^[a-zA-Z0-9_.:\[\]\/\-]{1,255}$/D', $this->endpoint) ? $this->endpoint : '[REDACTED]'),
            'timeout_ms' => isset($this->options['timeout']) ? $this->options['timeout'] / 1000 : null,
            'command_ids' => $this->commandIds($data),
            'layer' => 'transport',
        ];
        return $data;
    }

    public function sdkException(mixed $request, Throwable $error, string $stage): void
    {
        try {
            $data = $this->prepare($request);
            $this->context['layer'] = 'sdk';
            $this->context += $this->payload(array_intersect_key($this->options,
                array_flip(['sdk_call_id', 'sdk_duration_ms', 'sdk_context'])));
            $this->write('error', 'exception', [
                'stage' => $stage, 'exception' => get_class($error),
                'exception_code' => (string) $error->getCode(),
                'message' => $this->safeString($error->getMessage()),
                'cause_exception' => $error->getPrevious() === null ? null : get_class($error->getPrevious()),
                'cause_message' => $error->getPrevious() === null ? null : $this->safeString($error->getPrevious()->getMessage()),
                'request' => TicketPayload::response($data),
            ]);
        } catch (Throwable) {
        }
    }

    public function response(mixed $response, mixed $status): void
    {
        if ($this->finished) return;
        $this->finished = true;
        try {
            $data = $this->payload($response);
            $code = is_object($status) ? ($status->code ?? null) : ($status['code'] ?? null);
            $details = is_object($status) ? ($status->details ?? '') : ($status['details'] ?? '');
            $this->sdkScope?->response($code === null ? null : (int) $code, (string) $details);
            $reject = is_array($data) ? (int) ($data['reject_code'] ?? 0) : null;
            $batchFailures = [];
            $results = is_array($data) && is_array($data['results'] ?? null) ? $data['results'] : [];
            foreach ($results as $index => $item) {
                if (is_array($item) && (int) ($item['reject_code'] ?? 0) !== 0) {
                    $batchFailures[] = ['index' => $index, 'reject_code' => (int) $item['reject_code']];
                }
            }
            $result = $code === null || (int) $code !== 0 ? 'transport_error'
                : ($response === null ? 'unconfirmed'
                    : ($reject !== null && $reject !== 0 ? 'business_rejected'
                        : ($batchFailures ? 'batch_rejected' : 'rpc_ok')));
            $this->write($result === 'rpc_ok' ? 'info' : 'warning', 'response', [
                'status_code' => $code === null ? null : (int) $code,
                'status_details' => $this->safeString((string) $details),
                'reject_code' => $reject,
                'batch_failures' => $batchFailures,
                'result' => $result,
                'response' => TicketPayload::response($data),
            ]);
            if ($result === 'transport_error') {
                $this->write('error', 'exception', [
                    'stage' => 'wait', 'exception' => null,
                    'status_code' => $code === null ? null : (int) $code,
                    'message' => $this->safeString((string) $details),
                ]);
            }
        } catch (Throwable) {
            // Do not turn a successful RPC into a retryable error when logging fails.
        }
    }

    public function exception(Throwable $error, string $stage): void
    {
        if ($this->finished) return;
        $this->finished = true;
        try {
            $this->sdkScope?->exception($error);
            $this->write('error', 'exception', [
                'stage' => $stage,
                'exception' => get_class($error),
                'exception_code' => (string) $error->getCode(),
                'message' => $this->safeString($error->getMessage()),
            ]);
        } catch (Throwable) {}
    }

    private function write(string $level, string $event, array $data): void
    {
        try {
            ($this->writer)($level, 'TK RPC '.basename($this->method).' '.$event, $this->context + [
                'event' => $event,
                'duration_ms' => $this->started > 0 ? round((hrtime(true) - $this->started) / 1000000, 3) : null,
            ] + $data);
        } catch (Throwable) {}
    }

    private function payload(mixed $value): mixed
    {
        try { return $this->redactStrings(TicketPayload::normalize($value)); }
        catch (Throwable) { return ['unavailable' => true, 'type' => get_debug_type($value)]; }
    }

    private function redactStrings(mixed $value): mixed
    {
        if (is_array($value)) return array_map($this->redactStrings(...), $value);
        return is_string($value) ? $this->safeString($value) : $value;
    }

    private function safeString(string $value): string
    {
        $value = (string) TicketPayload::normalize($value);
        return preg_replace('/\b(password|passwd|passphrase|secret|token|api[_-]?key|private[_-]?key|authorization|cookie)\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s;,]+)/i', '$1=[REDACTED]', $value);
    }

    private function commandIds(mixed $value): array
    {
        $ids = [];
        if (!is_array($value)) return $ids;
        foreach ($value as $key => $item) {
            if ($key === 'command_id' && is_scalar($item)) $ids[] = (string) $item;
            elseif (is_array($item)) $ids = array_merge($ids, $this->commandIds($item));
        }
        return array_values(array_unique($ids));
    }
}
