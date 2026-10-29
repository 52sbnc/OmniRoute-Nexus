<?php
/**
 * 控制台首页（天枢模板）
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

// ---------------- 手动操作 ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) post('action');

    if ($action === 'check') {
        $res = DomainService::checkAndFailover();
        Notifier::dispatch($res['events']);
        audit('手动检测', sprintf('检测 %d 个域名，发现 %d 个异常', $res['checked'], $res['failed']));
        flash($res['failed'] > 0
            ? sprintf('检测完成：共 %d 个域名，%d 个异常已自动替换', $res['checked'], $res['failed'])
            : sprintf('检测完成：共 %d 个域名，全部正常', $res['checked']),
            $res['failed'] > 0 ? 'warn' : 'success');
        redirect(app_base_path() . '/admin/index.php');
    }

    if ($action === 'rotate') {
        $events = DomainService::rotate();
        Notifier::dispatch($events);
        audit('手动轮换', '执行轮换与状态升级');
        flash('轮换任务已执行，共产生 ' . count($events) . ' 条状态变更。');
        redirect(app_base_path() . '/admin/index.php');
    }

    if ($action === 'rebuild') {
        DomainService::rebuild();
        audit('刷新缓存', '重建 Redis 跳转路由快照');
        flash(Cache::usingRedis() ? '跳转路由快照已写入 Redis。' : 'Redis 未连接，快照重建失败。',
            Cache::usingRedis() ? 'success' : 'danger');
        redirect(app_base_path() . '/admin/index.php');
    }
}

$base   = app_base_path();
$config = Config::settings();
$layers = DomainService::layers();

// ---------------- 数据聚合 ----------------
$allDomains = Database::all('SELECT * FROM `domains` ORDER BY `layer_id` ASC, `sort` ASC, `id` ASC');
$byLayer    = [];
foreach ($allDomains as $d) {
    $byLayer[(int) $d['layer_id']][] = $d;
}

$stats = DomainService::stats();
$channelStats = DomainService::channelStats();

// 入口层当前正在推广的域名
$entryActive = [];
foreach ($layers as $layer) {
    if ((int) $layer['id'] === 1) {
        $entryActive = DomainService::active(1);
        break;
    }
}

// 计划任务运行状态
$checkTask = Database::one('SELECT * FROM `tasks` WHERE `code`=?', ['check']);
$lastRunTs = $checkTask && $checkTask['last_run_at'] ? strtotime((string) $checkTask['last_run_at']) : 0;
$interval  = 300;
$nextRunTs = $lastRunTs ? $lastRunTs + $interval : 0;
$now       = time();

// 事件流
$events = Database::all('SELECT * FROM `domain_logs` ORDER BY `id` DESC LIMIT 8');

/** 域名卡片样式与标签 */
function dash_card(string $status, int $layerId): array
{
    switch ($status) {
        case 'active':
            return $layerId === 3
                ? ['live', 'live', '在线']
                : ['main', 'main', $layerId === 2 ? '主用' : '使用中'];
        case 'observing':
            return ['standby', 'standby', '已到期'];
        case 'pending':
            return ['standby', 'standby', '待使用'];
        case 'standby':
            return ['standby', 'standby', '备用'];
        case 'review':
            return ['standby', 'standby', '待处理'];
        case 'disabled':
            return ['bad', 'bad', '已禁用'];
        case 'failed':
            return ['bad', 'bad', '检测失败'];
    }
    return ['standby', 'standby', $status];
}

/** 检测信息：展示 DNS 解析结果 */
function dash_check(array $d): array
{
    $ip = trim((string) ($d['resolved_ip'] ?? ''));
    if (($d['last_check_result'] ?? '') === 'ok') {
        return ['check', $ip !== '' ? $ip : '解析正常', human_time((string) $d['last_check_at'])];
    }
    if (($d['last_check_result'] ?? '') === 'fail') {
        return ['check bad', '解析失败', human_time((string) $d['last_check_at'])];
    }
    return ['check none', '未检测', ''];
}

/** 域名生命周期/响应文案 */
function dash_meta(array $d, int $layerId): string
{
    $life = DomainService::lifeText($d);
    if ($life !== '') {
        return $life;
    }

    $status = (string) $d['status'];
    if ((int) $d['response_ms'] > 0 && ($d['last_check_result'] ?? '') === 'fail') {
        return '解析耗时 ' . (int) $d['response_ms'] . 'ms';
    }

    switch ($status) {
        case 'active':
            return '运行中';
        case 'observing':
            return '已到期，不再对外服务';
        case 'review':
            return '待人工处理';
        case 'pending':
            return '未启用';
        case 'failed':
            return '检测失败，已停止服务';
    }
    return '待命';
}

$lvMap = [
    'failed'    => ['err', '失败'],
    'disabled'  => ['err', '禁用'],
    'observing' => ['warn', '轮换'],
    'active'    => ['ok', '启用'],
    'pending'   => ['info', '恢复'],
];

admin_header('', 'dash');
?>
    <!-- ==================== 统计栏 ==================== -->
    <section class="stats">
        <div class="stat">
            <div class="label">今日跳转量</div>
            <div class="value"><?= number_format((int) $stats['jumps_today']) ?><small>次</small></div>
            <div class="meta">昨日 <?= number_format((int) $stats['jumps_yesterday']) ?> · 总数 <?= number_format((int) $stats['total_jumps']) ?></div>
        </div>
        <div class="stat">
            <div class="label">今日访客数量</div>
            <div class="value"><?= number_format((int) $stats['visitors_today']) ?><small>IP</small></div>
            <div class="meta">昨日 <?= number_format((int) $stats['visitors_yesterday']) ?> · 总数 <?= number_format((int) $stats['total_visitors']) ?></div>
        </div>
        <div class="stat">
            <div class="label">今日跳转次数</div>
            <div class="value"><?= number_format((int) $stats['flows_today']) ?><small>次</small></div>
            <div class="meta"><span class="up">● 一次访问只记一次</span> · 昨日 <?= number_format((int) $stats['flows_yesterday']) ?> · 总数 <?= number_format((int) $stats['total_flows']) ?></div>
        </div>
        <div class="stat">
            <div class="label">跳转次数 / 访客IP</div>
            <div class="value"><?= number_format((int) $stats['flows_today']) ?> / <?= number_format((int) $stats['visitors_today']) ?></div>
            <div class="meta"><span class="warn">● 今日</span> 跳转次数与访客IP数量</div>
        </div>

        <div class="stat">
            <div class="label">在线域名</div>
            <div class="value"><?= (int) $stats['online_total'] ?><small>个</small></div>
            <div class="meta">
                <?php if ($entryActive): ?>
                    <span class="warn">● 入口层 <?= count($entryActive) ?> 个正在推广</span>
                <?php else: ?>
                    <span class="down">● 入口层无可用域名</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="stat">
            <div class="label">今日告警</div>
            <div class="value"><?= (int) $stats['alerts_today'] ?><small>条</small></div>
            <div class="meta">
                <?php if ($stats['alerts_today'] > 0): ?>
                    <span class="down">● 已自动替换处理</span>
                <?php else: ?>
                    <span class="up">● 无异常告警</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="stat">
            <div class="label">总跳转量</div>
            <div class="value"><?= number_format((int) $stats['total_jumps']) ?><small>次</small></div>
            <div class="meta"><span class="up">● 日志清理后仍保留</span></div>
        </div>
        <div class="stat">
            <div class="label">总访客数量</div>
            <div class="value"><?= number_format((int) $stats['total_visitors']) ?><small>IP</small></div>
            <div class="meta"><span class="up">● 每日独立 IP 相加</span></div>
        </div>
    </section>

    <!-- ==================== 渠道统计 ==================== -->
    <div class="panel" style="margin-bottom:24px">
        <div class="panel-head">
            <div class="panel-title">渠道统计</div>
            <a class="sec-action" href="<?= e($base) ?>/admin/templates.php">模板与渠道 →</a>
        </div>
        <?php if (!$channelStats): ?>
            <p class="muted" style="padding:6px 0">
                还没有渠道。先到「模板 → 渠道设置」建立渠道（如谷歌 / Facebook），再把模板归属到对应渠道；入口层域名用哪个模板就走哪个渠道。
            </p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>渠道</th>
                        <th>今日跳转次数</th><th>今日访客</th>
                        <th>昨日跳转次数</th><th>昨日访客</th>
                        <th>总跳转次数</th><th>总访客</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($channelStats as $c): ?>
                        <tr>
                            <td><b><?= e($c['name']) ?></b></td>
                            <td><?= number_format((int) $c['today_flows']) ?></td>
                            <td><?= number_format((int) $c['today_visitors']) ?></td>
                            <td class="muted"><?= number_format((int) $c['yesterday_flows']) ?></td>
                            <td class="muted"><?= number_format((int) $c['yesterday_visitors']) ?></td>
                            <td><?= number_format((int) $c['total_flows']) ?></td>
                            <td><?= number_format((int) $c['total_visitors']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="muted" style="font-size:12px;margin-top:8px">
                跳转次数按「一次访问只记一次」统计（同一次访问经过第 1/2/3 层只计 1 次）；访客按 IP 去重。
            </p>
        <?php endif; ?>
    </div>

    <!-- ==================== 计划任务横幅 ==================== -->
    <div class="task-banner">
        <div class="task-icon">⟳</div>
        <div class="task-info">
            <div class="task-name">
                task.php
                <span class="chip">CRON · 5 分钟</span>
                <?php if ($checkTask && $checkTask['last_result'] === 'fail'): ?>
                    <span class="chip bad">上次执行异常</span>
                <?php endif; ?>
            </div>
            <div class="task-sub">
                <?= e((string) ($checkTask['last_message'] ?? '') ?: '定时核查所有正在使用的域名是否失效，异常立即替换并推送通知') ?>
            </div>
        </div>
        <div class="task-right">
            <span class="pulse<?= $lastRunTs ? '' : ' stop' ?>"></span>
            <?php if ($lastRunTs): ?>
                最近执行 <?= e(human_time((string) $checkTask['last_run_at'])) ?> ·
                <?= $nextRunTs > $now ? '下次执行 ' . e(human_duration($nextRunTs - $now)) . '后' : '等待下次调度' ?>
            <?php else: ?>
                尚未执行，请在服务器配置计划任务每 5 分钟调用 task.php
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================== 实时导流链路 ==================== -->
    <div class="sec-head">
        <div class="sec-title">实时导流链路 <span class="sub">从左到右 · <?= count($layers) ?> 层跳转拓扑</span></div>
        <div class="sec-actions">
            <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="check">
                <button class="sec-action primary" type="submit">立即检测</button>
            </form>
            <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="rotate">
                <button class="sec-action" type="submit">执行轮换</button>
            </form>
            <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="rebuild">
                <button class="sec-action" type="submit">重建缓存</button>
            </form>
            <a class="sec-action" href="<?= e($base) ?>/admin/logs.php">查看详细日志 →</a>
        </div>
    </div>

    <div class="flow-panel">
        <div class="flow">
            <?php $first = true; foreach ($layers as $layer): ?>
                <?php
                    $lid     = (int) $layer['id'];
                    $isFinal = (int) $layer['is_final'] === 1;
                    $list    = $byLayer[$lid] ?? [];

                    // 排序：正在使用 > 观察期 > 待使用 > 备用 > 其它
                    $order = ['active' => 1, 'observing' => 2, 'pending' => 3, 'standby' => 4, 'disabled' => 7, 'failed' => 8];
                    usort($list, static function ($a, $b) use ($order) {
                        $pa = $order[$a['status']] ?? 5;
                        $pb = $order[$b['status']] ?? 5;
                        return $pa === $pb ? ((int) $a['sort'] <=> (int) $b['sort']) : ($pa <=> $pb);
                    });
                    $show    = array_slice($list, 0, 4);
                    $activeN = count(array_filter($list, static fn($d) => $d['status'] === 'active'));

                    if ($isFinal) {
                        $badge = '仅 1 个 URL';
                        $note  = '不绑定域名 · 全局唯一配置点 · 修改即生效';
                    } elseif ($lid === 1) {
                        $badge = $activeN . ' 个使用中';
                        $note  = '海报模板展示 · 不限在线数量 · 每个域名可独立启用 / 关闭 · 用哪个模板就走哪个渠道';
                    } elseif ($lid === 2) {
                        $badge = '主备双活';
                        $note  = '主用故障自动切备用 · 5 分钟巡检 · 切换对用户无感';
                    } else {
                        $badge = $activeN . ' / ' . (int) $layer['active_count'] . ' 在线';
                        $note  = '同时 ' . (int) $layer['active_count'] . ' 个在线 · '
                               . ['fixed' => '固定', 'random' => '随机', 'weight' => '权重', 'poll' => '轮询'][$layer['strategy']] . '调度 · 故障自动替换';
                    }
                ?>
                <?php if (!$first): ?><div class="conn"></div><?php endif; $first = false; ?>

                <div class="layer">
                    <div class="layer-head">
                        <div class="layer-title">
                            <span class="layer-num"><?= str_pad((string) $lid, 2, '0', STR_PAD_LEFT) ?></span>
                            <span class="layer-name"><?= e(preg_replace('/^第 \d+ 层 · /u', '', (string) $layer['name'])) ?></span>
                        </div>
                        <span class="layer-badge"><?= e($badge) ?></span>
                    </div>
                    <div class="domains">
                        <?php if ($isFinal): ?>
                            <div class="url-card">
                                <div class="url-tag">Target</div>
                                <div class="url-text"><?= e($config['final_url']) ?: '未配置最终落地 URL' ?></div>
                            </div>
                        <?php elseif (!$show): ?>
                            <div class="dempty">暂无域名，请到「域名池」添加</div>
                        <?php else: ?>
                            <?php foreach ($show as $d): ?>
                                <?php
                                    [$cardCls, $tagCls, $tagTxt] = dash_card((string) $d['status'], $lid);
                                    [$chkCls, $chkTxt, $chkTime]  = dash_check($d);
                                ?>
                                <div class="dcard <?= $cardCls ?>">
                                    <span class="dot"></span>
                                    <div class="dmain">
                                        <div class="dtop">
                                            <span class="dname"><?= e($d['domain']) ?></span>
                                            <span class="dtag <?= $tagCls ?>"><?= e($tagTxt) ?></span>
                                        </div>
                                        <div class="dmeta">
                                            <span><?= e(dash_meta($d, $lid)) ?></span>
                                            <span class="sep">·</span>
                                            <span class="<?= $chkCls ?>"><?= e($chkTxt) ?><?= $chkTime ? ' ' . e($chkTime) : '' ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($list) > 4): ?>
                                <div class="dempty">另有 <?= count($list) - 4 ?> 个域名未展示</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="layer-note"><?= e($note) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ==================== 底部两栏 ==================== -->
    <div class="bottom">

        <div class="panel">
            <div class="panel-head">
                <div class="panel-title">实时事件流</div>
                <a class="sec-action" href="<?= e($base) ?>/admin/logs.php?tab=domain">全部日志</a>
            </div>
            <div class="logs">
                <?php if (!$events): ?>
                    <div class="log-empty">暂无状态变更记录</div>
                <?php else: ?>
                    <?php foreach ($events as $lg): ?>
                        <?php
                            $to    = (string) $lg['to_status'];
                            [$lvCls, $lvTxt] = $lvMap[$to] ?? ['info', '信息'];
                            $dotCls = in_array($to, ['active'], true) ? 'g' : (in_array($to, ['failed', 'disabled'], true) ? 'r' : 'a');
                        ?>
                        <div class="log">
                            <span class="time"><?= e(date('H:i:s', strtotime((string) $lg['created_at']))) ?></span>
                            <span class="txt">
                                第 <?= (int) $lg['layer_id'] ?> 层 <b><?= e($lg['domain']) ?></b>
                                <?= e((string) $lg['message'] ?: (string) $lg['action']) ?>
                                <span class="<?= $dotCls ?>"><?= e(status_label($to, (int) $lg['layer_id'])) ?></span>
                            </span>
                            <span class="lv <?= $lvCls ?>"><?= e($lvTxt) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div class="panel-title">各层健康度</div>
                <a class="sec-action" href="<?= e($base) ?>/admin/domains.php">详情</a>
            </div>
            <div class="health-list">
                <?php foreach ($layers as $layer): ?>
                    <?php
                        $lid     = (int) $layer['id'];
                        $isFinal = (int) $layer['is_final'] === 1;
                        $list    = $byLayer[$lid] ?? [];

                        if ($isFinal) {
                            $pct = trim((string) $config['final_url']) !== '' ? 100 : 0;
                            $sub = $pct === 100 ? 'URL 已配置 · 唯一配置点' : '尚未配置最终落地 URL';
                        } else {
                            $total = count($list);
                            $bad   = count(array_filter($list, static fn($d) => $d['status'] === 'failed'));
                            $act   = count(array_filter($list, static fn($d) => $d['status'] === 'active'));
                            $pct   = $total > 0 ? (int) round(($total - $bad) / $total * 100) : 100;
                            $sub   = $total === 0
                                ? '暂无域名'
                                : ($bad > 0
                                    ? $act . ' 个在用 · ' . $bad . ' 个异常'
                                    : $act . ' 个在用 · 检测正常');
                        }
                    ?>
                    <div class="hitem">
                        <div class="hicon"><?= str_pad((string) $lid, 2, '0', STR_PAD_LEFT) ?></div>
                        <div class="hinfo">
                            <div class="hn"><?= e(preg_replace('/^第 \d+ 层 · /u', '', (string) $layer['name'])) ?></div>
                            <div class="hs"><?= e($sub) ?></div>
                        </div>
                        <div class="hbar"><i class="<?= $pct < 80 ? 'low' : '' ?>" style="width:<?= $pct ?>%"></i></div>
                        <span class="hpct"><?= $pct ?>%</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

<?php admin_footer(); ?>
