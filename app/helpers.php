<?php
/**
 * 通用助手函数
 */

/** HTML 转义输出 */
function e($str): string
{
    return htmlspecialchars((string) $str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 读取 GET/POST 参数 */
function input(string $key, $default = '')
{
    if (isset($_POST[$key])) {
        return is_array($_POST[$key]) ? $_POST[$key] : trim((string) $_POST[$key]);
    }
    if (isset($_GET[$key])) {
        return is_array($_GET[$key]) ? $_GET[$key] : trim((string) $_GET[$key]);
    }
    return $default;
}

function post(string $key, $default = '')
{
    return isset($_POST[$key]) ? (is_array($_POST[$key]) ? $_POST[$key] : trim((string) $_POST[$key])) : $default;
}

function get_param(string $key, $default = '')
{
    return isset($_GET[$key]) ? (is_array($_GET[$key]) ? $_GET[$key] : trim((string) $_GET[$key])) : $default;
}

/** 客户端 IP */
function client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = explode(',', (string) $_SERVER[$k])[0];
            return trim($ip);
        }
    }
    return '0.0.0.0';
}

/** 当前请求主机（不含端口，兼容反代） */
function request_host(): string
{
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '';
    $host = strtolower(trim(explode(',', (string) $host)[0]));
    // 去掉端口（保留 IPv6 场景下的简单处理）
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    return $host;
}

/**
 * 强制 HTTPS：开启时把 http 访问 301 到 https
 * 返回 true 表示已经发出跳转，调用方应立即结束
 */
function enforce_https(array $route): bool
{
    if (!empty($route['force_https']) && request_scheme() === 'http') {
        header('Location: ' . current_url('https'), true, 301);
        return true;
    }
    return false;
}

/** 当前请求使用的协议（兼容反向代理） */
function request_scheme(): string
{
    $proto = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    if ($proto !== '') {
        $proto = strtolower(trim(explode(',', $proto)[0]));
        if ($proto === 'https' || $proto === 'http') {
            return $proto;
        }
    }
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off' && $https !== '0') {
        return 'https';
    }
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return 'https';
    }
    return 'http';
}

/**
 * 解析下级域名协议配置
 * https / http 为固定协议，auto 表示跟随当前访问协议（http 与 https 都可用）
 */
function resolve_scheme(string $config): string
{
    $config = strtolower(trim($config));
    return in_array($config, ['http', 'https'], true) ? $config : request_scheme();
}

/** 当前完整请求地址，用于协议跳转 */
function current_url(string $scheme): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    return $scheme . '://' . request_host() . $uri;
}

/** 规范化域名输入：去掉协议、路径、端口、前后空格 */
function normalize_domain(string $domain): string
{
    $domain = trim($domain);
    $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
    $domain = strtolower($domain);
    $domain = preg_replace('/\/.*$/', '', $domain) ?? $domain;
    $domain = preg_replace('/:\d+$/', '', $domain) ?? $domain;
    return trim($domain, ". \t\n\r\0\x0B");
}

/** 校验域名格式 */
function is_valid_domain(string $domain): bool
{
    if ($domain === '' || strlen($domain) > 190) {
        return false;
    }
    if (filter_var($domain, FILTER_VALIDATE_IP)) {
        return true;
    }
    return (bool) preg_match('/^(?=.{1,190}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain);
}

/** 校验 URL 格式 */
function is_valid_url(string $url): bool
{
    return (bool) filter_var($url, FILTER_VALIDATE_URL);
}

/** 拼装 https/http 地址 */
function build_url(string $scheme, string $domain, string $path = '/'): string
{
    $scheme = $scheme === 'http' ? 'http' : 'https';
    $path   = '/' . ltrim($path, '/');
    return $scheme . '://' . $domain . $path;
}

/** 给 URL 追加查询参数 */
function append_query(string $url, string $key, string $value): string
{
    if ($url === '' || $value === '') {
        return $url;
    }
    $sep = strpos($url, '?') === false ? '?' : '&';
    return $url . $sep . rawurlencode($key) . '=' . rawurlencode($value);
}

/** 人性化时间 */
function human_time(?string $datetime): string
{
    if (!$datetime) {
        return '-';
    }
    $ts = strtotime($datetime);
    if (!$ts) {
        return '-';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return $diff <= 0 ? '刚刚' : $diff . ' 秒前';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' 分钟前';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' 小时前';
    }
    if ($diff < 86400 * 30) {
        return floor($diff / 86400) . ' 天前';
    }
    return date('Y-m-d H:i', $ts);
}

/** 人性化时长 */
function human_duration(int $seconds): string
{
    if ($seconds <= 0) {
        return '0 秒';
    }
    $d = intdiv($seconds, 86400);
    $h = intdiv($seconds % 86400, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    $out = [];
    if ($d) {
        $out[] = $d . ' 天';
    }
    if ($h) {
        $out[] = $h . ' 小时';
    }
    if ($m) {
        $out[] = $m . ' 分';
    }
    if (!$d && !$h && $s) {
        $out[] = $s . ' 秒';
    }
    return implode(' ', $out);
}

/** 状态中文名 */
function status_label(string $status, int $layerId = 0): string
{
    $map = [
        'pending'   => '待使用',
        'active'    => $layerId === 3 ? '在线' : ($layerId === 2 ? '当前使用' : '正在使用'),
        'observing' => '观察期',
        'standby'   => '备用',
        'disabled'  => '已禁用',
        'failed'    => '检测失败',
    ];
    return $map[$status] ?? $status;
}

function status_class(string $status): string
{
    return [
        'pending'   => 'gray',
        'active'    => 'green',
        'observing' => 'orange',
        'standby'   => 'blue',
        'disabled'  => 'dark',
        'failed'    => 'red',
    ][$status] ?? 'gray';
}

/** JSON 响应 */
function json_response($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 生成随机字符串 */
function random_str(int $len = 32): string
{
    return substr(bin2hex(random_bytes($len)), 0, $len);
}
