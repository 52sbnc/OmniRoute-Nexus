<?php
/**
 * 计划任务统一入口
 *
 * 不传 job  ：依次执行 实时检测 → 轮换 → （每天首次）日志清理与备份
 * 传 job    ：只执行指定的一个任务
 *
 * CLI 用法：
 *   php task.php              执行全部
 *   php task.php check        只执行实时检测
 *
 * URL 用法（宝塔「访问 URL」类型计划任务，每 5 分钟一次）：
 *   /task.php?token=配置文件中 app.cron_token 的值&job=check
 *   /task.php?token=...&job=rotate
 *   /task.php?token=...&job=cleanup
 */
require_once __DIR__ . '/app/bootstrap.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $token = (string) ($_GET['token'] ?? '');
    $key   = (string) Config::get('app.cron_token', '');
    if ($key === '' || !hash_equals($key, $token)) {
        http_response_code(403);
        exit("403 Forbidden：token 无效\n");
    }
}

// 指定任务：check / rotate / cleanup，留空表示全部
$job = strtolower(trim((string) ($_GET['job'] ?? '')));
if ($job === '' && $isCli && !empty($argv[1])) {
    $job = strtolower(trim((string) $argv[1]));
}
if (!in_array($job, ['check', 'rotate', 'cleanup'], true)) {
    $job = '';
}

$start  = microtime(true);
$output = static function (string $line) use ($isCli): void {
    echo $line, PHP_EOL;
    if (!$isCli) {
        @ob_flush();
        @flush();
    }
};

$output('[' . date('Y-m-d H:i:s') . '] 计划任务开始' . ($job !== '' ? '（仅 ' . $job . '）' : ''));

$enabled = static function (string $code): bool {
    try {
        $row = Database::one('SELECT `enabled` FROM `tasks` WHERE `code`=?', [$code]);
        return !$row || (int) $row['enabled'] === 1;
    } catch (Throwable $e) {
        // 数据库暂时不可用时不阻断，交给具体任务去报错
        return true;
    }
};

// ---------- 1. 实时检测 · 故障即换 ----------
if ($job === '' || $job === 'check') {
    if ($enabled('check')) {
        $r = TaskRunner::run('check');
        $output(sprintf('  · 实时检测：%s | %s（%d ms）', $r['result'], $r['message'], $r['ms']));
        foreach ($r['events'] as $ev) {
            $output('      - ' . ($ev['title'] ?? ''));
        }
    } else {
        $output('  · 实时检测：已停用，跳过');
    }
}

// ---------- 2. 轮换 · 观察期 · 禁用 ----------
if ($job === '' || $job === 'rotate') {
    if ($enabled('rotate')) {
        $r = TaskRunner::run('rotate');
        $output(sprintf('  · 轮换任务：%s | %s（%d ms）', $r['result'], $r['message'], $r['ms']));
        foreach ($r['events'] as $ev) {
            $output('      - ' . ($ev['title'] ?? ''));
        }
    } else {
        $output('  · 轮换任务：已停用，跳过');
    }
}

// ---------- 3. 日志清理与备份（每天首次执行） ----------
if ($job === '' || $job === 'cleanup') {
    if ($enabled('cleanup')) {
        $lastRun = '';
        try {
            $lastRun = (string) Database::value('SELECT `last_run_at` FROM `tasks` WHERE `code`=?', ['cleanup']);
        } catch (Throwable $e) {
            $lastRun = '';
        }
        if ($job !== 'cleanup' && $lastRun !== '' && substr($lastRun, 0, 10) === date('Y-m-d')) {
            $output('  · 日志清理与备份：今日已执行，跳过');
        } else {
            $r = TaskRunner::run('cleanup');
            $output(sprintf('  · 日志清理与备份：%s | %s（%d ms）', $r['result'], $r['message'], $r['ms']));
        }
    } else {
        $output('  · 日志清理与备份：已停用，跳过');
    }
}

$output(sprintf('计划任务结束，总耗时 %d ms', (int) round((microtime(true) - $start) * 1000)));
