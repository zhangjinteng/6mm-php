<?php
declare(strict_types=1);

namespace SixMm\Shared\TenantBrand;

use NetDNS2\Resolver;
use NetDNS2\RR;

final class DomainDns implements BindingDns
{
    public function __construct(private array $config) {}

    public static function normalize(string $domain): string
    {
        $ascii = idn_to_ascii(trim($domain), IDNA_USE_STD3_RULES | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if (!$ascii || str_contains($ascii, '*')) throw new DomainConfigException('请输入完整域名，不支持通配符');
        return DomainConfigValidator::normalize([['domain'=>$ascii,'is_primary'=>true]])[0]['domain'];
    }

    // Walk DNS zone cuts rather than assuming the last two labels are the hosted zone.
    public function zone(string $domain): string
    {
        $zone = $domain;
        while (str_contains($zone,'.')) {
            $rr = dns_get_record($zone.'.', DNS_SOA);
            if ($rr === false) throw new DomainConfigException('暂时无法查询权威 DNS，请稍后重试');
            foreach ($rr as $r) {
                if ($r['type'] === 'SOA' && strtolower(rtrim($r['host'],'.')) === $zone) return $zone;
            }
            $zone = explode('.', $zone, 2)[1];
        }
        throw new DomainConfigException('未找到已委派的 DNS 区域，请先配置域名服务器');
    }

    public function records(object $b): array
    {
        $apex = $b->domain === $b->dns_zone;
        $ips = array_values(array_unique($this->config['apex_ipv4'] ?? []));
        if ($apex && (!($this->config['apex_ready'] ?? false) || count($ips) !== 3 ||
            array_filter($ips, fn($ip)=>!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_GLOBAL_RANGE)))) return [];
        $name = $apex ? '@' : substr($b->domain,0,-strlen($b->dns_zone)-1);
        return [
            ['type'=>'TXT','name'=>$apex ? '_6mm-verify' : '_6mm-verify.'.$name,'values'=>[$b->ownership_token]],
            ['type'=>$apex ? 'A':'CNAME','name'=>$name,'values'=>$apex ? $ips : [strtolower(rtrim($this->config['cname_target'],'.'))]]
        ];
    }

    public function verify(object $b): void
    {
        $records = $this->records($b);
        if (!$records) throw new DomainConfigException('平台固定 IP 尚未就绪');
        if ($this->zone($b->domain) !== $b->dns_zone) throw new DomainConfigException('DNS 托管区已变更，请取消后重新获取解析说明');
        $servers = [];
        foreach (dns_get_record($b->dns_zone.'.',DNS_NS) ?: [] as $ns) {
            if ($ns['type'] !== 'NS' || strtolower(rtrim($ns['host'],'.')) !== $b->dns_zone) continue;
            foreach (dns_get_record(rtrim($ns['target'],'.').'.',DNS_A) ?: [] as $rr) {
                $ip = $rr['ip'] ?? '';
                // Connect only to a validated literal, never to a user-supplied hostname or internal address.
                if (filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_GLOBAL_RANGE)) $servers[$ip] = $ip;
            }
            if (count($servers) >= 2) break;
        }
        if (!$servers) throw new DomainConfigException('未找到可访问的公网权威 DNS 服务器');
        $resolver = new Resolver(['nameservers'=>array_slice(array_values($servers),0,2),'timeout'=>2,'recurse'=>false]);
        foreach ($records as $record) {
            $fqdn = ($record['name'] === '@' ? '' : $record['name'].'.').$b->dns_zone;
            try {
                $reply = $resolver->query($fqdn, $record['type']);
            } catch (\NetDNS2\Exception) {
                throw new DomainConfigException('未能从权威 DNS 读取 '.$record['type'].' 记录，请确认解析已生效');
            }
            $values = [];
            foreach ($reply->answer as $rr) {
                if (strtolower(rtrim((string)$rr->name,'.')) !== $fqdn) continue;
                if ($rr instanceof RR\TXT) $values[] = implode('', array_map(fn($s)=>$s->value(), $rr->text));
                if ($rr instanceof RR\A) $values[] = (string)$rr->address;
                if ($rr instanceof RR\CNAME) $values[] = strtolower(rtrim((string)$rr->cname,'.'));
            }
            $expected = $record['values']; sort($expected); sort($values);
            $matches = $record['type'] === 'TXT' ? in_array($expected[0],$values,true) : $expected === $values;
            if (!$reply->header->aa || !$matches) throw new DomainConfigException('请核对 '.$fqdn.' 的 '.$record['type'].' 记录');
        }
    }
}
