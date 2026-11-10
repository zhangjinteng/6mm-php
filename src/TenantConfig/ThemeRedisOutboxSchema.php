<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

/** One existing physical table; PostgreSQL upgrades use ALTER, never a replacement table. */
final class ThemeRedisOutboxSchema
{
    public static function create(ConnectionInterface $db): void
    {
        $prefix = $db->getDriverName() === 'pgsql' ? 'control_plane.' : '';
        $name = $prefix.ThemeRedisOutbox::TABLE;
        $schema = $db->getSchemaBuilder();
        if (self::exists($db, $name)) { self::upgrade($db); return; }
        $db->transaction(static function () use ($db, $schema, $name, $prefix): void {
            $schema->create($name, static function (Blueprint $t): void {
                $t->string('resource_type', 32)->comment('theme站点主题；agreement_template平台已发布协议模板');
                $t->bigInteger('resource_id')->comment('theme使用site_id；agreement_template使用wl_agreements.id');
                $t->string('locale', 32)->default('')->comment('主题为空字符串；协议使用实际语言');
                $t->bigInteger('tenant_id')->nullable()->comment('主题所属白标；平台模板为NULL');
                $t->bigInteger('site_id')->nullable()->comment('主题所属站点；平台模板为NULL');
                $t->bigInteger('config_version')->comment('投递快照版本，不等于模板的业务发布修订号');
                $t->bigInteger('schema_version')->comment('JSON结构版本');
                $t->text('payload')->comment('最新已提交的完整JSON快照；主题可能含公开客服Widget Key，禁止日志输出');
                $t->string('payload_sha256', 64)->comment('原始payload字节的SHA256');
                $t->bigInteger('previous_config_version')->nullable()->comment('上一投递快照版本；不代表保存了历史正文');
                $t->bigInteger('synced_version')->nullable()->comment('Redis已确认版本；NULL表示等待同步');
                $t->integer('attempts')->default(0)->comment('当前目标的同步失败次数');
                $t->timestampTz('next_attempt_at')->comment('最早重试时间');
                $t->string('last_error_code', 64)->nullable()->comment('脱敏错误代码，不保存异常正文或配置');
                $t->timestampTz('synced_at')->nullable()->comment('最近成功同步时间');
                $t->timestampTz('updated_at')->comment('最新一次入队时间');
                $t->primary(['resource_type','resource_id','locale'], 'wl_config_redis_resource_pk');
            });
            self::constraints($db, $name);
            self::indexes($db, $name);
            if ($prefix) $db->statement("COMMENT ON TABLE {$name} IS '通用配置Redis快照Outbox：每个资源保留最新快照和同步状态；主体信息随主题同步'");
        });
    }

    public static function upgrade(ConnectionInterface $db): void
    {
        $prefix = $db->getDriverName() === 'pgsql' ? 'control_plane.' : '';
        $name = $prefix.ThemeRedisOutbox::TABLE;
        $schema = $db->getSchemaBuilder();
        if (!self::exists($db, $name)) { self::create($db); return; }
        if (self::hasColumn($db, $name, 'resource_type')) {
            foreach (['resource_id','locale','tenant_id','site_id','schema_version','payload','payload_sha256','previous_config_version'] as $column) {
                if (!self::hasColumn($db, $name, $column)) throw new \RuntimeException('Incomplete generic configuration Outbox schema');
            }
            return;
        }
        if ($db->getDriverName() !== 'pgsql') {
            throw new \LogicException('Legacy Outbox in-place upgrade requires PostgreSQL; isolated SQLite fixtures must create the current schema');
        }
        $db->transaction(static function () use ($db, $name, $prefix): void {
            $db->statement("SET LOCAL lock_timeout = '5s'");
            $db->statement("SET LOCAL statement_timeout = '30s'");
            $db->statement("LOCK TABLE {$name} IN ACCESS EXCLUSIVE MODE");
            // Recheck after acquiring the lock: both hosts may have the upgrade migration.
            if (self::hasColumn($db, $name, 'resource_type')) return;
            $db->statement("ALTER TABLE {$name}
                ADD COLUMN resource_type varchar(32), ADD COLUMN resource_id bigint,
                ADD COLUMN locale varchar(32) DEFAULT '', ADD COLUMN tenant_id bigint,
                ADD COLUMN schema_version bigint, ADD COLUMN payload text,
                ADD COLUMN payload_sha256 varchar(64), ADD COLUMN previous_config_version bigint");
            $db->statement("UPDATE {$name} o SET resource_type='theme', resource_id=o.site_id, locale='',
                tenant_id=s.tenant_id, schema_version=t.schema_version, payload=t.payload,
                payload_sha256=t.payload_sha256, previous_config_version=t.previous_config_version,
                synced_version=NULL, synced_at=NULL, attempts=0, last_error_code=NULL, next_attempt_at=CURRENT_TIMESTAMP
                FROM {$prefix}wl_site_config_themes t JOIN {$prefix}wl_sites s ON s.site_id=t.site_id
                WHERE t.site_id=o.site_id AND t.config_version=o.config_version");
            foreach ($db->table($name)->orderBy('site_id')->cursor() as $row) {
                if ($row->payload === null || (int)$row->tenant_id < 1 || (int)$row->schema_version !== 1
                    || !hash_equals((string)$row->payload_sha256, hash('sha256', $row->payload))
                    || (json_decode($row->payload, false, 512, JSON_THROW_ON_ERROR)->siteId ?? null) !== (string)$row->site_id) {
                    throw new \RuntimeException('Legacy Outbox source snapshot missing or invalid; upgrade rolled back');
                }
            }
            $db->statement("ALTER TABLE {$name}
                DROP CONSTRAINT IF EXISTS wl_theme_redis_snapshot_fk,
                DROP CONSTRAINT wl_site_theme_redis_outbox_pkey,
                ALTER COLUMN site_id DROP NOT NULL,
                ALTER COLUMN resource_type SET NOT NULL, ALTER COLUMN resource_id SET NOT NULL,
                ALTER COLUMN locale SET NOT NULL, ALTER COLUMN schema_version SET NOT NULL,
                ALTER COLUMN payload SET NOT NULL, ALTER COLUMN payload_sha256 SET NOT NULL,
                ADD CONSTRAINT wl_config_redis_resource_pk PRIMARY KEY(resource_type, resource_id, locale)");
            $db->statement("DROP INDEX IF EXISTS {$prefix}wl_theme_redis_pending_idx");
            self::constraints($db, $name);
            self::indexes($db, $name);
            $db->statement("COMMENT ON TABLE {$name} IS '通用配置Redis快照Outbox：每个资源保留最新快照和同步状态；主体信息随主题同步'");
        });
    }

    private static function constraints(ConnectionInterface $db, string $name): void
    {
        if ($db->getDriverName() !== 'pgsql') return;
        $db->statement("ALTER TABLE {$name}
            ADD CONSTRAINT wl_config_redis_scope_ck CHECK (
                (resource_type='theme' AND site_id IS NOT NULL AND site_id=resource_id
                    AND tenant_id IS NOT NULL AND tenant_id>0 AND locale='')
                OR (resource_type='agreement_template' AND site_id IS NULL AND tenant_id IS NULL
                    AND locale ~ '^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$')),
            ADD CONSTRAINT wl_config_redis_version_ck CHECK (resource_id>0 AND config_version>0 AND schema_version>0 AND attempts>=0
                AND (synced_version IS NULL OR synced_version=config_version)
                AND (previous_config_version IS NULL OR (previous_config_version>0 AND previous_config_version<config_version))),
            ADD CONSTRAINT wl_config_redis_payload_ck CHECK (payload_sha256 ~ '^[a-f0-9]{64}$' AND octet_length(payload)<=1048576)");
    }

    private static function exists(ConnectionInterface $db, string $name): bool
    {
        return $db->getDriverName() === 'pgsql'
            ? $db->selectOne('SELECT to_regclass(?) AS name', [$name])->name !== null
            : $db->getSchemaBuilder()->hasTable($name);
    }

    private static function hasColumn(ConnectionInterface $db, string $name, string $column): bool
    {
        return $db->getDriverName() === 'pgsql'
            ? (bool)$db->selectOne('SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid=to_regclass(?) AND attname=? AND attnum>0 AND NOT attisdropped) AS present', [$name,$column])->present
            : $db->getSchemaBuilder()->hasColumn($name, $column);
    }

    private static function indexes(ConnectionInterface $db, string $name): void
    {
        $db->statement("CREATE INDEX wl_config_redis_pending_idx ON {$name} (next_attempt_at, resource_type, resource_id, locale) WHERE synced_version IS NULL");
    }
}
