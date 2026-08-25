<?php

declare(strict_types=1);

namespace SixMm\Shared\Prediction;

use SixMm\Prediction\ClientConfiguration;
use SixMm\Prediction\PlatformTemplates\PlatformTemplateClient;

final class PredictionPlatformTemplateService
{
    private readonly PlatformTemplateClient $client;
    private readonly PredictionRuleCatalog $ruleCatalog;

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
        ?callable $traceIdGenerator = null,
        ?PredictionRuleCatalog $ruleCatalog = null
    ) {
        $this->client = new PlatformTemplateClient(
            new ClientConfiguration($target, $token, $timeoutMicroseconds, $tls),
            $rpcInvoker,
            $traceIdGenerator
        );
        $this->ruleCatalog = $ruleCatalog ?? new PredictionRuleCatalog();
    }

    /** @return array{draft: array<string, mixed>|null, current: array<string, mixed>} */
    public function getTemplate(string $operatorId, int $version = 0, bool $includeDraft = true): array
    {
        return $this->client->getTemplate($operatorId, $version, $includeDraft);
    }

    /**
     * @param iterable<mixed, string> $symbols
     * @return array{draft: array<string, mixed>|null, current: array<string, mixed>}
     */
    public function getFilteredTemplate(
        string $operatorId,
        iterable $symbols,
        PredictionGameType $gameType,
        PredictionRuleStatus $status = PredictionRuleStatus::ALL,
        int $version = 0,
        bool $includeDraft = false
    ): array {
        $symbolList = is_array($symbols) ? $symbols : iterator_to_array($symbols, false);
        $template = $this->getTemplate($operatorId, $version, $includeDraft);
        $template['current']['rules'] = $this->ruleCatalog->mergeAndFilter(
            (array) ($template['current']['rules'] ?? []),
            $symbolList,
            $gameType,
            $status
        );

        if ($template['draft'] !== null) {
            $template['draft']['rules'] = $this->ruleCatalog->mergeAndFilter(
                (array) ($template['draft']['rules'] ?? []),
                $symbolList,
                $gameType,
                $status
            );
        }

        return $template;
    }

    /** @return array{version: array<string, mixed>, receipt: array<string, mixed>|null} */
    public function saveSymbolConfig(string $operatorId, array $data): array
    {
        return $this->client->saveSymbolConfig($operatorId, $data);
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
