<?php
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

function scopedFeeFixture(ConnectionInterface $db): void
{
    $db->getSchemaBuilder()->dropIfExists('handling_fee_level_config');
    $db->getSchemaBuilder()->create('handling_fee_level_config', static function (Blueprint $t): void {
        $t->id(); $t->bigInteger('home_site_id')->default(0); $t->bigInteger('agent_id')->default(0);
        $t->integer('level'); $t->string('level_name');
        $t->bigInteger('tk_version')->nullable();
        $t->decimal('volume_30d',20,2);
        foreach (['maker_fee_rate','taker_fee_rate'] as $field) $t->string($field);
        $t->timestamps(); $t->softDeletes();
    });
    $db->statement('CREATE UNIQUE INDEX handling_fee_active_scope_level_uq ON handling_fee_level_config(home_site_id,agent_id,level) WHERE deleted_at IS NULL');
    foreach ([0,1] as $level) $db->table('handling_fee_level_config')->insert([
        'level'=>$level,'level_name'=>'VIP'.$level,'volume_30d'=>(string)(($level+1)*1000),
        'maker_fee_rate'=>'0.0002','taker_fee_rate'=>'0.0005',
    ]);
}

function successfulFeeBatch(array $payload): array
{
    return ['totalCount'=>count($payload['items']),'successCount'=>count($payload['items']),
        'results'=>array_map(static fn ($i)=>['level'=>$i['level'],'homeSiteId'=>$i['home_site_id'],'agentId'=>$i['agent_id']],$payload['items'])];
}
