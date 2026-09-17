<?php

declare(strict_types=1);

namespace SixMm\Shared\TranslationConfig;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Throwable;

final class TranslationConfigService
{
    public function __construct(
        private ConnectionInterface $connection,
        private TranslationConfigCipher $cipher,
        private TranslationConfigVerifier $verifier,
        private string $table = 'agent_translation_config'
    ) {
    }

    /** @return array<string, mixed> */
    public function get(int $agentId): array
    {
        $row = $this->connection->table($this->table)->where('agent_id', $agentId)->first();

        return $row === null ? $this->defaults() : $this->serialize($row);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function save(int $agentId, int $actorId, array $input): array
    {
        $existing = $this->connection->table($this->table)->where('agent_id', $agentId)->first();
        $version = (string) ($input['api_version'] ?? 'v2');
        $concurrency = filter_var($input['concurrency'] ?? null, FILTER_VALIDATE_INT);
        $enabled = filter_var($input['is_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $apiKey = trim((string) ($input['api_key'] ?? ''));

        if ($agentId <= 0 || $actorId <= 0 || $version !== 'v2'
            || $concurrency === false || $concurrency < 1 || $concurrency > 10
            || $enabled === null) {
            throw TranslationConfigException::because(TranslationConfigException::INVALID_PARAMETERS);
        }
        if ($existing === null && $apiKey === '') {
            throw TranslationConfigException::because(TranslationConfigException::API_KEY_REQUIRED);
        }

        $now = $this->timestamp();
        $values = [
            'provider' => 'google',
            'api_version' => $version,
            'concurrency' => $concurrency,
            'is_enabled' => $enabled,
            'updated_by' => $actorId,
            'updated_at' => $now,
        ];

        if ($apiKey !== '') {
            $values += [
                'api_key_ciphertext' => $this->cipher->encrypt($apiKey),
                'api_key_mask' => $this->mask($apiKey),
                'verify_status' => 'pending',
                'verified_at' => null,
                'last_error' => null,
            ];
        }

        if ($existing === null) {
            $this->connection->table($this->table)->insert($values + [
                'agent_id' => $agentId,
                'created_by' => $actorId,
                'created_at' => $now,
            ]);
        } else {
            $this->connection->table($this->table)->where('agent_id', $agentId)->update($values);
        }

        return $this->get($agentId);
    }

    /** @return array{valid: bool, verify_status: string, verified_at: ?string, last_error: ?string} */
    public function test(int $agentId, ?string $candidateApiKey = null): array
    {
        $candidateApiKey = trim((string) $candidateApiKey);
        $usesSavedKey = $candidateApiKey === '';
        $row = null;

        if ($usesSavedKey) {
            $row = $this->connection->table($this->table)->where('agent_id', $agentId)->first();
            if ($row === null) {
                throw TranslationConfigException::because(TranslationConfigException::API_KEY_REQUIRED);
            }
            try {
                $candidateApiKey = $this->cipher->decrypt((string) $row->api_key_ciphertext);
            } catch (Throwable) {
                throw TranslationConfigException::because(TranslationConfigException::DECRYPT_FAILED);
            }
        }

        try {
            $verification = $this->verifier->verify($candidateApiKey);
            $valid = $verification->isValid();
            $error = $valid ? null : $this->safeError((string) $verification->error());
        } catch (Throwable $exception) {
            $valid = false;
            $error = $this->safeError($exception->getMessage());
        }

        $verifiedAt = $valid ? $this->timestamp() : null;
        if ($usesSavedKey && $row !== null) {
            $this->connection->table($this->table)->where('agent_id', $agentId)->update([
                'verify_status' => $valid ? 'valid' : 'invalid',
                'verified_at' => $verifiedAt,
                'last_error' => $error,
                'updated_at' => $this->timestamp(),
            ]);
        }

        return [
            'valid' => $valid,
            'verify_status' => $valid ? 'valid' : 'invalid',
            'verified_at' => $verifiedAt,
            'last_error' => $error,
        ];
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'provider' => 'google',
            'api_version' => 'v2',
            'api_key_mask' => null,
            'api_key_set' => false,
            'concurrency' => 3,
            'is_enabled' => true,
            'verify_status' => 'pending',
            'verified_at' => null,
            'last_error' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(object $row): array
    {
        return [
            'provider' => (string) $row->provider,
            'api_version' => (string) $row->api_version,
            'api_key_mask' => $row->api_key_mask !== null ? (string) $row->api_key_mask : null,
            'api_key_set' => (string) $row->api_key_ciphertext !== '',
            'concurrency' => (int) $row->concurrency,
            'is_enabled' => (bool) $row->is_enabled,
            'verify_status' => (string) $row->verify_status,
            'verified_at' => $row->verified_at !== null ? (string) $row->verified_at : null,
            'last_error' => $row->last_error !== null ? (string) $row->last_error : null,
        ];
    }

    private function mask(string $apiKey): string
    {
        if (strlen($apiKey) <= 8) {
            return str_repeat('*', strlen($apiKey));
        }

        return substr($apiKey, 0, 4) . '****' . substr($apiKey, -4);
    }

    private function safeError(string $error): string
    {
        $error = trim($error);
        $truncated = grapheme_substr($error, 0, 1000);

        return $truncated === false ? substr($error, 0, 1000) : $truncated;
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable())->format(DATE_ATOM);
    }
}
