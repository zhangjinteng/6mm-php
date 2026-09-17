<?php

declare(strict_types=1);

namespace SixMm\Shared\TranslationConfig;

use RuntimeException;

final class CurlGoogleTranslationVerifier implements TranslationConfigVerifier
{
    private const VERIFY_URL = 'https://translation.googleapis.com/language/translate/v2';

    public function __construct(private int $timeout = 15)
    {
    }

    public function verify(string $apiKey): TranslationVerificationResult
    {
        $handle = curl_init(self::VERIFY_URL);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the Google Translation HTTP client.');
        }

        $body = json_encode([
            'q' => 'hello',
            'source' => 'en',
            'target' => 'zh-CN',
            'format' => 'text',
        ], JSON_THROW_ON_ERROR);

        try {
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'X-goog-api-key: ' . $apiKey,
                ],
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => false,
            ]);

            $responseBody = curl_exec($handle);
            if (!is_string($responseBody)) {
                throw new RuntimeException('Google Translation transport error: ' . curl_error($handle));
            }

            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $payload = json_decode($responseBody, true);
            $translated = is_array($payload)
                ? ($payload['data']['translations'][0]['translatedText'] ?? null)
                : null;

            if ($status >= 200 && $status < 300 && is_string($translated)) {
                return TranslationVerificationResult::valid();
            }

            $error = is_array($payload) ? ($payload['error']['message'] ?? null) : null;

            return TranslationVerificationResult::invalid(
                is_string($error) && trim($error) !== ''
                    ? $error
                    : 'Google Translation API verification failed'
            );
        } finally {
            curl_close($handle);
        }
    }
}
