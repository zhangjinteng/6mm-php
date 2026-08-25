<?php

declare(strict_types=1);

namespace SixMm\Shared\Prediction;

use SixMm\Prediction\ClientConfiguration;
use SixMm\Prediction\PlatformTemplates\PlatformTemplateClient;

final class PredictionPlatformTemplateService
{
    private readonly PlatformTemplateClient $client;

    /**
     * @param callable(string, object, string, string): array{0: mixed, 1: mixed}|null $rpcInvoker
     * @param callable(): string|null $traceIdGenerator
     */
    public function __construct(
        ?callable $rpcInvoker = null,
        int $timeoutMicroseconds = 5000000,
        string $target = '127.0.0.1:18081',
        string $token = '',
        bool $tls = false,
        ?callable $traceIdGenerator = null
    ) {
        $this->client = new PlatformTemplateClient(
            new ClientConfiguration($target, $token, $timeoutMicroseconds, $tls),
            $rpcInvoker,
            $traceIdGenerator
        );
    }

    /** @return array{draft: array<string, mixed>|null, current: array<string, mixed>} */
    public function getTemplate(string $operatorId, int $version = 0): array
    {
        return $this->client->getTemplate($operatorId, $version);
    }

    /** @return array{draft: array<string, mixed>, receipt: array<string, mixed>|null} */
    public function saveDraft(string $operatorId, array $data): array
    {
        return $this->client->saveDraft($operatorId, $data);
    }

    /**
     * @return array{
     *     version: array<string, mixed>,
     *     reconciliation_job: array<string, mixed>|null,
     *     receipt: array<string, mixed>|null
     * }
     */
    public function publish(string $operatorId, array $data): array
    {
        return $this->client->publish($operatorId, $data);
    }
}
