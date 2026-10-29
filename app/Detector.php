<?php
/**
 * 域名健康检测：只看 DNS 解析
 *
 * 判定标准：该域名当前是否还能解析出 IP 地址。
 * 不发起 HTTP 请求、不判断能否跳转、不校验 SSL。
 * 每次检测都重新发起 DNS 查询，不复用本地解析缓存。
 */
class Detector
{
    /**
     * 检测单个域名
     *
     * @param array $domain 域名行
     * @return array{ok:bool,dns_ok:bool,resolved_ip:string,http_code:int,ssl_ok:bool,keyword_ok:bool,response_ms:int,message:string}
     */
    public static function check(array $domain): array
    {
        $host   = (string) $domain['domain'];
        $result = [
            'ok'          => false,
            'dns_ok'      => false,
            'resolved_ip' => '',
            'http_code'   => 0,
            'ssl_ok'      => true,
            'keyword_ok'  => true,
            'response_ms' => 0,
            'message'     => '',
        ];

        $start = microtime(true);
        $ips   = self::resolve($host);
        $result['response_ms'] = (int) round((microtime(true) - $start) * 1000);

        if (!$ips) {
            $result['message'] = 'DNS 解析失败（域名已无法解析）';
            return $result;
        }

        $result['dns_ok']      = true;
        $result['ok']          = true;
        $result['resolved_ip'] = $ips[0];
        $result['message']     = 'DNS 正常（' . implode(' / ', array_slice($ips, 0, 3)) . '）';
        return $result;
    }

    /**
     * 实时解析域名，返回 IP 列表
     * 使用 dns_get_record 直接向 DNS 服务器查询，绕过系统解析缓存
     */
    public static function resolve(string $host): array
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return [];
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        if (function_exists('dns_get_record')) {
            foreach ([DNS_A, DNS_AAAA] as $type) {
                $records = @dns_get_record($host, $type);
                if (is_array($records)) {
                    foreach ($records as $r) {
                        if (!empty($r['ip']) && filter_var($r['ip'], FILTER_VALIDATE_IP)) {
                            $ips[] = (string) $r['ip'];
                        } elseif (!empty($r['ipv6']) && filter_var($r['ipv6'], FILTER_VALIDATE_IP)) {
                            $ips[] = (string) $r['ipv6'];
                        }
                    }
                }
                if ($ips) {
                    break; // 已有 A 记录，不再查 AAAA
                }
            }
        }

        // 兜底：dns_get_record 不可用或查询失败时退回系统解析
        if (!$ips) {
            $ip = @gethostbyname($host);
            if (is_string($ip) && $ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }
}
