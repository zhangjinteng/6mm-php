<?php

declare(strict_types=1);

use SixMm\Shared\IpLocations\IpInfoClient;
use SixMm\Shared\IpLocations\IpInfoConfig;
use SixMm\Shared\IpLocations\IpInfoService;
use SixMm\Shared\IpLocations\IpLocationCache;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertIpLocationSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%s\nExpected: %s\nActual: %s",
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

final class MemoryIpLocationCache implements IpLocationCache
{
    public array $items = [];
    public array $ttls = [];

    public function get(string $key): mixed
    {
        return $this->items[$key] ?? null;
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->items[$key] = $value;
        $this->ttls[$key] = $ttlSeconds;
    }
}

final class FakeIpInfoClient implements IpInfoClient
{
    public array $calls = [];

    public function __construct(private readonly array $responses = []) {}

    public function lookupMany(array $ips, IpInfoConfig $config): array
    {
        $this->calls[] = [
            'ips' => $ips,
            'base_url' => $config->baseUrl,
            'timeout' => $config->timeout,
        ];

        $results = [];
        foreach ($ips as $ip) {
            $results[$ip] = $this->responses[$ip] ?? null;
        }

        return $results;
    }
}

$cache = new MemoryIpLocationCache();
$client = new FakeIpInfoClient([
    '8.8.8.8' => [
        'country' => 'US',
        'region' => 'California',
        'city' => 'Mountain View',
        'timezone' => 'America/Los_Angeles',
    ],
    '1.1.1.1' => [
        'geo' => [
            'country_code' => 'AU',
            'country' => 'Australia',
            'region' => 'Queensland',
            'city' => 'South Brisbane',
            'timezone' => 'Australia/Brisbane',
        ],
    ],
]);
$config = new IpInfoConfig('secret', 'https://ipinfo.example/', 3, 86400, 300);
$service = new IpInfoService($cache, $config, $client);

$locations = $service->lookupMany([
    ' 8.8.8.8 ',
    '8.8.8.8',
    '1.1.1.1',
    '10.0.0.1',
    'not-an-ip',
    '',
]);

assertIpLocationSame(['8.8.8.8', '1.1.1.1'], $client->calls[0]['ips'], 'Only unique public cache misses should be requested.');
assertIpLocationSame('https://ipinfo.example', $client->calls[0]['base_url'], 'Base URLs should be normalized.');
assertIpLocationSame('resolved', $locations['8.8.8.8']['kind'], 'A valid IPinfo response should resolve.');
assertIpLocationSame('US', $locations['8.8.8.8']['country_code'], 'Legacy IPinfo country codes should be normalized.');
assertIpLocationSame('United States', $locations['8.8.8.8']['country'], 'Legacy country codes should be expanded to a stable country name.');
assertIpLocationSame('Australia', $locations['1.1.1.1']['country'], 'Nested geo payloads should retain country names.');
assertIpLocationSame('private', $locations['10.0.0.1']['kind'], 'Private addresses should not call IPinfo.');
assertIpLocationSame('unavailable', $locations['not-an-ip']['kind'], 'Invalid non-empty values should use the unavailable state.');
assertIpLocationSame(86400, max($cache->ttls), 'Resolved results should use the success TTL.');

$service->lookupMany(['8.8.8.8']);
assertIpLocationSame(1, count($client->calls), 'Resolved results should be read from cache.');

$failureCache = new MemoryIpLocationCache();
$failureClient = new FakeIpInfoClient(['9.9.9.9' => null]);
$failureService = new IpInfoService($failureCache, $config, $failureClient);
$failure = $failureService->lookup('9.9.9.9');
assertIpLocationSame('unavailable', $failure['kind'], 'Request failures should degrade to unavailable.');
assertIpLocationSame(300, max($failureCache->ttls), 'Unavailable results should use the failure TTL.');
$failureService->lookup('9.9.9.9');
assertIpLocationSame(1, count($failureClient->calls), 'Failure results should be cached to avoid request storms.');

$disabledClient = new FakeIpInfoClient();
$disabledService = new IpInfoService(
    new MemoryIpLocationCache(),
    new IpInfoConfig('', 'https://ipinfo.io', 3, 86400, 300),
    $disabledClient
);
assertIpLocationSame('unavailable', $disabledService->lookup('8.8.4.4')['kind'], 'Missing tokens should degrade gracefully.');
assertIpLocationSame([], $disabledClient->calls, 'Missing tokens should prevent outbound requests.');

$forwarded = $service->lookup('8.8.8.8, 10.0.0.1');
assertIpLocationSame('8.8.8.8', $forwarded['ip'], 'Forwarded IP lists should use the first address.');
$forwardedBatch = $service->lookupMany(['8.8.8.8, 10.0.0.1']);
assertIpLocationSame(
    '8.8.8.8',
    $forwardedBatch['8.8.8.8, 10.0.0.1']['ip'],
    'Batch lookups should preserve the original value as the result key.'
);

fwrite(STDOUT, "6mm-php IP location tests passed.\n");
