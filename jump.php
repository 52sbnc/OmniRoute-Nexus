<?php
/**
 * 全链路跳转入口（默认网页跳转，支持 301 / 302）
 *
 * /entry → 第 1 层（入口层）
 * /mid   → 第 2 层（中转层）
 * /land  → 第 3 层（落地层）
 * /target→ 第 4 层（最终落地 URL）
 * /health→ 健康检查接口（供实时检测使用，不跳转）
 * /go/{layer}
 */
require_once __DIR__ . '/app/bootstrap.php';

$start = microtime(true);

// ---------- 自检模式：jump.php?diag=1（仅本机访问或带 app.cron_token） ----------
if (isset($_GET['diag'])) {
    $token = (string) ($_GET['token'] ?? '');
    $key   = (string) Config::get('app.cron_token', '');
    $local = in_array(client_ip(), ['127.0.0.1', '::1'], true);
    header('Content-Type: text/plain; charset=utf-8');
    if (!$local && ($key === '' || !hash_equals($key, $token))) {
        http_response_code(403);
        exit("403 Forbidden\n");
    }

    $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
    $out   = [];
    $out[] = 'PHP 版本 : ' . PHP_VERSION . '（' . ($phpOk ? '满足 7.4+' : '过低，需 7.4 及以上') . '）';
    foreach (['pdo_mysql', 'redis', 'curl', 'mbstring', 'json'] as $ext) {
        $out[] = '扩展 ' . str_pad($ext, 10) . ': ' . (extension_loaded($ext) ? '正常' : '缺失');
    }
    $out[] = 'DNS 函数  : ' . (function_exists('dns_get_record') ? '正常' : '缺失');

    $host   = request_host();
    $scheme = request_scheme();
    $out[]  = '';
    $out[]  = '访问域名 : ' . $host . '（协议 ' . $scheme . '）';
    $ips    = Detector::resolve($host);
    $out[]  = 'DNS 解析 : ' . ($ips ? implode(', ', $ips) : '解析失败');

    try {
        $out[] = '数据库   : 正常（layers ' . (int) Database::value('SELECT COUNT(*) FROM `layers`') . ' 行）';
    } catch (Throwable $e) {
        $out[] = '数据库   : 失败 - ' . $e->getMessage();
    }
    $out[] = 'Redis    : ' . (Cache::usingRedis() ? '正常' : '不可用 - ' . Cache::lastError());

    try {
        $route = DomainService::route();
        $layer = 1;
        if (isset($route['l1']['map'][$host])) {
            $layer = 1;
        } elseif ((string) ($route['l2']['active'] ?? '') === $host) {
            $layer = 2;
        } elseif (in_array($host, $route['l3'] ?? [], true)) {
            $layer = 3;
        }
        $out[] = '';
        $out[] = '--- 跳转快照 ---';
        $out[] = '跳转方式  : ' . ($route['jump_type'] ?? '');
        $out[] = '下级协议  : ' . ($route['scheme'] ?? '');
        $out[] = '强制HTTPS : ' . (!empty($route['force_https']) ? '开启' : '关闭');
        $out[] = '识别层级  : 第 ' . $layer . ' 层';
        $out[] = '第2层当前 : ' . (string) ($route['l2']['active'] ?? '') . '（备用 ' . implode(',', $route['l2']['standby'] ?? []) . '）';
        $out[] = '第3层在线 : ' . implode(',', $route['l3'] ?? []);
        $out[] = '最终落地  : ' . (string) ($route['final'] ?? '');
        $out[] = '兜底地址  : ' . (string) ($route['fallback'] ?? '');
    } catch (Throwable $e) {
        $out[] = '跳转快照  : 读取失败 - ' . $e->getMessage();
    }

    echo implode("\n", $out) . "\n";
    exit;
}

// ---------- 健康检查（供实时检测器使用） ----------
if (isset($_GET['health'])) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'OK ' . date('Y-m-d H:i:s');
    exit;
}

$route = DomainService::route();

// ---------- 强制 HTTPS：开启时 http 访问整体 301 到 https ----------
// 强制 HTTPS（开启时 http 访问 301 到 https）
if (enforce_https($route)) {
    exit;
}

$host  = request_host();
$layer = (int) get_param('layer', 0);

// ---------- 层级识别 ----------
if ($layer < 1 || $layer > 4) {
    if (isset($route['l1']['map'][$host])) {
        $layer = 1;
    } elseif (!empty($route['l2']['active']) && $host === $route['l2']['active']) {
        $layer = 2;
    } elseif (in_array($host, $route['l2']['standby'] ?? [], true)) {
        $layer = 2;
    } elseif (in_array($host, $route['l3'] ?? [], true)) {
        $layer = 3;
    } else {
        $layer = 1; // 默认按入口层处理
    }
}

// ---------- 下级域名协议：https / http / auto（跟随访问协议） ----------
$scheme   = resolve_scheme((string) ($route['scheme'] ?? 'https'));
$final    = trim((string) ($route['final'] ?? ''));
$fallback = trim((string) ($route['fallback'] ?? '')) ?: $final;

// ---------- 防刷：IP 限频 & UA 过滤 ----------
$blocked = false;
$ua      = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
$ip      = client_ip();

$limit = (int) ($route['ip_limit'] ?? 0);
if ($limit > 0 && $ip !== '') {
    $n = Cache::incr('rl:' . md5($ip) . ':' . date('YmdHi'), 70);
    if ($n > $limit) {
        $blocked = true;
    }
}

$blacklist = trim((string) ($route['ua_blacklist'] ?? ''));
if (!$blocked && $blacklist !== '' && $ua !== '') {
    foreach (array_filter(array_map('trim', explode("\n", str_replace(',', "\n", $blacklist)))) as $kw) {
        if ($kw !== '' && stripos($ua, $kw) !== false) {
            $blocked = true;
            break;
        }
    }
}

// ---------- 计算目标地址 ----------
$target   = '';
$result   = 'ok';
$fallbackUsed = false;

if ($layer === 1) {
    // 已禁用/失败的入口域名 → 直接走兜底
    $status = $route['l1']['map'][$host] ?? '';
    if (in_array($status, ['disabled', 'failed'], true)) {
        $target = $fallback;
        $result = 'fallback';
        $fallbackUsed = true;
    } else {
        $mid = (string) ($route['l2']['active'] ?? '');
        if ($mid === '') {
            $mid = (string) ($route['l2']['standby'][0] ?? '');
        }
        // 跳到第 2 层域名的根路径，由该站点的 index.php 自动识别层级继续跳
        $target = $mid !== '' ? build_url($scheme, $mid, '/') : $fallback;
        $result = $mid !== '' ? 'ok' : 'fallback';
        $fallbackUsed = $mid === '';
    }
} elseif ($layer === 2) {
    $lands = $route['l3'] ?? [];
    $land  = '';
    if ($lands) {
        $land = count($lands) === 1 ? $lands[0] : $lands[random_int(0, count($lands) - 1)];
    }
    $target = $land !== '' ? build_url($scheme, $land, '/') : $fallback;
    $result = $land !== '' ? 'ok' : 'fallback';
    $fallbackUsed = $land === '';
} else {
    $target = $final !== '' ? $final : $fallback;
    $result = $final !== '' ? 'ok' : 'fallback';
    $fallbackUsed = $final === '';
}

if ($blocked) {
    $target = $fallback;
    $result = 'blocked';
}

if ($target === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo '导流目标未配置';
    exit;
}

// 协议相对地址补全（兼容 PHP 7.4）
if (substr($target, 0, 2) === '//') {
    $target = $scheme . ':' . $target;
}

// ---------- 跳转日志 + 当日汇总 ----------
// 透传访问流程标识，让第 2/3 层共享同一次访问（跳转次数只记一次）
if ($layer <= 2 && $result === 'ok') {
    $target = append_query($target, 'flow', DomainService::flowToken());
}

// 渠道归属：入口层域名所属渠道（其余层为 0）
$channelId = (int) ($route['domains'][$host]['channel'] ?? 0);
DomainService::logJump($layer, $host, $target, $result, (int) round((microtime(true) - $start) * 1000), $channelId);

// ---------- 输出跳转（默认网页跳转） ----------
$jumpType = (string) ($route['jump_type'] ?? 'meta');
if (!in_array($jumpType, ['301', '302', 'meta'], true)) {
    $jumpType = 'meta';
}

// HTML 兜底页面：meta 刷新 + 手动链接
$body = static function (string $url): string {
    $safe = e($url);
    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<meta http-equiv="refresh" content="0.5;url=' . $safe . '">'
        . '<title>正在跳转…</title></head>'
        . '<body style="margin:0;height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'font:14px/1.7 -apple-system,\'PingFang SC\',\'Microsoft YaHei\',sans-serif;color:#8a8f99">'
        . '<div>正在跳转，若未自动跳转请 <a href="' . $safe . '" style="color:#a8843e">点此继续</a></div>'
        . '</body></html>';
};

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Content-Type: text/html; charset=utf-8');
    // meta 方式不使用 Location 头，纯 HTML 跳转
    if ($jumpType !== 'meta') {
        header('Location: ' . $target, true, $jumpType === '301' ? 301 : 302);
    }
}
echo $body($target);
exit;
