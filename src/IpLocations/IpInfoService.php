<?php

declare(strict_types=1);

namespace SixMm\Shared\IpLocations;

final class IpInfoService
{
    private readonly IpInfoClient $client;

    public function __construct(
        private readonly IpLocationCache $cache,
        private readonly IpInfoConfig $config,
        ?IpInfoClient $client = null
    ) {
        $this->client = $client ?? new CurlIpInfoClient();
    }

    /** @return array<string, array<string, string>> */
    public function lookupMany(array $ips): array
    {
        $aliases = [];
        foreach ($ips as $rawIp) {
            $key = trim((string) $rawIp);
            if ($key !== '') {
                $aliases[$key] = $this->normalizeInput($key);
            }
        }

        $normalizedResults = $this->lookupNormalizedMany(array_values($aliases));
        $results = [];
        foreach ($aliases as $key => $ip) {
            if (isset($normalizedResults[$ip])) {
                $results[$key] = $normalizedResults[$ip];
            }
        }

        return $results;
    }

    /** @return array<string, array<string, string>> */
    private function lookupNormalizedMany(array $ips): array
    {
        $results = [];
        $cacheMisses = [];

        foreach ($this->uniqueIps($ips) as $ip) {
            if (!$this->isValidIp($ip)) {
                $results[$ip] = $this->fallback($ip, 'unavailable');
                continue;
            }

            if (!$this->isPublicIp($ip)) {
                $results[$ip] = $this->fallback($ip, 'private');
                continue;
            }

            $cached = $this->getCached($ip);
            if ($cached !== null) {
                $results[$ip] = $cached;
                continue;
            }

            $cacheMisses[] = $ip;
        }

        if ($cacheMisses === []) {
            return $results;
        }

        $responses = $this->config->token === ''
            ? []
            : $this->requestLocations($cacheMisses);

        foreach ($cacheMisses as $ip) {
            $payload = $responses[$ip] ?? null;
            $location = is_array($payload)
                ? $this->normalize($ip, $payload)
                : $this->fallback($ip, 'unavailable');

            $this->putCached(
                $ip,
                $location,
                $location['kind'] === 'resolved' ? $this->config->cacheTtl : $this->config->failureCacheTtl
            );
            $results[$ip] = $location;
        }

        return $results;
    }

    /** @return array<string, string>|null */
    public function lookup(?string $rawIp): ?array
    {
        $ip = $this->normalizeInput($rawIp);
        if ($ip === '') {
            return null;
        }

        return $this->lookupNormalizedMany([$ip])[$ip] ?? null;
    }

    private function requestLocations(array $ips): array
    {
        try {
            return $this->client->lookupMany($ips, $this->config);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, string> */
    private function normalize(string $ip, array $payload): array
    {
        $geo = isset($payload['geo']) && is_array($payload['geo']) ? $payload['geo'] : $payload;
        $country = trim((string) ($geo['country'] ?? $geo['country_name'] ?? ''));
        $countryCode = strtoupper(trim((string) ($geo['country_code'] ?? '')));

        if ($countryCode === '' && preg_match('/^[a-z]{2}$/i', $country)) {
            $countryCode = strtoupper($country);
            $country = '';
        }

        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            $countryCode = '';
        }

        if ($country === '' && $countryCode !== '' && class_exists(\Locale::class)) {
            $displayCountry = trim((string) \Locale::getDisplayRegion('-' . $countryCode, 'en'));
            if ($displayCountry !== '' && strtoupper($displayCountry) !== $countryCode) {
                $country = $displayCountry;
            }
        }

        $location = [
            'ip' => $ip,
            'kind' => 'resolved',
            'country_code' => $countryCode,
            'country' => $country,
            'region' => trim((string) ($geo['region'] ?? '')),
            'city' => trim((string) ($geo['city'] ?? '')),
            'timezone' => trim((string) ($geo['timezone'] ?? '')),
        ];

        if ($location['country_code'] === ''
            && $location['country'] === ''
            && $location['region'] === ''
            && $location['city'] === '') {
            return $this->fallback($ip, 'unavailable');
        }

        return $location;
    }

    /** @return array<string, string> */
    private function fallback(string $ip, string $kind): array
    {
        return [
            'ip' => $ip,
            'kind' => $kind,
            'country_code' => '',
            'country' => '',
            'region' => '',
            'city' => '',
            'timezone' => '',
        ];
    }

    /** @return list<string> */
    private function uniqueIps(array $ips): array
    {
        $unique = [];
        foreach ($ips as $rawIp) {
            $ip = $this->normalizeInput($rawIp);
            if ($ip !== '') {
                $unique[$ip] = true;
            }
        }

        return array_keys($unique);
    }

    private function normalizeInput(mixed $rawIp): string
    {
        return trim(explode(',', trim((string) $rawIp))[0]);
    }

    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function cacheKey(string $ip): string
    {
        return 'ipinfo:location:v1:' . hash('sha256', $ip);
    }

    private function getCached(string $ip): ?array
    {
        try {
            $cached = $this->cache->get($this->cacheKey($ip));
        } catch (\Throwable) {
            return null;
        }

        return is_array($cached)
            && ($cached['ip'] ?? null) === $ip
            && in_array($cached['kind'] ?? null, ['resolved', 'private', 'unavailable'], true)
                ? $cached
                : null;
    }

    private function putCached(string $ip, array $location, int $ttl): void
    {
        try {
            $this->cache->put($this->cacheKey($ip), $location, max($ttl, 1));
        } catch (\Throwable) {
            // Optional enrichment must never make a business query fail.
        }
    }
}
