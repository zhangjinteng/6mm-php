# TK RPC Logging

`RpcLogging/TkRpcLogger` observes actual unary TK requests through the existing
`RpcTickets/GrpcOptions` and `RecordedUnaryCall` adapter. Both administration
applications must continue to construct TK channels with
`GrpcOptions::forService('tk', $identity, $options)`.

## Events

- `TK RPC request`: emitted before the write-ticket check and native send.
- `TK RPC response`: emitted when native `wait()` returns, including business
  rejection or a nonzero transport status.
- `TK RPC exception`: emitted for thrown start/wait errors, a blocked pre-send
  write-ticket check, or a nonzero/absent gRPC status.

Each actual attempt has a separate `call_id`. SDK retries are not changed and
can be linked by their unchanged `command_ids`. This is an attempt log, not a
business completion journal: `result=rpc_ok` does not mean an asynchronous
operation or subsequent database transaction has completed.

Common fields: `schema_version`, `call_id`, `application`, `service`,
`service_identity`, `method`, `rpc_method`, `endpoint`, `timeout_ms`,
`command_ids`, `event`, `duration_ms`.

Response fields: `status_code`, `status_details`, `reject_code`,
`batch_failures`, `result`, `response`. Nonzero `results[].reject_code` is
summarized without changing any host's existing validation or error handling.
Exception fields: `stage`, `exception`, `exception_code`, `message` (or
`status_code` for a native transport failure without a thrown exception).

Payloads are structured arrays with snake_case keys. Protobuf command IDs and
decimal strings are preserved. Credential fields and bearer credentials are
redacted, authentication metadata is never logged, and payloads over 1 MiB
are replaced by byte-count/hash summaries. Serialization or logger failures
never alter RPC payloads, return values, exceptions, retry policy, timeouts,
or the existing Redis write-ticket guarantees.

## Host Configuration

Both applications configure the Laravel `tk_rpc` daily channel at
`storage/logs/tk-rpc-YYYY-MM-DD.log`, retaining 14 days with locking enabled.
`TK_RPC_LOG_LEVEL` defaults to `info`. Keep this level to retain request and
successful-response records. Deployments with cached Laravel configuration
must rebuild that cache as usual.

The component only logs calls that reach the native TK unary adapter. Local
SDK validation failures before native call creation are not outgoing RPCs.
Non-TK services, server streams, and application business-summary logs are
outside this component. No raw credentials, SDK internals, or call metadata
are added to the transport.

## Offline Verification

Run `php tests/tk_rpc_logging.php`, `php tests/rpc_ticket_native.php`, and
`php tests/rpc_write_tickets.php`. Native tests replace the actual call before
`start()`, so they cannot send a live RPC.
