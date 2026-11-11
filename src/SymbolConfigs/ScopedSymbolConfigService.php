<?php

declare(strict_types=1);

namespace SixMm\Shared\SymbolConfigs;

use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/** Existing upstream schema only: NULL inherits; explicit zero/false never falls back. */
final class ScopedSymbolConfigService
{
    private const PREFERENCES = ['sort', 'is_visible', 'is_active', 'tags'];
    private ?array $columns = null;

    public function __construct(private ConnectionInterface $db) {}

    public function lock(): void
    {
        if ($this->db->getDriverName() === 'pgsql') $this->db->select('SELECT pg_advisory_xact_lock(640061110)');
    }

    public function query(int $siteId, int $agentId = 0): Builder
    {
        $this->validateScope($siteId, $agentId);
        return $this->db->table('symbol_config')->where('home_site_id', $siteId)->where('agent_id', $agentId);
    }

    /** No inherited preference, public parameter or tag relation is copied into a new row. */
    public function ensureScope(int $siteId, int $agentId = 0): void
    {
        $this->validateScope($siteId, $agentId);
        if ($siteId === 0) return;
        $this->db->transaction(function () use ($siteId, $agentId): void {
            $this->lock();
            if ($agentId > 0) $this->ensureScope($siteId);
            $emptyScope = !$this->query($siteId, $agentId)->exists();
            $this->ensureTags($siteId, $agentId);
            if ($emptyScope && $agentId > 0) $this->importLegacyTags($siteId, $agentId);
            $existing = $this->query($siteId, $agentId)->get()->keyBy('base_config_id');
            foreach ($this->query(0)->whereNull('deleted_at')->orderBy('id')->get(['id','symbol']) as $root) {
                if ($existing->has($root->id)) continue;
                // Do not silently rewrite an existing incompatible ownership/reference.
                $conflict = $this->query($siteId, $agentId)->where('symbol', $root->symbol)->first();
                if ($conflict) throw new DomainException('合约基础配置关联不一致，请核查后重试');
                $id = $this->db->table('symbol_config')->insertGetId([
                    'symbol'=>$root->symbol, 'home_site_id'=>$siteId, 'agent_id'=>$agentId,
                    'base_config_id'=>$root->id, 'sort'=>null, 'is_visible'=>null, 'is_active'=>null,
                    'revision'=>1, 'created_at'=>$this->now(), 'updated_at'=>$this->now(),
                ]);
                $row = $this->query($siteId, $agentId)->where('id',$id)->first();
                $this->importLegacyConfig($row, (int)$root->id);
            }
        });
    }

    /** Resolve values without persisting the result; public metadata comes only from base. */
    public function effectiveQuery(int $siteId, int $agentId = 0): Builder
    {
        $this->validateScope($siteId, $agentId);
        $this->columns ??= $this->db->getSchemaBuilder()->getColumnListing('symbol_config');
        $local = ['id','home_site_id','agent_id','base_config_id','revision',
            'sort','is_visible','is_active','created_at','updated_at','deleted_at'];
        $select = array_map(static fn ($column) => 'base.'.$column, array_values(array_diff($this->columns,$local)));
        foreach ($local as $column) if (!in_array($column,['sort','is_visible','is_active'],true)) $select[]='sc.'.$column;
        return $this->db->table('symbol_config as sc')
            ->join('symbol_config as base', function ($join): void {
                $join->on('base.id','=',$this->db->raw('COALESCE(sc.base_config_id, sc.id)'))
                    ->where('base.home_site_id',0)->where('base.agent_id',0)->whereNull('base.deleted_at');
            })
            ->leftJoin('symbol_config as site', function ($join) use ($siteId): void {
                $join->on('site.base_config_id','=','base.id')->where('site.home_site_id',$siteId)
                    ->where('site.agent_id',0)->whereNull('site.deleted_at');
            })
            ->where('sc.home_site_id',$siteId)->where('sc.agent_id',$agentId)->whereNull('sc.deleted_at')
            ->select($select)
            ->selectRaw('COALESCE(sc.sort, site.sort, base.sort, 0) AS sort')
            ->selectRaw('COALESCE(sc.is_visible, site.is_visible, base.is_visible, TRUE) AS is_visible')
            ->selectRaw('COALESCE(sc.is_active, site.is_active, base.is_active, 0) AS is_active');
    }

    /** Platform public edits require no fan-out. Only the symbol identity follows a rename. */
    public function syncIdentity(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $this->lock();
            $root = $this->query(0)->where('id',$id)->first();
            if (!$root) throw new DomainException('平台合约不存在');
            $this->db->table('symbol_config')->where('home_site_id','>',0)->where('base_config_id',$id)
                ->where('symbol','<>',$root->symbol)->update(['symbol'=>$root->symbol,
                    'revision'=>$this->db->raw('revision + 1'),'updated_at'=>$this->now()]);
        });
    }

    public function update(int $siteId, int $agentId, int $id, array $input): array
    {
        $this->validateScope($siteId,$agentId);
        if ($siteId===0) throw new DomainException('不允许修改平台配置');
        if (array_diff(array_keys($input),self::PREFERENCES)) throw new DomainException('仅允许修改排序、标签、交易状态和上架状态');
        return $this->db->transaction(function () use ($siteId,$agentId,$id,$input): array {
            $this->ensureScope($siteId,$agentId);
            $row=$this->query($siteId,$agentId)->where('id',$id)->whereNull('deleted_at')->lockForUpdate()->first();
            if (!$row || !$this->query(0)->where('id',$row->base_config_id)->whereNull('deleted_at')->exists()) {
                throw new DomainException('合约不存在或不属于当前账户');
            }
            $values=[];
            foreach ($input as $field=>$value) {
                if ($field==='tags') {
                    if ($value===null) $this->ruleQuery($row)->delete();
                    else $this->saveTagSelection($row,$value);
                    continue;
                }
                if ($value===null) { $values[$field]=null; continue; }
                $integer=is_bool($value)?(int)$value:filter_var($value,FILTER_VALIDATE_INT);
                if ($integer===false || ($field==='sort'?$integer<0:!in_array($integer,[0,1],true))) {
                    throw new DomainException('请求参数有误');
                }
                $values[$field]=$field==='is_visible'?(bool)$integer:$integer;
            }
            $values += ['revision'=>$this->db->raw('revision + 1'),'updated_at'=>$this->now()];
            $this->query($siteId,$agentId)->where('id',$id)->update($values);
            $resolved=$this->effectiveQuery($siteId,$agentId)->where('sc.id',$id)->first();
            if (array_key_exists('is_visible',$input) && (bool)$resolved->is_visible && (int)$resolved->is_active!==1) {
                throw new DomainException('请先上架该合约，再开启交易');
            }
            if (array_key_exists('is_active',$input) && (int)$resolved->is_active===0 && (bool)$resolved->is_visible) {
                throw new DomainException('请先关闭交易开关，再关闭上架开关');
            }
            return (array)$resolved;
        });
    }

    /** Batch-resolve tag rules by original tag identity through platform -> site -> merchant. */
    public function tagsFor(int $siteId, int $agentId, array $rows): array
    {
        if ($rows===[]) return [];
        [$byId,$catalog]=$this->tagCatalog($siteId,$agentId);
        $baseIds=array_values(array_unique(array_map(static fn ($row)=>(int)($row->base_config_id ?: $row->id),$rows)));
        $roots=$this->query(0)->whereIn('id',$baseIds)->whereNull('deleted_at')->get()->keyBy('id');
        $sites=$siteId>0?$this->query($siteId)->whereIn('base_config_id',$baseIds)->whereNull('deleted_at')->get()->keyBy('base_config_id'):collect();
        $ids=array_merge($roots->pluck('id')->all(),$sites->pluck('id')->all(),array_map(static fn ($row)=>(int)$row->id,$rows));
        $rules=$this->db->table('symbol_config_tag')->whereIn('symbol_config_id',$ids)->get()->groupBy('symbol_config_id');
        $result=[];
        foreach ($rows as $row) {
            $baseId=(int)($row->base_config_id ?: $row->id);
            $levels=array_filter([$roots->get($baseId),$agentId>0?$sites->get($baseId):null,$siteId>0?$row:null]);
            $attached=[];
            foreach ($levels as $level) foreach ($rules->get($level->id,collect()) as $rule) {
                if ((int)$rule->home_site_id!==(int)$level->home_site_id || (int)$rule->agent_id!==(int)$level->agent_id) continue;
                $tag=$byId[(int)$rule->symbol_tag_id]??null;
                if (!$tag || !$this->tagAllowed($tag,(int)$level->home_site_id,(int)$level->agent_id)) continue;
                $key=(int)($tag->base_tag_id ?: $tag->id);
                if ((bool)$rule->is_attached) $attached[$key]=true;
                else unset($attached[$key]);
            }
            $tags=[];
            foreach (array_keys($attached) as $key) {
                if (!isset($catalog[$key])) continue;
                $tag=clone $catalog[$key];
                $parent=$byId[(int)$tag->parent_id]??null;
                $effectiveParent=$parent?($catalog[(int)($parent->base_tag_id ?: $parent->id)]??null):null;
                $tag->parent_id=$effectiveParent->id??0;
                $tag->parent_tag_code=$effectiveParent->tag_code??null;
                $tag->parent_tag_name=$effectiveParent->tag_name??null;
                $tag->parent_tag_name_zh=$effectiveParent->tag_name_zh??null;
                $tag->parent_tag_name_en=$effectiveParent->tag_name_en??null;
                $tags[]=$tag;
            }
            usort($tags,static fn ($a,$b)=>((int)$a->parent_id!==0)<=>((int)$b->parent_id!==0)
                ?: ((int)$b->sort<=>(int)$a->sort) ?: ((int)$a->id<=>(int)$b->id));
            $result[(int)$row->id]=$tags;
        }
        return $result;
    }

    private function saveTagSelection(object $row, mixed $input): void
    {
        if (!is_array($input) || count($input)>100) throw new DomainException('标签参数有误');
        [$byId,$catalog]=$this->tagCatalog((int)$row->home_site_id,(int)$row->agent_id);
        $selected=[];
        foreach ($input as $id) {
            $id=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $tag=$id===false?null:($byId[$id]??null);
            if (!$tag) throw new DomainException('标签不存在或不属于当前账户');
            $selected[(int)($tag->base_tag_id ?: $tag->id)]=$tag;
            if ((int)$tag->parent_id>0) {
                $parent=$byId[(int)$tag->parent_id]??null;
                if (!$parent || (int)$parent->parent_id!==0) throw new DomainException('标签层级无效');
                $selected[(int)($parent->base_tag_id ?: $parent->id)]=$parent;
            }
        }
        // Replacing the displayed selection records removals of currently inherited tags.
        $parent=(int)$row->agent_id>0
            ?$this->query((int)$row->home_site_id)->where('base_config_id',$row->base_config_id)->whereNull('deleted_at')->first()
            :$this->query(0)->where('id',$row->base_config_id)->whereNull('deleted_at')->first();
        $parent ??= $this->query(0)->where('id',$row->base_config_id)->whereNull('deleted_at')->first();
        $inherited=$parent?($this->tagsFor((int)$parent->home_site_id,(int)$parent->agent_id,[$parent])[(int)$parent->id]??[]):[];
        $detached=[];
        foreach ($this->ruleQuery($row)->where('is_attached',false)->get() as $rule) {
            $tag=$byId[(int)$rule->symbol_tag_id]??null;
            if ($tag) $detached[(int)($tag->base_tag_id ?: $tag->id)]=$tag;
        }
        foreach ($inherited as $tag) $detached[(int)($tag->base_tag_id ?: $tag->id)]=$tag;
        $this->ruleQuery($row)->delete();
        foreach ($detached as $key=>$tag) {
            if (!isset($selected[$key])) $this->insertRule($row,(int)($catalog[$key]->id??$tag->id),false);
        }
        foreach ($selected as $key=>$tag) $this->insertRule($row,(int)($catalog[$key]->id??$tag->id),true);
    }

    private function ruleQuery(object $row): Builder
    {
        return $this->db->table('symbol_config_tag')->where('symbol_config_id',$row->id)
            ->where('home_site_id',$row->home_site_id)->where('agent_id',$row->agent_id);
    }

    private function insertRule(object $row,int $tagId,bool $attached): void
    {
        $this->db->table('symbol_config_tag')->insert(['symbol_config_id'=>$row->id,'symbol_tag_id'=>$tagId,
            'home_site_id'=>$row->home_site_id,'agent_id'=>$row->agent_id,'is_attached'=>$attached,'revision'=>1]);
    }

    private function tagCatalog(int $siteId,int $agentId): array
    {
        $rows=$this->db->table('symbol_tag')->whereNull('deleted_at')->where(function ($q) use ($siteId,$agentId): void {
            $q->where(function ($q): void { $q->where('home_site_id',0)->where('agent_id',0); });
            if ($siteId>0) $q->orWhere(function ($q) use ($siteId): void { $q->where('home_site_id',$siteId)->where('agent_id',0); });
            if ($agentId>0) $q->orWhere(function ($q) use ($siteId,$agentId): void { $q->where('home_site_id',$siteId)->where('agent_id',$agentId); });
        })->get()->all();
        usort($rows,static fn ($a,$b)=>((int)$a->home_site_id>0?1:0)+((int)$a->agent_id>0?1:0)
            <=> ((int)$b->home_site_id>0?1:0)+((int)$b->agent_id>0?1:0));
        $byId=[]; $catalog=[];
        foreach ($rows as $tag) { $byId[(int)$tag->id]=$tag; $catalog[(int)($tag->base_tag_id ?: $tag->id)]=$tag; }
        return [$byId,$catalog];
    }

    private function tagAllowed(object $tag,int $siteId,int $agentId): bool
    {
        return ((int)$tag->home_site_id===0 && (int)$tag->agent_id===0)
            || ((int)$tag->home_site_id===$siteId && ((int)$tag->agent_id===0 || (int)$tag->agent_id===$agentId));
    }

    /** Tag definitions retain their existing local IDs; no assignment rules are copied. */
    private function ensureTags(int $siteId,int $agentId): void
    {
        [$byId,$catalog]=$this->tagCatalog($siteId,0);
        $map=[];
        foreach ($catalog as $baseId=>$tag) {
            if ((int)$tag->home_site_id===$siteId && (int)$tag->agent_id===$agentId) {
                $map[$baseId]=(int)$tag->id;
                continue;
            }
            $local=$this->db->table('symbol_tag')->where('home_site_id',$siteId)->where('agent_id',$agentId)->where('base_tag_id',$baseId)->first();
            if ($local===null) {
                $data=array_intersect_key((array)$tag,array_flip(['tag_name','tag_name_zh','tag_name_en','tag_code','sort','is_enable']));
                $this->db->table('symbol_tag')->insertOrIgnore($data+['home_site_id'=>$siteId,'agent_id'=>$agentId,'base_tag_id'=>$baseId,
                    'parent_id'=>0,'count'=>0,'revision'=>1,'created_at'=>$this->now(),'updated_at'=>$this->now()]);
                $local=$this->db->table('symbol_tag')->where('home_site_id',$siteId)->where('agent_id',$agentId)->where('base_tag_id',$baseId)->first();
            }
            if ($local && $local->deleted_at===null) $map[$baseId]=(int)$local->id;
        }
        foreach ($catalog as $baseId=>$tag) {
            if (!isset($map[$baseId]) || (int)$tag->parent_id===0) continue;
            $parent=$byId[(int)$tag->parent_id]??null;
            $parentKey=$parent?(int)($parent->base_tag_id ?: $parent->id):0;
            if (isset($map[$parentKey])) $this->db->table('symbol_tag')->where('id',$map[$baseId])
                ->where('parent_id','<>',$map[$parentKey])->update(['parent_id'=>$map[$parentKey]]);
        }
    }

    private function importLegacyTags(int $siteId,int $agentId): void
    {
        if (!$this->db->getSchemaBuilder()->hasTable('agent_symbol_tag')) return;
        $tags=$this->db->table('agent_symbol_tag')->where('agent_id',$agentId)->whereNull('deleted_at')->orderBy('id')->get();
        $map=[];
        foreach ($tags as $tag) {
            $query=$this->db->table('symbol_tag')->where('home_site_id',$siteId)->where('agent_id',$agentId)->where('tag_code',$tag->tag_code)->whereNull('deleted_at');
            $id=$query->value('id');
            $values=array_intersect_key((array)$tag,array_flip(['tag_name','tag_name_zh','tag_name_en','tag_code','sort','is_enable']));
            if ($id) $this->db->table('symbol_tag')->where('id',$id)->update($values);
            else $id=$this->db->table('symbol_tag')->insertGetId($values+['home_site_id'=>$siteId,'agent_id'=>$agentId,
                'parent_id'=>0,'count'=>0,'revision'=>1,'created_at'=>$this->now(),'updated_at'=>$this->now()]);
            $map[(int)$tag->id]=(int)$id;
            if ($this->db->getSchemaBuilder()->hasTable('symbol_tag_translation')) {
                foreach ($this->db->table('symbol_tag_translation')->where('agent_symbol_tag_id',$tag->id)->where('symbol_tag_id',0)->get() as $translation) {
                    $this->db->table('symbol_tag_translation')->insertOrIgnore(['symbol_tag_id'=>$id,'agent_symbol_tag_id'=>0,
                        'locale'=>$translation->locale,'name'=>$translation->name,'created_at'=>$this->now(),'updated_at'=>$this->now()]);
                }
            }
        }
        foreach ($tags as $tag) if (isset($map[$tag->parent_id])) {
            $this->db->table('symbol_tag')->where('id',$map[$tag->id])->update(['parent_id'=>$map[$tag->parent_id]]);
        }
    }

    private function importLegacyConfig(object $row,int $baseId): void
    {
        if ((int)$row->agent_id===0 || !$this->db->getSchemaBuilder()->hasTable('agent_symbol_config')) return;
        $legacy=$this->db->table('agent_symbol_config')->where('agent_id',$row->agent_id)->where('symbol_config_id',$baseId)->whereNull('deleted_at')->first();
        if (!$legacy) return;
        $this->query((int)$row->home_site_id,(int)$row->agent_id)->where('id',$row->id)->update([
            'sort'=>$legacy->sort,'is_visible'=>(bool)$legacy->trading_status,'is_active'=>(int)$legacy->listing_status]);
        if (!$this->db->getSchemaBuilder()->hasTable('agent_symbol_config_tag') || !$this->db->getSchemaBuilder()->hasTable('agent_symbol_tag')) return;
        $codes=$this->db->table('agent_symbol_config_tag as r')->join('agent_symbol_tag as t','t.id','=','r.agent_symbol_tag_id')
            ->where('r.agent_id',$row->agent_id)->where('t.agent_id',$row->agent_id)->where('r.agent_symbol_config_id',$legacy->id)
            ->whereNull('t.deleted_at')->pluck('t.tag_code')->all();
        $ids=$this->db->table('symbol_tag')->where('home_site_id',$row->home_site_id)->where('agent_id',$row->agent_id)
            ->whereIn('tag_code',$codes)->whereNull('deleted_at')->pluck('id')->all();
        $this->saveTagSelection($row,$ids);
    }

    private function validateScope(int $siteId,int $agentId): void
    {
        if ($siteId<0 || $agentId<0 || ($siteId===0 && $agentId!==0)) throw new DomainException('无效的合约配置归属');
    }

    private function now(): string { return date('Y-m-d H:i:s'); }
}
