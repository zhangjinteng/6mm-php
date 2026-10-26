<?php
namespace SixMm\Shared\Prediction\Configuration;
use Brick\Math\BigDecimal;
final class OwnerConfigValidator {
    public function validate(ConfigurationActor $c, array $data, array $i, string $method): void {
        if ($method === 'index') {
            $this->check(array_keys($data) === ['items'] && is_array($data['items']) && array_is_list($data['items'])); $seen=[];
            foreach ($data['items'] as $v) { $this->configuration($v,$c,$i); $this->check(!in_array($v['symbol'],$seen,true)); $seen[]=$v['symbol']; }
        } else {
            $this->keys($data,$method === 'save' ? ['configuration','receipt'] : ['configuration']);
            $this->configuration($data['configuration'],$c,$i);
            if ($method === 'save') $this->receipt($data,$c,$i);
        }
    }
    private function configuration($v,ConfigurationActor $c,array $i): void {
        $this->check(is_array($v));$this->keys($v,['owner_agent_id','game_type','symbol','public_version','owner_revision','rules','enabled','platform_enabled']);
        $this->check($v['owner_agent_id']===$c->agentId() && $v['game_type']==='GAME_TYPE_'.$i['game_type']);
        $this->check(is_string($v['symbol']) && preg_match('/^[A-Z0-9][A-Z0-9._-]{0,63}$/D',$v['symbol'])===1 && (!isset($i['symbol']) || $v['symbol']===$i['symbol']));
        $this->check(is_bool($v['enabled']) && is_bool($v['platform_enabled']));
        $this->integer($v['public_version'],true);$this->integer($v['owner_revision']);
        $this->check(is_array($v['rules']) && array_is_list($v['rules']));$rules=[];
        foreach($v['rules'] as $row) {
            $this->check(is_array($row));$this->keys($row,['rule','rule_epoch','platform_minimum_stake','platform_maximum_stake','custom','platform_enabled','owner_enabled','platform_target_payout_rate','platform_maximum_odds','platform_fixed_odds']);
            $this->integer($row['rule_epoch'],true);$this->check(is_bool($row['custom']) && is_array($row['rule']));
            $rule=$row['rule'];
            $this->check(is_bool($row['platform_enabled']) && is_bool($row['owner_enabled']) && $rule['enabled']===($v['enabled'] && $row['platform_enabled'] && $row['owner_enabled']));
            $up=$i['game_type']==='UP_DOWN';
            foreach($up?['target_payout_rate','maximum_odds']:['fixed_odds'] as $k) {
                $this->money($row['platform_'.$k]);$this->money($rule[$k]??null);
                $this->check(BigDecimal::of($rule[$k])->isLessThanOrEqualTo($row['platform_'.$k]));
            }
            foreach($up?['fixed_odds']:['target_payout_rate','maximum_odds'] as $k) $this->check($row['platform_'.$k]==='');
            if($up) $this->check(BigDecimal::of($rule['target_payout_rate'])->isLessThanOrEqualTo(1) && BigDecimal::of($rule['maximum_odds'])->isGreaterThanOrEqualTo(1));
            else $this->check(BigDecimal::of($rule['fixed_odds'])->isGreaterThan(1));
            $bounds=$i['game_type']==='UP_DOWN'?$this->activeTier($rule):$rule;
            $validationRule=$rule;if($i['game_type']==='UP_DOWN')$validationRule['stake_tiers']=[$bounds];$rules[]=$validationRule;
            foreach(['platform_minimum_stake','platform_maximum_stake'] as $k) $this->money($row[$k]);
            foreach(['minimum_stake','maximum_stake'] as $k) $this->money($bounds[$k]??null);
            $this->check(BigDecimal::of($row['platform_minimum_stake'])->isLessThanOrEqualTo($bounds['minimum_stake']) && BigDecimal::of($bounds['minimum_stake'])->isLessThanOrEqualTo($bounds['maximum_stake']) && BigDecimal::of($bounds['maximum_stake'])->isLessThanOrEqualTo($row['platform_maximum_stake']));
        }
        (new SiteConfigValidator)->validateRuleRows($rules,['game_type'=>$i['game_type'],'symbol'=>$v['symbol']]);
    }
    private function receipt(array $data,ConfigurationActor $c,array $i): void {
        $v=$data['configuration']; $this->check(BigDecimal::of($v['owner_revision'])->isEqualTo(BigDecimal::of($i['based_owner_revision'])->plus(1)));
        if(array_key_exists('enabled',$i)) $this->check($v['enabled']===$i['enabled']);
        foreach($i['changes'] as $change) {
            $found=false;
            foreach($v['rules'] as $row) {
                $r=$row['rule']; if($r['duration_seconds']!==$change['duration_seconds']) continue;
                $b=$i['game_type']==='UP_DOWN'?$this->activeTier($r):$r;
                $this->check(($b['stake_tier_id']??'')===$change['stake_tier_id'] && $row['rule_epoch']===$change['based_rule_epoch']);
                foreach(['minimum_stake','maximum_stake'] as $k) $this->check(BigDecimal::of($b[$k])->isEqualTo($change[$k]));
                if(array_key_exists('enabled',$change)) $this->check($row['owner_enabled']===$change['enabled']);
                foreach(['target_payout_rate','maximum_odds','fixed_odds'] as $k) if(array_key_exists($k,$change)) $this->check(BigDecimal::of($r[$k])->isEqualTo($change[$k]));
                $found=true;
            }
            $this->check($found);
        }
        $r=$data['receipt'];$this->check(is_array($r));
        $this->keys($r,['operation_id','operator_id','client_request_id','capability','action','target_type','target_id','reason','before_json','after_json','result','error_code','created_at_ms','completed_at_ms','source_service','trace_id']);
        foreach($r as $value) $this->check(is_string($value));
        foreach(['operation_id','trace_id'] as $k) $this->check($r[$k]!=='' && strlen($r[$k])<=256 && trim($r[$k])===$r[$k]);
        foreach(['created_at_ms','completed_at_ms'] as $k) $this->integer($r[$k],true);
        $target=hash('sha256',json_encode(['ownerAgentId'=>$c->agentId(),'gameType'=>$i['game_type'],'symbol'=>$i['symbol']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        $this->check($r['operator_id']===$c->operatorId() && $r['client_request_id']===$i['client_request_id'] && $r['source_service']==='agent_admin' && $r['reason']===$i['reason'] && $r['target_id']===$target && $r['target_type']==='owner_config' && $r['action']==='ADMIN_ACTION_OWNER_CONFIG_PUBLISH' && $r['capability']==='prediction.owner.config.publish' && $r['result']==='OPERATION_RESULT_SUCCEEDED' && $r['error_code']==='');
        foreach(['before_json','after_json'] as $k) $this->check(base64_decode($r[$k],true)!==false);
    }
    private function activeTier(array $rule): array {
        $tiers=$rule['stake_tiers']??null;$this->check(is_array($tiers) && array_is_list($tiers));$live=[];$seen=[];
        foreach($tiers as $tier) {
            $this->check(is_array($tier) && is_bool($tier['retired']??null) && is_bool($tier['enabled']??null));
            $id=$tier['stake_tier_id']??null;$this->check(is_string($id) && $id!=='' && !in_array($id,$seen,true));$seen[]=$id;
            if(!$tier['retired'])$live[]=$tier;
        }
        $this->check(count($live)===1);return $live[0];
    }
    private function keys(array $v,array $keys): void { $this->check(array_diff(array_keys($v),$keys)===[] && array_diff($keys,array_keys($v))===[]); }
    private function integer($v,bool $positive=false): void { $this->check(is_string($v) && ConfigurationInteger::canonical($v,$positive)===$v); }
    private function money($v): void { $this->check(is_string($v) && preg_match('/^(0|[1-9][0-9]{0,17})(\.[0-9]{1,18})?$/D',$v)===1 && BigDecimal::of($v)->isGreaterThan(0)); }
    private function check(bool $ok): void { if(!$ok) throw new \UnexpectedValueException('Invalid owner response'); }
}
