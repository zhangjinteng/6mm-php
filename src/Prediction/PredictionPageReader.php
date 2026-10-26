<?php
declare(strict_types=1);
namespace SixMm\Shared\Prediction;
final class PredictionPageReader {
    /** Fetch/enrichment receive only the host's already authorized query and transport. */
    public static function read(array $query,callable $fetch,?callable $enrich=null,?callable $assertScope=null): array {
        $page=$fetch($query);
        if (!is_array($page) || !isset($page['items']) || !is_array($page['items'])) {
            throw new ReadException('DEPENDENCY_UNAVAILABLE');
        }
        if ((array_key_exists('time_field',$query) || array_key_exists('user_filter',$query)) &&
            ($page['query_contract_version']??null)!==2) {
            throw new ReadException('QUERY_CONTRACT_MISMATCH');
        }
        if ($assertScope!==null) { $assertScope($page); }
        $result=$enrich===null ? $page : $enrich($page);
        if ($assertScope!==null) { $assertScope($result); }
        return $result;
    }
    public static function assertMerchant(array $page,string $agentId,bool $globalRounds=false): void {
        if ($globalRounds && array_intersect(['summary','configuration','configurations','receipt'],array_keys($page))!==[]) {
            throw new ReadException('DEPENDENCY_UNAVAILABLE');
        }
        $expected=$globalRounds?'0':$agentId;
        foreach ($page['items']??[] as $row) {
            if (!is_array($row) || ($row['agent_id']??null)!==$expected ||
                ($globalRounds && (!is_string($row['round_id']??null) || $row['round_id']===''))) {
                throw new ReadException('DEPENDENCY_UNAVAILABLE');
            }
        }
        self::assertNestedScope($page,$expected);
    }
    private static function assertNestedScope(array $data,string $expected): void {
        foreach ($data as $key=>$value) {
            if ($key==='agent_id' && $value!==$expected) { throw new ReadException('DEPENDENCY_UNAVAILABLE'); }
            if (is_array($value)) { self::assertNestedScope($value,$expected); }
        }
    }
}
