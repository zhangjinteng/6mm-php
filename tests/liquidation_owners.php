<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use SixMm\Shared\Liquidations\LiquidationTradeListQuery;
use SixMm\Shared\Liquidations\LiquidationTradeListQueryService;
use SixMm\Shared\Liquidations\LiquidationTradeQueryExecutor;
use SixMm\Shared\Liquidations\LiquidationUserContextService;
use SixMm\Shared\TradeFills\TradeFillOwnerScope;

require dirname(__DIR__).'/vendor/autoload.php';
$checks=0;
function liqCheck(bool $condition,string $message): void { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }
$executor=new class implements LiquidationTradeQueryExecutor {
    public array $sql=[];
    public function select(string $sql): array { $this->sql[]=$sql; return str_contains($sql,'count() AS aggregate') ? [['aggregate'=>1]] : [['order_id'=>'12']]; }
};
$service=new LiquidationTradeListQueryService($executor);
foreach ([new TradeFillOwnerScope([[0,0]]),new TradeFillOwnerScope([[100,0]]),new TradeFillOwnerScope([[100,10]]),
    TradeFillOwnerScope::all(),TradeFillOwnerScope::category('merchant'),TradeFillOwnerScope::category('white_label')] as $scope) {
    $result=$service->searchForOwner(new LiquidationTradeListQuery(77,2,15),$scope)->toArray();
    liqCheck($result['count']===1,'Count retained');
    foreach (array_slice($executor->sql,-2) as $sql) {
        liqCheck(str_contains($sql,$scope->predicate('f')),'Count and drill-down use the same CK event owner');
        liqCheck(str_contains($sql,"upperUTF8(f.place_type) = 'LIQUIDATION'"),'Do not include regular trades');
        liqCheck(str_contains($sql,'f.position_id = 77'),'Position is narrowed before aggregation');
        liqCheck(!str_contains($sql,'o.agent_id IN'),'No merchant sentinel and no directory-agent list');
    }
    liqCheck(str_contains(end($executor->sql),'LIMIT 15 OFFSET 15'),'Pagination preserved');
}
$before=count($executor->sql);
$service->searchForOwner(new LiquidationTradeListQuery(77),new TradeFillOwnerScope([]));
$service->searchForOwner(new LiquidationTradeListQuery(0),new TradeFillOwnerScope([[0,0]]));
liqCheck(count($executor->sql)===$before,'Invalid identity or position skips CK');
$capsule=new Capsule(); $capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']); $capsule->setAsGlobal();
$db=$capsule->getConnection();
$db->statement('CREATE TABLE users (user_id integer, public_user_id integer, username text, nick_name text, user_type integer, deleted_at text)');
$db->statement('CREATE TABLE agent_user_bindings (platform_user_id integer, agent_id integer, agent_user_id text, bind_status text)');
$db->table('users')->insert(['user_id'=>1,'public_user_id'=>9001,'username'=>'Moved','nick_name'=>'Moved user','user_type'=>2,'deleted_at'=>'2026-11-01']);
$db->table('agent_user_bindings')->insert(['platform_user_id'=>1,'agent_id'=>10,'agent_user_id'=>'old-owner-uid','bind_status'=>'BOUND']);
$context=new LiquidationUserContextService($db);
liqCheck($context->matchingHistoricalUserIds('old-owner-uid')===[1],'Historical binding remains searchable for a deleted/moved profile');
$rows=$context->hydrateHistoricalRows([
    ['user_id'=>1,'home_site_id'=>100,'agent_id'=>10,'user_type'=>1],
    ['user_id'=>999,'home_site_id'=>100,'agent_id'=>0,'user_type'=>1],
]);
liqCheck(count($rows)===2,'Page rows survive missing/deleted users');
liqCheck($rows[0]['agent_id']===10 && $rows[0]['home_site_id']===100,'Historical owner unchanged');
liqCheck($rows[0]['user_type']===1,'Historical user type wins');
liqCheck($rows[0]['agent_user_id']==='old-owner-uid','Historical merchant binding used');
liqCheck($rows[0]['public_user_id']===9001,'Public identity retained');
echo "Liquidation owners: {$checks} checks passed\n";
