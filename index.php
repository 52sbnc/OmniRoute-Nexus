<?php
/**
 * 站点入口
 * - 未入池 / 待使用 / 备用 / 已到期 / 待处理 / 检测失败：只显示提示文字，不对外服务
 * - 第 1 层入口域名：展示海报模板页，按钮指向中转层
 * - 第 2、3 层：继续向下跳转
 */
require_once __DIR__ . '/app/bootstrap.php';

/**
 * 输出极简状态页（页面上只显示这几个字）
 */
function show_status_text(string $text): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($text) . '</title></head>'
        . '<body style="margin:0;height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'background:#fff;color:#1e232b;'
        . 'font:16px/1.6 -apple-system,\'PingFang SC\',\'Microsoft YaHei\',Segoe UI,sans-serif">'
        . e($text) . '</body></html>';
    exit;
}

$start = microtime(true);
$route = DomainService::route();

// 快照不可用（数据库/缓存异常）时按导流处理，由 jump.php 给出兜底或明确提示
if (empty($route['ts'])) {
    require_once __DIR__ . '/jump.php';
    exit;
}

// 强制 HTTPS（开启时 http 访问 301 到 https）
if (enforce_https($route)) {
    exit;
}

$host  = request_host();
$state = DomainService::hostState($route, $host);

// 不可对外服务的状态：只显示提示文字
if ($state['text'] !== '') {
    show_status_text($state['text']);
}

// 第 1 层入口域名：展示海报模板
if ($state['layer'] === 1) {
    $scheme = resolve_scheme((string) ($route['scheme'] ?? 'auto'));
    if (DomainService::renderPoster($route, $host, (int) $state['template_id'], $scheme, $start)) {
        exit;
    }
    // 未配置任何模板时回退为直接跳转，保证链路不中断
}

// 第 2、3 层：跳转
require_once __DIR__ . '/jump.php';
