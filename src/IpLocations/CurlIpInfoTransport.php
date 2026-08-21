<?php

declare(strict_types=1);

namespace SixMm\Shared\IpLocations;

final class CurlIpInfoTransport implements IpInfoHttpTransport
{
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout
    ): IpInfoHttpResponse {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new IpInfoRequestException('Unable to initialize the IPinfo HTTP client.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        try {
            curl_setopt_array($handle, $options);
            $responseBody = curl_exec($handle);

            if (!is_string($responseBody)) {
                throw new IpInfoRequestException(
                    'IPinfo transport error: ' . curl_error($handle),
                    null,
                    curl_errno($handle)
                );
            }

            return new IpInfoHttpResponse(
                (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                $responseBody
            );
        } finally {
            curl_close($handle);
        }
    }
}
