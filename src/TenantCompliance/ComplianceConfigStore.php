<?php
declare(strict_types=1);

namespace SixMm\Shared\TenantCompliance;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

final class ComplianceConfigStore
{
    public const TYPES = ['terms'=>'用户协议','privacy'=>'隐私政策','risk'=>'风险披露','cookies'=>'Cookie 政策','aml'=>'KYC / AML 声明'];
    public const PROFILE_FIELDS = ['legal_entity_name','registration_number','registered_address','support_email','effective_date'];


    public function __construct(private ConnectionInterface $db, private bool $enabledSitesOnly = false, private ?string $schema = null)
    {
        if ($schema !== null && !preg_match('/^[a-z_][a-z0-9_]*$/D', $schema)) throw new \InvalidArgumentException('Invalid compliance schema');
    }
    public static function rules(string $action): array
    {
            $id='required|integer|min:1|max:9223372036854775807';
            $scope=['tenant_id'=>$id,'site_id'=>$id];
            return match ($action) {
                'listing'=>['keyword'=>'sometimes|nullable|string|max:128','page_no'=>'sometimes|integer|between:1,1000000','page_size'=>'sometimes|integer|between:1,40'],
                'detail'=>$scope,
                'save'=>$scope+['expected_revision'=>'required|string|size:64|regex:/^[a-f0-9]+$/D',
                    'profile'=>'required|array:legal_entity_name,registration_number,registered_address,support_email,effective_date',
                    'profile.legal_entity_name'=>'present|nullable|string|max:200','profile.registration_number'=>'present|nullable|string|max:100',
                    'profile.registered_address'=>'present|nullable|string|max:2000','profile.support_email'=>'present|nullable|email:rfc|max:254',
                    'profile.effective_date'=>'present|nullable|date_format:Y-m-d','agreements'=>'required|array|size:5',
                    'agreements.*'=>'required|array:agreement_type,mode,is_enabled,url',
                    'agreements.*.agreement_type'=>'required|string|in:terms,privacy,risk,cookies,aml',
                    'agreements.*.mode'=>'required|string|in:platform,url,content',
                    'agreements.*.url'=>'present|nullable|string|max:2048',
                    'agreements.*.is_enabled'=>['required',static function ($attribute,$value,$fail): void {if (!is_bool($value)) $fail('启停状态必须为布尔值');}]],
            };

    }
    private function id(mixed $value): void
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,18}$/D', (string)$value)
            || (strlen((string)$value)===19 && strcmp((string)$value,'9223372036854775807')>0)) $this->reject('site_id','白标或站点参数不正确');
    }
    public function sites(string $tenantId): array
    {
        $this->id($tenantId);
        return $this->query()->where('t.tenant_id',$tenantId)->orderBy('s.site_id')->get()
            ->map(fn ($site)=>['site_id'=>(string)$site->site_id,'site_name'=>$site->site_name])->all();
    }

    private function name(string $name): string { return ($this->schema !== null ? $this->schema.'.' : ($this->db->getDriverName()==='pgsql'?'control_plane.':'')).$name; }
    private function table(string $name): Builder { return $this->db->table($this->name($name)); }
    private function reject(string $key, string $message): never { throw ValidationException::withMessages([$key=>$message]); }
    private function query(): Builder
    {
        return $this->table('wl_sites as s')->join($this->name('wl_tenants').' as t','t.tenant_id','=','s.tenant_id')
            ->when($this->enabledSitesOnly,fn ($q)=>$q->where('s.status','enabled')->where('t.status','enabled'))
            ->select(['s.site_id','s.site_name','t.tenant_id','t.tenant_name']);
    }

    public function listing(array $input): array
    {
        $query=$this->query(); $keyword=trim($input['keyword'] ?? '');
        if ($keyword!=='') {
            $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($keyword)).'%';
            $query->where(function (Builder $q) use ($pattern,$keyword): void {
                $q->whereRaw("LOWER(t.tenant_name) LIKE ? ESCAPE '!'",[$pattern])->orWhereRaw("LOWER(s.site_name) LIKE ? ESCAPE '!'",[$pattern]);
                if (ctype_digit($keyword) && strlen($keyword)<=18) $q->orWhere('t.tenant_id',$keyword)->orWhere('s.site_id',$keyword);
            });
        }
        $count=(clone $query)->count();
        $sites=$query->orderBy('t.tenant_id')->orderBy('s.site_id')->forPage($input['page_no'] ?? 1,$input['page_size'] ?? 20)->get();
        return ['count'=>$count,'lists'=>$this->hydrate($sites,false)];
    }

    private function templates(): Collection
    {
        // Published metadata only. Draft content must never make a site look published.
        return $this->table('wl_agreements')->where('scope_type','platform')->where('locale','en')->whereIn('agreement_type',array_keys(self::TYPES))
            ->get(['id','agreement_type','code','name','is_enabled','published_version','published_sections','published_at'])->keyBy('agreement_type');
    }

    private function hydrate(Collection $sites, bool $detail): array
    {
        $ids=$sites->pluck('site_id');
        $profiles=$this->table('partner_account_profiles')->whereIn('home_site_id',$ids)->whereNull('agent_id')->get()->keyBy('home_site_id');
        $configs=$this->table('wl_agreements')->where('scope_type','site')->whereIn('site_id',$ids)->where('locale','en')->get()->groupBy('site_id');
        $templates=$this->templates();
        return $sites->map(fn ($site)=>$this->format($site,$profiles->get($site->site_id),$configs->get($site->site_id,collect()),$templates,$detail))->all();
    }

    private function format(object $site, ?object $profile, Collection $configs, Collection $templates, bool $detail): array
    {
        $fields=[];
        foreach (self::PROFILE_FIELDS as $field) $fields[$field]=trim((string)($profile?->{$field} ?? ''));
        $missing=array_keys(array_filter($fields,fn ($value)=>$value===''));
        $byType=$configs->keyBy('agreement_type'); $agreements=[]; $times=[$profile?->updated_at]; $versions=[];
        foreach (self::TYPES as $type=>$label) {
            $row=$byType->get($type); $template=$templates->get($type);
            $mode=(!$row || $row->source_mode==='platform')?'platform':($row->custom_type==='content'?'content':'url');
            $enabled=$row?(bool)$row->is_enabled:true;
            $published=json_decode((string)($mode==='platform'?$template?->published_sections:$row?->published_sections) ?: '[]',true,512,JSON_THROW_ON_ERROR);
            $version=(int)($mode==='platform'?$template?->published_version:$row?->published_version);
            $state=match (true) {
                !$enabled=>'disabled',
                $mode==='platform' && (!$template || !$template->is_enabled)=>'template_disabled',
                $version<1 || ($mode!=='url' && count($published)===0)=>'unpublished',
                $mode==='url' && !self::validUrl((string)$row?->published_url)=>'incomplete',
                $mode==='platform' && count($missing)>0=>'incomplete',
                default=>'ready',
            };
            $agreements[]=['agreement_type'=>$type,'label'=>$label,'mode'=>$mode,'is_enabled'=>$enabled,
                'url'=>(string)($row?->draft_url ?? ''),'state'=>$state,'template_code'=>$template?->code,
                'published_chapters'=>count($published),'published_version'=>(string)$version];
            $times[]=$row?->updated_at;
            if ($mode==='platform') $times[]=$template?->published_at;
            $versions[]=[$type,$row?(string)$row->id:null,$row?(string)$row->row_version:null,$row?->updated_at];
        }
        $times=array_values(array_filter($times));
        usort($times,fn ($a,$b)=>strtotime($b)<=>strtotime($a));
        $result=['tenant_id'=>(string)$site->tenant_id,'tenant_name'=>$site->tenant_name,'site_id'=>(string)$site->site_id,'site_name'=>$site->site_name,
            'legal_entity_name'=>$fields['legal_entity_name'],'agreements'=>$agreements,
            'platform_count'=>count(array_filter($agreements,fn ($a)=>$a['is_enabled'] && $a['mode']==='platform')),
            'updated_at'=>$times[0] ?? null];
        if ($detail) $result+=['profile'=>$fields,'revision'=>hash('sha256',json_encode([(string)$site->tenant_id,(string)$site->site_id,
            $profile?(string)$profile->id:null,$profile?->updated_at,$fields,$versions],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))];
        return $result;
    }

    public function detail(array $input): array
    {
        foreach (['tenant_id','site_id'] as $key) $this->id($input[$key] ?? null);
        $site=$this->query()->where('t.tenant_id',$input['tenant_id'])->where('s.site_id',$input['site_id'])->first();
        if (!$site) $this->reject('site_id','站点不存在或不属于该白标');
        return $this->hydrate(collect([$site]),true)[0];
    }

    public static function validUrl(string $url): bool
    {
        if ($url==='' || preg_match('/[\x00-\x20\x7f<>"\\\\]/',$url) || !filter_var($url,FILTER_VALIDATE_URL)) return false;
        $parts=parse_url($url);
        return is_array($parts) && strtolower($parts['scheme'] ?? '')==='https' && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
    }

    public function save(array $input, int $operatorId, string $actorType, callable $audit): array
    {
        if ($operatorId<1 || !in_array($actorType,['platform','tenant'],true)) throw new \InvalidArgumentException('Invalid compliance actor');
        $rules=self::rules('save');
        if (array_diff(array_keys($input),array_filter(array_keys($rules),fn ($key)=>!str_contains($key,'.')))) $this->reject('config','请求含不允许修改的字段');
        $input=\Illuminate\Support\Facades\Validator::make($input,$rules)->validate();
        foreach (['tenant_id','site_id'] as $key) $this->id($input[$key]);
        $profile=[];
        foreach (self::PROFILE_FIELDS as $field) $profile[$field]=trim((string)($input['profile'][$field] ?? ''));
        $items=[];
        foreach ($input['agreements'] as $i=>$agreement) {
            $type=$agreement['agreement_type'];
            if (isset($items[$type])) $this->reject("agreements.$i.agreement_type",'协议类型不能重复');
            $agreement['url']=trim($agreement['url'] ?? ''); $items[$type]=$agreement;
            if ($agreement['mode']==='url' && ($agreement['is_enabled'] || $agreement['url']!=='') && !self::validUrl($agreement['url'])) {
                $this->reject("agreements.$i.url",'请填写不含账号密码的有效 HTTPS 链接');
            }
        }
        if (count($items)!==5 || array_diff(array_keys(self::TYPES),array_keys($items))) $this->reject('agreements','请完整提交五类协议配置');
        if (array_filter($items,fn ($a)=>$a['is_enabled'] && $a['mode']==='platform')) {
            foreach (self::PROFILE_FIELDS as $field) if ($profile[$field]==='') $this->reject('profile.'.$field,'启用平台模板时请完善该主体信息');
        }
        return $this->db->transaction(function () use ($input,$operatorId,$actorType,$audit,$items,$profile): array {
            if ($this->db->getDriverName()==='pgsql') {
                $this->db->statement("SET LOCAL lock_timeout = '5s'"); $this->db->statement("SET LOCAL statement_timeout = '15s'");
            }
            // Parent locks serialize first inserts. Match the existing tenant/site/profile lock order.
            $tenant=$this->table('wl_tenants')->where('tenant_id',$input['tenant_id'])->lockForUpdate()->first();
            $site=$this->table('wl_sites')->where('site_id',$input['site_id'])->where('tenant_id',$input['tenant_id'])->lockForUpdate()->first();
            if (!$tenant || !$site) $this->reject('site_id','站点不存在或不属于该白标');
            $existingProfile=$this->table('partner_account_profiles')->where('home_site_id',$site->site_id)->whereNull('agent_id')->lockForUpdate()->first();
            $rows=$this->table('wl_agreements')->where('scope_type','site')->where('site_id',$site->site_id)->where('locale','en')->orderBy('id')->lockForUpdate()->get()->keyBy('agreement_type');
            $before=$this->detail($input);
            if (!hash_equals($before['revision'],$input['expected_revision'])) $this->reject('expected_revision','配置已被其他操作员修改，请重新打开后再保存');
            $templates=$this->templates(); $old=collect($before['agreements'])->keyBy('agreement_type'); $changes=[];
            foreach (self::TYPES as $type=>$label) {
                $item=$items[$type]; $row=$rows->get($type); $template=$templates->get($type);
                if (!$template) $this->reject('agreements','缺少平台协议模板，请先初始化模板');
                if ($item['mode']==='content' && (!$row || $row->source_mode!=='custom' || $row->custom_type!=='content')) $this->reject('agreements','此入口不能创建自定义正文');
                if ($item['mode']==='content' && $item['is_enabled'] && ((int)$row->published_version<1 || count(json_decode($row->published_sections,true))===0)) $this->reject('agreements','自定义正文尚未发布，不能启用');
                $was=$old[$type];
                // URL input is inactive outside URL mode; preserve any existing custom draft.
                $url=$item['mode']==='url'?$item['url']:$was['url'];
                if ($was['mode']!==$item['mode'] || $was['is_enabled']!==$item['is_enabled'] || $was['url']!==$url) {
                    $changes[$type]=['mode'=>$item['mode'],'is_enabled'=>$item['is_enabled'],'url'=>$url,'template_id'=>$template->id];
                }
            }
            $profileChanged=$before['profile']!==$profile;
            if (!$profileChanged && !$changes) {
                $this->syncTheme($input, $operatorId, $actorType);
                return ['changed'=>false,'detail'=>$before];
            }
            $now=\Carbon\Carbon::now();
            if ($existingProfile?->updated_at && $now->lessThanOrEqualTo(\Carbon\Carbon::parse($existingProfile->updated_at))) $now=\Carbon\Carbon::parse($existingProfile->updated_at)->addMicrosecond();
            $timestamp=$now->format('Y-m-d H:i:s.uP');
            if ($profileChanged) {
                $data=array_map(fn ($v)=>$v===''?null:$v,$profile)+['updated_by'=>$actorType==='platform'?$operatorId:null,'updated_at'=>$timestamp];
                if ($existingProfile) $this->table('partner_account_profiles')->where('id',$existingProfile->id)->update($data);
                else $this->table('partner_account_profiles')->insert($data+['home_site_id'=>$site->site_id,'agent_id'=>null,'owner_admin_id'=>$actorType==='platform'?$operatorId:null,'created_by'=>$actorType==='platform'?$operatorId:null,'created_at'=>$timestamp]);
            }
            foreach ($changes as $type=>$change) {
                $row=$rows->get($type);
                $data=['source_mode'=>$change['mode']==='platform'?'platform':'custom','is_enabled'=>$change['is_enabled'],
                    'template_id'=>$change['template_id'],'updated_by_type'=>$actorType,'updated_by_id'=>$operatorId,'updated_at'=>$timestamp,
                    'row_version'=>(int)($row?->row_version ?? 0)+1];
                if ($change['mode']==='url') {
                    $data+=['custom_type'=>'url','draft_url'=>$change['url'] ?: null];
                    if ($change['url']!=='' && ($row?->published_url!==$change['url'] || $row?->custom_type!=='url' || (int)$row?->published_version===0)) {
                        $data+=['published_url'=>$change['url'],'published_sections'=>'[]','published_version'=>(int)($row?->published_version ?? 0)+1,
                            'published_by_type'=>$actorType,'published_by_id'=>$operatorId,'published_at'=>$timestamp];
                    }
                }
                if ($row) $this->table('wl_agreements')->where('id',$row->id)->update($data);
                else $this->table('wl_agreements')->insert($data+['scope_type'=>'site','site_id'=>$site->site_id,'agreement_type'=>$type,'locale'=>'en',
                    'created_by_type'=>$actorType,'created_by_id'=>$operatorId,'created_at'=>$timestamp]);
            }
            $this->syncTheme($input, $operatorId, $actorType);
            $after=$this->detail($input);
            $summary=static fn (array $d): array=>['profile_sha256'=>hash('sha256',json_encode($d['profile'],JSON_THROW_ON_ERROR)),
                'agreements'=>array_map(static fn ($a)=>['type'=>$a['agreement_type'],'mode'=>$a['mode'],'enabled'=>$a['is_enabled'],'url_sha256'=>hash('sha256',$a['url'])],$d['agreements'])];
            $audit(['tenant_id'=>(string)$tenant->tenant_id,'site_id'=>(string)$site->site_id,
                'actor_type'=>$actorType,'before'=>$summary($before),'after'=>$summary($after)],$timestamp);
            return ['changed'=>true,'detail'=>$after];
        },3);
    }

    private function syncTheme(array $input, int $operator, string $actorType): void
    {
        (new \SixMm\Shared\TenantConfig\ThemeSnapshotWriter($this->table(...)))->sync(
            (int)$input['tenant_id'], (int)$input['site_id'], $operator,
            ($actorType === 'platform' ? 'platform-admin:' : 'white-label-admin:').$operator, '保存合规配置');
    }
}
