<?php

declare(strict_types=1);

namespace SixMm\Shared\Prediction;

use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

class GridOrderPresenter
{
    private const STATUSES=[1=>'PENDING_DEBIT',2=>'ACTIVE',3=>'HIT',4=>'MISS',5=>'SETTLED_WIN',6=>'SETTLED_LOSS',7=>'VOID',8=>'REJECTED'];

    public function row(array $record): array
    {
        $status=$this->smallInteger($record['status'],1,8);
        $row=['status'=>$status,'status_name'=>self::STATUSES[$status],'result_code'=>$this->smallInteger($record['result_code'],1,2147483647)];
        foreach (['order_id','user_id','order_version','time_start_ms','time_end_ms'] as $key) { $row[$key]=$this->integer($record[$key],true); }
        foreach (['home_site_id','entry_site_id','symbol','settlement_asset','cell_id','price_low','price_high'] as $key) { $row[$key]=$this->text($record[$key]); }
        $stake=$this->integer($record['stake_amount_units'],true);
        $gross=$this->integer($record['locked_gross_payout_units'],true);
        $row['stake_amount']=$this->decimal($stake,8);
        $row['locked_gross_payout']=$this->decimal($gross,8);
        $row['locked_net_profit']=$this->decimal($this->integer($record['locked_net_profit_units']),8);
        $row['locked_multiplier']=$this->decimal($this->integer($record['locked_multiplier_cent'],true),2);
        if ($status===5) { $row['realized_net_profit']=$this->decimal(bcsub($gross,$stake,0),8); }
        elseif ($status===6) { $row['realized_net_profit']=$this->decimal('-'.$stake,8); }
        elseif ($status===7) { $row['realized_net_profit']='0'; }
        $row['created_at_ms']=$this->timestamp($record['created_at']);
        $row['updated_at_ms']=$this->timestamp($record['updated_at']);
        $row['settled_at_ms']=$record['settled_at_ms']===null?null:$this->integer($record['settled_at_ms'],true);
        $row['order_no']=($record['order_no']??null)===null?null:$this->text($record['order_no']);
        $row['settlement_mark_price']=($record['settlement_mark_price']??null)===null?null:$this->text($record['settlement_mark_price']);
        $row['settlement_price_time_ms']=($record['settlement_price_time_ms']??null)===null?null:$this->integer($record['settlement_price_time_ms'],true);
        $row['funding_read_available']=($record['funding_read_available']??false)===true;
        foreach (['debit_ledger_id','settlement_ledger_id'] as $key) { $row[$key]=($record[$key]??null)===null?null:$this->text($record[$key]); }
        $fundingType=$record['settlement_funding_type']??null;
        if ($fundingType!==null && !in_array($fundingType,['PAYOUT','REFUND'],true)) { throw new UnexpectedValueException('Invalid Grid settlement funding type'); }
        $row['settlement_funding_type']=$fundingType;
        return $row;
    }

    public function detail(array $record): array
    {
        $row=$this->row($record);
        foreach (['client_order_id','public_quote_id','quote_id','locked_model_version','result_message'] as $key) { $row[$key]=$this->text($record[$key]); }
        foreach (['locked_layout_version','locked_config_version','bet_close_time_ms'] as $key) { $row[$key]=$this->integer($record[$key],true); }
        $row['locked_hit_probability']=$this->decimal($this->integer($record['locked_hit_probability_units']),8);
        if (($record['hit_time_ms']===null)!==($record['hit_mark_price']===null)) { throw new UnexpectedValueException('Invalid Grid hit evidence'); }
        $row['hit_time_ms']=$record['hit_time_ms']===null?null:$this->integer($record['hit_time_ms'],true);
        $row['hit_mark_price']=$record['hit_mark_price']===null?null:$this->text($record['hit_mark_price']);
        $row['payout_discarded_remainder']=$this->smallInteger($record['payout_discarded_remainder'],0,99);
        return $row;
    }

    private function integer($value,bool $positive=false): string
    {
        if (is_int($value)) { $value=(string)$value; }
        if (!is_string($value) || !preg_match($positive?'/^[1-9][0-9]*$/D':'/^(0|[1-9][0-9]*)$/D',$value) ||
            strlen($value)>19 || (strlen($value)===19 && strcmp($value,'9223372036854775807')>0)) {
            throw new UnexpectedValueException('Invalid Grid integer');
        }
        return $value;
    }

    private function smallInteger($value,int $minimum,int $maximum): int
    {
        $value=$this->integer($value);
        if (bccomp($value,(string)$minimum,0)<0 || bccomp($value,(string)$maximum,0)>0) { throw new UnexpectedValueException('Invalid Grid enum or counter'); }
        return (int)$value;
    }

    private function text($value): string
    {
        if (!is_string($value)) { throw new UnexpectedValueException('Invalid Grid text'); }
        return $value;
    }

    private function decimal(string $integer,int $scale): string
    {
        $negative=str_starts_with($integer,'-');
        $digits=$negative?substr($integer,1):$integer;
        $digits=str_pad($digits,$scale+1,'0',STR_PAD_LEFT);
        $whole=substr($digits,0,-$scale);$fraction=rtrim(substr($digits,-$scale),'0');
        $value=$whole.($fraction===''?'':'.'.$fraction);
        return ($negative && $value!=='0'?'-':'').$value;
    }

    private function timestamp($value): string
    {
        $time=new DateTimeImmutable($this->text($value),new DateTimeZone('UTC'));
        return bcadd(bcmul($time->format('U'),'1000',0),$time->format('v'),0);
    }
}
