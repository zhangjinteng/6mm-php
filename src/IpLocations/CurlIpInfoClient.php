<?php

declare(strict_types=1);

namespace SixMm\Shared\IpLocations;

use JsonException;

final class CurlIpInfoClient implements IpInfoClient
{
    private const BATCH_SIZE = 1000;

    private readonly IpInfoHttpTransport $transport;

    public function __construct(?IpInfoHttpTransport $transport = null)
    {
        $this->transport = $transport ?? new CurlIpInfoTransport();
    }

    public function lookupMany(array $ips, IpInfoConfig $config): array
    {
        $ips = array_values(array_unique(array_map('strval', $ips)));
        if ($ips === []) {
            return [];
        }

        if (count($ips) === 1) {
            $ip = $ips[0];

            return [$ip => $this->requestSingle($ip, $config)];
        }

        $results = [];
        foreach (array_chunk($ips, self::BATCH_SIZE) as $chunk) {
            try {
                $results += $this->requestBatch($chunk, $config);
            } catch (IpInfoRequestException $exception) {
                if (!$this->shouldFallBackToSingleRequests($exception)) {
                    throw $exception;
                }

                $results += $this->requestSingles($chunk, $config);
            }
        }

        return $results;
    }

    /** @param list<string> $ips */
    private function requestBatch(array $ips, IpInfoConfig $config): array
    {
        try {
            $body = json_encode($ips, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new IpInfoRequestException(
                'Unable to encode the IPinfo Batch request.',
                previous: $exception
            );
        }

        $response = $this->transport->request(
            'POST',
            $config->baseUrl . '/batch',
            [
                'Accept: application/json',
                'Authorization: Bearer ' . $config->token,
                'Content-Type: application/json',
            ],
            $body,
            $config->timeout
        );

        $payload = $this->decodeResponse($response, 'Batch');
        $results = [];

        foreach ($ips as $ip) {
            $location = $payload[$ip] ?? null;
            $results[$ip] = is_array($location) && !isset($location['error'])
                ? $location
                : null;
        }

        return $results;
    }

    /** @param list<string> $ips */
    private function requestSingles(array $ips, IpInfoConfig $config): array
    {
        $results = [];
        foreach ($ips as $ip) {
            $results[$ip] = $this->requestSingle($ip, $config);
        }

        return $results;
    }

    private function requestSingle(string $ip, IpInfoConfig $config): ?array
    {
        $response = $this->transport->request(
            'GET',
            $config->baseUrl . '/' . rawurlencode($ip) . '/json',
            ['Accept: application/json', 'Authorization: Bearer ' . $config->token],
            null,
            $config->timeout
        );

        if ($response->statusCode === 404) {
            return null;
        }

        return $this->decodeResponse($response, 'Single-IP');
    }

    private function decodeResponse(IpInfoHttpResponse $response, string $requestType): array
    {
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new IpInfoRequestException(
                sprintf('IPinfo %s request returned HTTP %d.', $requestType, $response->statusCode),
                $response->statusCode
            );
        }

        try {
            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new IpInfoRequestException(
                sprintf('IPinfo %s request returned invalid JSON.', $requestType),
                $response->statusCode,
                previous: $exception
            );
        }

        if (!is_array($payload)) {
            throw new IpInfoRequestException(
                sprintf('IPinfo %s request returned an invalid payload.', $requestType),
                $response->statusCode
            );
        }

        return $payload;
    }

    private function shouldFallBackToSingleRequests(IpInfoRequestException $exception): bool
    {
        return in_array($exception->statusCode, [402, 403, 404, 405], true);
    }
}
