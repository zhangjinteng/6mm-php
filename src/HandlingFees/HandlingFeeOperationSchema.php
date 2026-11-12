<?php
declare(strict_types=1);

namespace SixMm\Shared\HandlingFees;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

final class HandlingFeeOperationSchema
{
    public const TABLE = 'handling_fee_config_operations';

    public static function create(ConnectionInterface $db): void
    {
        $schema = $db->getSchemaBuilder();
        if (!$schema->hasColumn('handling_fee_level_config', 'tk_version')) {
            $schema->table('handling_fee_level_config', static fn (Blueprint $t) => $t->bigInteger('tk_version')->nullable());
        }
        if ($schema->hasTable(self::TABLE)) {
            if (!$schema->hasColumn(self::TABLE, 'vip_recalculated_at')) {
                $schema->table(self::TABLE, static fn (Blueprint $t) => $t->timestamp('vip_recalculated_at')->nullable());
            }
            return;
        }
        $schema->create(self::TABLE, static function (Blueprint $t): void {
            $t->string('id', 32)->primary();
            $t->bigInteger('home_site_id');
            $t->bigInteger('agent_id');
            $t->string('request_id', 64);
            $t->string('action', 16);
            $t->integer('level');
            $t->string('intent_hash', 64);
            $t->string('method', 64);
            $t->json('payload');
            $t->json('values');
            $t->json('before')->nullable();
            $t->json('result')->nullable();
            $t->json('response')->nullable();
            $t->string('status', 16);
            $t->text('error')->nullable();
            $t->string('operator', 128);
            $t->timestamp('vip_recalculated_at')->nullable();
            $t->timestamps();
            $t->unique(['home_site_id', 'agent_id', 'request_id'], 'handling_fee_operation_request_uq');
            $t->index(['home_site_id', 'agent_id', 'status'], 'handling_fee_operation_scope_idx');
        });
    }
}
