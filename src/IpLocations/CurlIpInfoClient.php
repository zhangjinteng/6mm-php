<?php

declare(strict_types=1);

namespace SixMm\Shared\IpLocations;

final class CurlIpInfoClient implements IpInfoClient
{
    public function lookupMany(array $ips, IpInfoConfig $config): array
    {
        $results = array_fill_keys($ips, null);
        if ($ips === []) {
            return $results;
        }

        $multiHandle = curl_multi_init();
        $handles = [];

        try {
            foreach ($ips as $ip) {
                $handle = curl_init($config->baseUrl . '/' . rawurlencode($ip) . '/json');
                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Accept: application/json',
                        'Authorization: Bearer ' . $config->token,
                    ],
                    CURLOPT_CONNECTTIMEOUT => $config->timeout,
                    CURLOPT_TIMEOUT => $config->timeout,
                    CURLOPT_FOLLOWLOCATION => false,
                ]);
                curl_multi_add_handle($multiHandle, $handle);
                $handles[$ip] = $handle;
            }

            do {
                $status = curl_multi_exec($multiHandle, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            while ($running && $status === CURLM_OK) {
                if (curl_multi_select($multiHandle, 0.25) === -1) {
                    usleep(10000);
                }

                do {
                    $status = curl_multi_exec($multiHandle, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);
            }

            foreach ($handles as $ip => $handle) {
                $body = curl_multi_getcontent($handle);
                $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                if ($statusCode < 200 || $statusCode >= 300 || !is_string($body)) {
                    continue;
                }

                $payload = json_decode($body, true);
                if (is_array($payload)) {
                    $results[$ip] = $payload;
                }
            }
        } catch (\Throwable) {
            return $results;
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multiHandle, $handle);
                curl_close($handle);
            }
            curl_multi_close($multiHandle);
        }

        return $results;
    }
}
