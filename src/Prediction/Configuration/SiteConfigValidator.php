<?php
namespace SixMm\Shared\Prediction\Configuration;
use Brick\Math\BigDecimal;

/** Site envelopes have their own scope; legacy order/agent policies stay intact. */
final class SiteConfigValidator
{
    public function validate(ConfigurationActor $context,array $data,array $input,bool $save): void
    {
        $this->keys($data,$save ? ['stored','receipt'] : ['stored','effective','adjustments']);
        $stored=$data['stored']; $this->check(is_array($stored));
        $this->keys($stored,['site_id','game_type','symbol','version','based_on_public_version','enabled','overrides']);
        $this->key($stored,$input); $this->integer($stored['version'],true); $this->integer($stored['based_on_public_version'],true);
        $this->check(is_bool($stored['enabled'])); $this->rows($stored['overrides'],$input,false);
        if ($save) {
            $this->check($stored['based_on_public_version']===$input['based_on_public_version']);
            $this->check(BigDecimal::of($stored['version'])->isEqualTo(BigDecimal::of($input['based_on_site_version'])->plus(1)));
            $this->check($stored['enabled']===$input['enabled']);
            $receipt=$data['receipt']; $this->check(is_array($receipt));
            $this->keys($receipt,['operation_id','operator_id','client_request_id','capability','action','target_type','target_id','reason','before_json','after_json','result','error_code','created_at_ms','completed_at_ms','source_service','trace_id']);
            foreach (['operation_id','trace_id'] as $field) { $this->text($receipt[$field],128); }
            $this->integer($receipt['created_at_ms'],true); $this->integer($receipt['completed_at_ms'],true);
            $this->check($receipt['result']==='OPERATION_RESULT_SUCCEEDED' && $receipt['source_service']==='agent_admin' && $receipt['operator_id']===$context->operatorId() && $receipt['client_request_id']===$input['client_request_id']);
            $this->check($receipt['action']==='ADMIN_ACTION_SITE_CONFIG_PUBLISH' && $receipt['capability']==='prediction.site.config.publish' && $receipt['target_type']==='site_config' && $receipt['target_id']===self::targetID($input));
            $this->check($receipt['reason']===$input['reason'] && $receipt['error_code']==='');
            foreach (['before_json','after_json'] as $field) { $this->check(is_string($receipt[$field]) && base64_decode($receipt[$field],true)!==false); }
            // A replay deliberately keeps the original receipt trace and timestamps.
            return;
        }
        $effective=$data['effective']; $this->check(is_array($effective));
        $this->keys($effective,['site_id','game_type','symbol','public_version','site_version','based_on_public_version','enabled','rules']);
        $this->key($effective,$input);
        foreach (['public_version','site_version','based_on_public_version'] as $field) { $this->integer($effective[$field],true); }
        $this->check($effective['site_version']===$stored['version'] && $effective['based_on_public_version']===$stored['based_on_public_version'] && is_bool($effective['enabled']));
        // Public rollback is legal: do not compare public_version >= based_on_public_version.
        $this->rows($effective['rules'],$input,true);
        $this->check(is_array($data['adjustments']) && array_is_list($data['adjustments']));
        foreach ($data['adjustments'] as $row) {
            $this->check(is_array($row)); $this->keys($row,['game_type','symbol','duration_seconds','field','before_value','after_value','reason']);
            $this->key($row,$input,false); $this->integer($row['duration_seconds']);
            foreach (['field','before_value','after_value','reason'] as $field) { $this->check(is_string($row[$field])); }
        }
    }

    public function validateRuleRows(array $rows,array $input): void { $this->rows($rows,$input,true); }

    private function rows($rows,array $input,bool $effective): void
    {
        $highLow=$input['game_type']==='HIGH_LOW';
        $this->check(is_array($rows) && array_is_list($rows));
        if (!$highLow) { $this->check(count($rows)<=5); }
        if ($effective) { $this->check($highLow ? count($rows)>0 : count($rows)===5); }
        $seen=[];
        foreach ($rows as $row) {
            $this->check(is_array($row));
            $common=['game_type','symbol','duration_seconds','enabled','stake_tiers','bet_open_seconds','target_payout_rate','minimum_odds','maximum_odds','fixed_odds','minimum_stake','maximum_stake'];
            $allowed=$effective ? array_merge($common,['market_id','settlement_display_seconds','price_rule','validation_status']) : $common;
            if ($highLow && !$effective) { $allowed=['game_type','symbol','duration_seconds','enabled','stake_tiers','fixed_odds','minimum_stake','maximum_stake']; }
            $required=$effective ? $allowed : ['game_type','symbol','duration_seconds','stake_tiers'];
            $this->keys($row,$allowed,$required); $this->key($row,$input,false);
            $duration=$row['duration_seconds']; $this->integer($duration,true);
            $this->check(($highLow || in_array($duration,['35','40','45','60','90'],true)) && !in_array($duration,$seen,true)); $seen[]=$duration;
            if (array_key_exists('enabled',$row)) { $this->check(is_bool($row['enabled'])); }
            foreach (['bet_open_seconds','settlement_display_seconds'] as $field) { if (array_key_exists($field,$row)) { $this->integer($row[$field]); } }
            foreach (['target_payout_rate','minimum_odds','maximum_odds','fixed_odds','minimum_stake','maximum_stake'] as $field) {
                if (array_key_exists($field,$row)) { $this->decimal($row[$field],$effective); }
            }
            if ($effective) {
                $this->text($row['market_id'],128);
                $this->check(is_array($row['price_rule']));
                $timeFields=['max_age_milliseconds','late_arrival_grace_milliseconds'];
                $this->keys($row['price_rule'],array_merge($timeFields,['price_decimals']),$timeFields);
                foreach ($timeFields as $field) { $this->integer($row['price_rule'][$field]); }
                if (array_key_exists('price_decimals',$row['price_rule'])) {
                    $decimals=$row['price_rule']['price_decimals'];
                    $this->check(is_int($decimals) && $decimals>=0 && $decimals<=8);
                }
                $this->check(in_array($row['validation_status'],['CONFIG_VALIDATION_STATUS_VALID','CONFIG_VALIDATION_STATUS_ADJUSTED','CONFIG_VALIDATION_STATUS_DISABLED'],true));
            }
            $tiers=$row['stake_tiers']; $this->check(is_array($tiers) && array_is_list($tiers) && count($tiers)<=1);
            if ($highLow) {
                $this->check($tiers===[]);
                if ($effective) {
                    $this->check($row['bet_open_seconds']==='0' && $row['target_payout_rate']==='' && $row['minimum_odds']==='' && $row['maximum_odds']==='');
                }
                foreach (['fixed_odds','minimum_stake','maximum_stake'] as $field) {
                    if (array_key_exists($field,$row)) {
                        $this->decimal($row[$field]);
                        $this->check(BigDecimal::of($row[$field])->isGreaterThan($field==='fixed_odds' ? BigDecimal::one() : BigDecimal::zero()));
                    }
                }
                if (isset($row['minimum_stake'],$row['maximum_stake'])) { $this->check(BigDecimal::of($row['minimum_stake'])->isLessThanOrEqualTo($row['maximum_stake'])); }
            } elseif ($effective) { $this->check(count($tiers)===1); }
            foreach ($tiers as $tier) {
                $this->check(is_array($tier));
                $allowed=['stake_tier_id','enabled','minimum_stake','maximum_stake'];
                if ($effective) { $allowed=array_merge($allowed,['display_order','retired']); }
                $this->keys($tier,$allowed,$effective ? $allowed : ['stake_tier_id']); $this->text($tier['stake_tier_id'],64);
                foreach (['enabled','retired'] as $field) { if (array_key_exists($field,$tier)) { $this->check(is_bool($tier[$field])); } }
                foreach (['minimum_stake','maximum_stake'] as $field) { if (array_key_exists($field,$tier)) { $this->decimal($tier[$field]); } }
                if ($effective) { $this->check(is_int($tier['display_order']) && $tier['display_order']>=0); }
            }
        }
    }
    public static function targetID(array $input): string
    {
        $bytes='';
        foreach ([$input['site_id'],$input['game_type'],$input['symbol']] as $part) { $bytes.=pack('N2',0,strlen($part)).$part; }
        return 'site-v1:'.hash('sha256',$bytes);
    }
    private function key(array $value,array $input,bool $site=true): void
    {
        $this->check(in_array($input['game_type'],['UP_DOWN','HIGH_LOW'],true) && ($value['game_type']??null)==='GAME_TYPE_'.$input['game_type'] && ($value['symbol']??null)===$input['symbol']);
        if ($site) { $this->check(($value['site_id']??null)===$input['site_id']); }
    }
    private function keys(array $v,array $allowed,?array $required=null): void
    {
        $this->check(array_diff(array_keys($v),$allowed)===[] && array_diff($required??$allowed,array_keys($v))===[]);
    }
    private function integer($v,bool $positive=false): void { $this->check(is_string($v) && ConfigurationInteger::canonical($v,$positive)===$v); }
    private function text($v,int $max): void { $this->check(is_string($v) && $v!=='' && strlen($v)<=$max && trim($v)===$v && !preg_match('/[\x00-\x1f\x7f]/',$v)); }
    private function decimal($v,bool $empty=false): void { $this->check(is_string($v) && strlen($v)<=256 && (($empty && $v==='') || preg_match('/^(0|[1-9][0-9]*)(\.[0-9]+)?$/D',$v)===1)); }
    private function check(bool $ok): void { if (!$ok) { throw new \UnexpectedValueException('Invalid site response'); } }
}
