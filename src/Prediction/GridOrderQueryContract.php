<?php

declare(strict_types=1);

namespace SixMm\Shared\Prediction;


trait GridOrderQueryContract
{
    private array $filters=[];
    public function authorize(): bool { return true; }
    public function rules(): array { return []; }
    public function filters(): array { return $this->filters; }

    protected function prepareForValidation(): void
    {
        $this->check($this->getContent()==='');
        // Global trim/null middleware must not turn a malformed original filter into a valid one.
        $raw=(string)$this->server('QUERY_STRING','');
        $seen=[];$statusEncoding=null;
        foreach (explode('&',$raw) as $part) {
            if ($part==='') { continue; }
            $name=urldecode(explode('=',$part,2)[0]);
            // A later array token must never hide an invalid scalar statuses value.
            $this->check($name!=='statuses');
            $this->check((bool)preg_match('/^[a-z_]+$|^statuses\[(?:[0-7])?\]$/D',$name));
            $this->check($name==='statuses[]' || !isset($seen[$name]));
            $seen[$name]=true;
            if (str_starts_with($name,'statuses[')) {
                $encoding=$name==='statuses[]'?'append':'indexed';
                $this->check($statusEncoding===null || $statusEncoding===$encoding);
                $statusEncoding=$encoding;
            }
        }
        parse_str($raw,$input);
        $this->filters=$this->contract($input);
        $this->query->replace($this->filters);
        app('request')->query->replace($this->filters);
    }

    protected function contract(array $input): array
    {
        $allowed=['created_from_ms','created_to_ms','order_id','order_no','user_id','public_user_id','user_keyword','client_order_id','home_site_id','symbol','status','statuses','page_no','page_size'];
        $this->check(array_diff(array_keys($input),$allowed)===[] && isset($input['created_from_ms'],$input['created_to_ms']));
        foreach (['created_from_ms','created_to_ms'] as $key) { $this->integer($input[$key],'253402300799999',false); }
        $from=(int)$input['created_from_ms'];$to=(int)$input['created_to_ms'];
        $this->check($from<$to && $to-$from<=2678400000);
        foreach (['order_id','user_id'] as $key) { if (array_key_exists($key,$input)) { $this->integer($input[$key],'9223372036854775807'); } }
        if (array_key_exists('public_user_id',$input)) {
            $uid=$input['public_user_id'];
            $this->check(is_string($uid) && $uid!=='' && trim($uid)===$uid && mb_check_encoding($uid,'UTF-8') &&
                mb_strlen($uid)<=64 && !preg_match('/[\x00-\x1f\x7f]/',$uid));
        }
        if (array_key_exists('user_keyword',$input)) {
            $keyword=$input['user_keyword'];
            $this->check(is_string($keyword) && mb_check_encoding($keyword,'UTF-8') && !preg_match('/[\x00-\x1f\x7f]/',$keyword));
            $keyword=trim($keyword);
            $this->check(mb_strlen($keyword,'UTF-8')<=128);
            if ($keyword==='') { unset($input['user_keyword']); }
            else { $input['user_keyword']=$keyword; }
        }
        if (array_key_exists('order_no',$input)) { $this->check(is_string($input['order_no']) && (bool)preg_match('/^GC[0-9]{12}[A-Z0-9]{6}$/D',$input['order_no'])); }
        if (array_key_exists('client_order_id',$input)) { $this->check(is_string($input['client_order_id']) && (bool)preg_match('/^[A-Za-z0-9_-]{8,64}$/D',$input['client_order_id'])); }
        if (array_key_exists('symbol',$input)) { $this->check(is_string($input['symbol']) && (bool)preg_match('/^[A-Z0-9]{2,32}$/D',$input['symbol'])); }
        if (array_key_exists('home_site_id',$input)) {
            $site=$input['home_site_id'];
            $this->check(is_string($site) && $site!=='' && strlen($site)<=64 && (bool)preg_match('//u',$site) && !preg_match('/^\s|\s$/u',$site) && strpos($site,"\0")===false);
        }
        $this->check(!(array_key_exists('status',$input) && array_key_exists('statuses',$input)));
        if (array_key_exists('status',$input)) { $this->integer($input['status'],'8'); }
        if (array_key_exists('statuses',$input)) {
            $statuses=$input['statuses'];
            $this->check(is_array($statuses) && count($statuses)>0 && count($statuses)<=8 && array_keys($statuses)===range(0,count($statuses)-1));
            foreach ($statuses as $status) { $this->integer($status,'8'); }
            $this->check(count(array_unique($statuses,SORT_STRING))===count($statuses));
        }
        foreach (['page_no'=>['1','1000'],'page_size'=>['20','100']] as $key=>[$default,$maximum]) {
            if (!array_key_exists($key,$input)) { $input[$key]=$default; }
            $this->integer($input[$key],$maximum);
            $input[$key]=(int)$input[$key];
        }
        return $input;
    }

    protected function integer($value,string $maximum,bool $positive=true): void
    {
        $this->check(is_string($value) && (bool)preg_match($positive?'/^[1-9][0-9]*$/D':'/^(0|[1-9][0-9]*)$/D',$value) &&
            (strlen($value)<strlen($maximum) || (strlen($value)===strlen($maximum) && strcmp($value,$maximum)<=0)));
    }

    protected function check(bool $valid): void
    {
        if (!$valid) { GridResponder::reject('INVALID_REQUEST',422); }
    }
}
