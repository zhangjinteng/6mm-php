<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

use DateTimeInterface;
use Illuminate\Database\Query\Builder;

final class ConfigSnapshotWriter
{
    /**
     * Append to the version chain. The caller holds the publication lock from
     * before reading $previous until its configuration and audit writes commit.
     *
     * @param callable(string): never $reject Invalid version handler for the calling store.
     */
    public static function append(
        Builder $snapshots,
        object $previous,
        object $payload,
        string $publishedBy,
        string $reason,
        DateTimeInterface|string $publishedAt,
        callable $reject,
    ): string {
        $db = $snapshots->getConnection();
        if ($db->transactionLevel() < 1) {
            throw new \LogicException('Snapshot publication requires a transaction');
        }
        $version = $db->getDriverName() === 'pgsql'
            ? (int) $db->selectOne("SELECT nextval('control_plane.wl_snapshot_version_seq'::regclass) AS version")->version
            : (int) $previous->snapshot_version + 1;
        if ($version <= (int) $previous->snapshot_version) {
            $reject('快照序列异常，请联系管理员');
        }
        $payload->snapshotVersion = $version;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $snapshots->insert([
            'snapshot_version' => $version,
            'schema_version' => $previous->schema_version,
            'payload' => $json,
            'payload_sha256' => hash('sha256', $json),
            'previous_snapshot_version' => $previous->snapshot_version,
            'rollback_of_snapshot_version' => null,
            'published_by' => $publishedBy,
            'publish_reason' => $reason,
            'published_at' => $publishedAt,
        ]);

        return (string) $version;
    }
}
