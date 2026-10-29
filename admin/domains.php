<?php
/**
 * 域名池管理（左 → 右 池子看板）
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$layers  = DomainService::layers();
$pools   = DomainService::pools();
$templates = DomainService::templates();
$layerOf = static fn(int $id) => DomainService::layer($id);

// 渠道：由域名所用模板决定（用哪个模板就属于哪个渠道）
$tplChannel  = [];
$channelName = [];
foreach ($templates as $t) {
    $tplChannel[(int) $t['id']] = (int) ($t['channel_id'] ?? 0);
}
foreach (DomainService::channels() as $cid => $c) {
    $channelName[$cid] = (string) $c['name'];
}

/** 手工把某个域名设为“正在使用”，并把超出的域名降级 */
function manual_activate(int $id): void
{
    $domain = DomainService::find($id);
    if (!$domain) {
        return;
    }
    $layerId = (int) $domain['layer_id'];
    $layer   = DomainService::layer($layerId);
    if (!$layer || (int) $layer['is_final'] === 1) {
        return;
    }
    if ((string) $domain['status'] === 'active') {
        return;
    }
    DomainService::activate($domain, '手工切换', '管理员手工设为正在使用');

    // 入口层不限制在线数量：每个域名独立启用/关闭，不做降级
    if ($layerId === 1) {
        DomainService::rebuild();
        return;
    }

    $limit    = max(1, (int) $layer['active_count']);
    $active   = DomainService::active($layerId);
    $demoteTo = 'standby';
    if (count($active) > $limit) {
        usort($active, static function ($a, $b) {
            return strtotime((string) ($a['current_active_at'] ?? 'now')) <=> strtotime((string) ($b['current_active_at'] ?? 'now'));
        });
        foreach (array_slice($active, 0, count($active) - $limit) as $d) {
            if ((int) $d['id'] === $id) {
                continue;
            }
            DomainService::accumulate($d);
            DomainService::setStatus((int) $d['id'], $demoteTo, '手工切换', '被管理员手工切换下线');
        }
    }
    DomainService::rebuild();
}

// ---------------- 提交处理 ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) post('action');

    switch ($action) {
        case 'add':
        case 'edit':
            $id      = (int) post('id');
            $raw     = (string) post('domain');
            $domain  = normalize_domain($raw);
            $layerId = (int) post('layer_id');
            $poolId  = (int) post('pool_id');
            $weight  = max(1, (int) post('weight', 100));
            $sort    = (int) post('sort', 0);
            $role    = (string) post('role', '');
            $remark  = (string) post('remark');
            $status  = (string) post('status');
            $tplId   = (int) post('template_id', 0);

            if (!is_valid_domain($domain)) {
                flash('域名格式不正确：' . $raw, 'danger');
                redirect('domains.php');
            }
            if (!$layerOf($layerId)) {
                flash('请选择有效的层级。', 'danger');
                redirect('domains.php');
            }
            $exists = Database::one('SELECT `id` FROM `domains` WHERE `domain`=?', [$domain]);
            if ($exists && (int) $exists['id'] !== $id) {
                flash('域名已存在：' . $domain, 'danger');
                redirect('domains.php');
            }

            $data = [
                'domain'     => $domain,
                'layer_id'   => $layerId,
                'pool_id'    => $poolId,
                'weight'     => $weight,
                'sort'       => $sort,
                'role'       => in_array($role, ['main', 'backup'], true) ? $role : '',
                'template_id'=> $tplId,
                'remark'     => mb_substr($remark, 0, 250),
                'updated_at' => Database::now(),
            ];

            if ($action === 'add') {
                $defaultStatus      = $layerId === 2 ? 'standby' : 'pending';
                $data['status']     = in_array($status, ['pending', 'standby', 'active'], true) ? $status : $defaultStatus;
                $data['created_at'] = Database::now();
                $newId = Database::insert('domains', $data);
                DomainService::logChange($newId, $domain, $layerId, '', (string) $data['status'], '新增', '后台添加域名');
                audit('新增域名', $domain . '（层级 ' . $layerId . '）');
                flash('域名已添加：' . $domain);
            } else {
                $before = DomainService::find($id);
                if (in_array($status, ['pending', 'active', 'observing', 'review', 'standby', 'disabled', 'failed'], true)) {
                    $data['status'] = $status;
                }
                Database::update('domains', $data, '`id`=?', [$id]);
                if ($before && isset($data['status']) && $before['status'] !== $data['status']) {
                    DomainService::logChange($id, $domain, $layerId, (string) $before['status'], (string) $data['status'], '编辑', '后台修改状态');
                }
                audit('编辑域名', $domain);
                flash('域名已更新：' . $domain);
            }
            DomainService::rebuild();
            redirect('domains.php');

        case 'import':
            $layerId = (int) post('layer_id');
            $poolId  = (int) post('pool_id');
            $text    = (string) post('domains');
            if (!$layerOf($layerId) || trim($text) === '') {
                flash('请选择层级并填写域名列表。', 'danger');
                redirect('domains.php');
            }
            $ok = 0;
            $skip = 0;
            foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $parts  = preg_split('/[\s,，]+/', $line, 2) ?: [];
                $domain = normalize_domain((string) $parts[0]);
                $remark = isset($parts[1]) ? trim((string) $parts[1]) : '';
                if (!is_valid_domain($domain) || Database::one('SELECT `id` FROM `domains` WHERE `domain`=?', [$domain])) {
                    $skip++;
                    continue;
                }
                $id = Database::insert('domains', [
                    'domain'     => $domain,
                    'layer_id'   => $layerId,
                    'pool_id'    => $poolId,
                    'status'     => $layerId === 2 ? 'standby' : 'pending',
                    'weight'     => 100,
                    'remark'     => mb_substr($remark, 0, 250),
                    'created_at' => Database::now(),
                    'updated_at' => Database::now(),
                ]);
                DomainService::logChange($id, $domain, $layerId, '', $layerId === 2 ? 'standby' : 'pending', '批量导入', '后台批量导入');
                $ok++;
            }
            audit('批量导入域名', sprintf('成功 %d 个，跳过 %d 个', $ok, $skip));
            flash(sprintf('批量导入完成：成功 %d 个，跳过 %d 个（重复或格式错误）。', $ok, $skip));
            DomainService::rebuild();
            redirect('domains.php');

        case 'delete':
            $ids = array_filter(array_map('intval', (array) post('ids', [])));
            if (!$ids) {
                flash('请先勾选要删除的域名。', 'warn');
                redirect('domains.php');
            }
            $ph   = implode(',', array_fill(0, count($ids), '?'));
            $rows = Database::all("SELECT * FROM `domains` WHERE `id` IN ($ph)", $ids);
            Database::exec("DELETE FROM `domains` WHERE `id` IN ($ph)", $ids);
            foreach ($rows as $r) {
                DomainService::logChange((int) $r['id'], (string) $r['domain'], (int) $r['layer_id'], (string) $r['status'], 'deleted', '删除', '后台删除域名');
            }
            audit('删除域名', '共 ' . count($ids) . ' 个');
            flash('已删除 ' . count($ids) . ' 个域名。');
            foreach (DomainService::layers() as $l) {
                DomainService::ensureActive((int) $l['id'], false);
            }
            DomainService::rebuild();
            redirect('domains.php');

        case 'set_status':
            $id     = (int) post('id');
            $status = (string) post('status');
            if (!in_array($status, ['pending', 'active', 'observing', 'standby', 'disabled', 'failed'], true)) {
                redirect('domains.php');
            }
            if ($status === 'active') {
                manual_activate($id);
            } else {
                DomainService::setStatus($id, $status, '手工调整', '管理员手工修改状态');
                DomainService::rebuild();
                foreach (DomainService::layers() as $l) {
                    DomainService::ensureActive((int) $l['id'], false);
                }
            }
            audit('修改域名状态', 'ID ' . $id . ' → ' . $status);
            flash('域名状态已更新。');
            redirect('domains.php');

        case 'reset_failed':
            $ids = array_filter(array_map('intval', (array) post('ids', [])));
            if (!$ids) {
                flash('请先勾选要恢复的域名。', 'warn');
                redirect('domains.php');
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach (Database::all("SELECT * FROM `domains` WHERE `id` IN ($ph)", $ids) as $r) {
                DomainService::setStatus((int) $r['id'], 'pending', '人工恢复', '管理员将故障域名恢复为待使用');
            }
            audit('恢复故障域名', '共 ' . count($ids) . ' 个');
            flash('已恢复 ' . count($ids) . ' 个域名。');
            DomainService::rebuild();
            redirect('domains.php');

        case 'check_one':
            $id     = (int) post('id');
            $domain = DomainService::find($id);
            if ($domain) {
                $res = Detector::check($domain);
                Database::update('domains', [
                    'last_check_at'     => Database::now(),
                    'last_check_result' => $res['ok'] ? 'ok' : 'fail',
                    'last_check_msg'    => mb_substr($res['message'], 0, 250),
                    'resolved_ip'       => $res['resolved_ip'],
                    'response_ms'       => $res['response_ms'],
                    'updated_at'        => Database::now(),
                ], '`id`=?', [$id]);
                flash('DNS 检测：' . ($res['ok'] ? '解析正常 · ' . $res['resolved_ip'] : '解析失败'), $res['ok'] ? 'success' : 'danger');
            }
            redirect('domains.php');
    }
}

// ---------------- 数据准备 ----------------
$statusFilter = (string) get_param('status');
$kw           = (string) get_param('kw');

$where  = [];
$params = [];
if ($statusFilter !== '') {
    $where[]  = '`status`=?';
    $params[] = $statusFilter;
}
if ($kw !== '') {
    $where[]  = '(`domain` LIKE ? OR `remark` LIKE ?)';
    $params[] = '%' . $kw . '%';
    $params[] = '%' . $kw . '%';
}
$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$list  = Database::all("SELECT * FROM `domains` $sqlWhere ORDER BY `layer_id` ASC, `sort` ASC, `id` ASC", $params);
$total = (int) Database::value("SELECT COUNT(*) FROM `domains` $sqlWhere", $params);

// 按池子分组
$poolMap  = [];
foreach ($pools as $p) {
    $poolMap[(int) $p['id']] = $p;
}
$byPool    = [];
$ungrouped = [];
foreach ($list as $d) {
    $pid = (int) $d['pool_id'];
    if ($pid > 0 && isset($poolMap[$pid])) {
        $byPool[$pid][] = $d;
    } else {
        $ungrouped[] = $d;
    }
}

// 池子按层级排序
usort($pools, static function ($a, $b) {
    $sa = (int) (DomainService::layer((int) $a['layer_id'])['sort'] ?? 99);
    $sb = (int) (DomainService::layer((int) $b['layer_id'])['sort'] ?? 99);
    return $sa === $sb ? ((int) $a['id'] <=> (int) $b['id']) : ($sa <=> $sb);
});

$statusOptions = [
    'pending'   => '待使用',
    'active'    => '正在使用',
    'observing' => '已到期',
    'review'    => '待处理',
    'standby'   => '备用',
    'disabled'  => '已禁用',
    'failed'    => '检测失败',
];
$strategyLabel = ['fixed' => '固定', 'random' => '随机', 'weight' => '权重', 'poll' => '轮询'];
$maxPerColumn  = 60;

/** 卡片状态样式 */
function board_card(string $status, int $layerId): array
{
    switch ($status) {
        case 'active':
            return $layerId === 3
                ? ['live', 'live', '在线']
                : ['main', 'main', $layerId === 2 ? '主用' : '使用中'];
        case 'observing':
            return ['', 'standby', '已到期'];
        case 'pending':
            return ['', 'standby', '待使用'];
        case 'standby':
            return ['', 'standby', '备用'];
        case 'review':
            return ['', 'standby', '待处理'];
        case 'disabled':
            return ['bad', 'bad', '已禁用'];
        case 'failed':
            return ['bad', 'bad', '检测失败'];
    }
    return ['', 'standby', $status];
}

/** 卡片副信息 */
function board_meta(array $d, int $layerId): string
{
    // 第 1 层：使用期倒计时 / 转待处理倒计时
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

/** 卡片检测信息：展示 DNS 解析结果 */
function board_check(array $d): array
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

$q = array_filter(['status' => $statusFilter, 'kw' => $kw], static fn($v) => $v !== '');

admin_header('域名池管理', 'domains', '从左到右依次为各层域名池，卡片即域名，悬停可执行检测、切换与编辑。');
?>

<div class="sec-head">
  <div class="sec-title">
    域名池总览
    <span class="sub">共 <?= $total ?> 个域名 · <?= count($pools) ?> 个池子</span>
  </div>
  <div class="sec-actions">
    <form method="get" class="filter" style="margin:0">
      <select name="status">
        <option value="">全部状态</option>
        <?php foreach ($statusOptions as $k => $v): ?>
          <option value="<?= $k ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="kw" value="<?= e($kw) ?>" placeholder="搜索域名 / 备注">
      <button class="sec-action" type="submit">筛选</button>
      <?php if ($q): ?><a class="sec-action" href="domains.php">重置</a><?php endif; ?>
    </form>
    <button class="sec-action primary" type="button" onclick="openAdd(0, 0)">+ 添加域名</button>
    <button class="sec-action" type="button" onclick="openImport()">批量导入</button>
  </div>
</div>

<form id="bulkForm" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete" id="bulkAction">

  <div class="poolboard">
    <?php foreach ($pools as $pool): ?>
      <?php
        $pid     = (int) $pool['id'];
        $layerId = (int) $pool['layer_id'];
        $layer   = $layerOf($layerId);
        $items   = $byPool[$pid] ?? [];
        $activeN = count(array_filter($items, static fn($d) => $d['status'] === 'active'));
        $failN   = count(array_filter($items, static fn($d) => $d['status'] === 'failed'));
        $need    = max(1, (int) ($layer['active_count'] ?? 1));
        $shown   = array_slice($items, 0, $maxPerColumn);
        $isFinal = (int) ($layer['is_final'] ?? 0) === 1;
      ?>
      <div class="pool-col">
        <div class="pool-head">
          <div class="pool-title">
            <span class="layer-num"><?= str_pad((string) $layerId, 2, '0', STR_PAD_LEFT) ?></span>
            <span class="pool-name"><?= e($pool['name']) ?></span>
          </div>
          <span class="pool-badge"><?= count($items) ?> 个域名</span>
        </div>
        <div class="pool-meta">
          <span><?= e(preg_replace('/^第 \d+ 层 · /u', '', (string) ($layer['name'] ?? '未绑定层级'))) ?></span>
          <span class="sep">·</span>
          <span>在线 <?= $activeN ?> / <?= $need ?></span>
          <?php if ($failN > 0): ?>
            <span class="sep">·</span><span style="color:var(--red)">异常 <?= $failN ?></span>
          <?php endif; ?>
          <span class="sep">·</span>
          <span><?= e($strategyLabel[$pool['strategy']] ?? $pool['strategy']) ?>调度</span>
        </div>

        <div class="pool-list">
          <?php if (!$shown): ?>
            <div class="pool-empty">此池暂无域名</div>
          <?php endif; ?>
          <?php foreach ($shown as $d): ?>
            <?php
              [$cardCls, $tagCls, $tagTxt] = board_card((string) $d['status'], $layerId);
              [$chkCls, $chkTxt, $chkTime] = board_check($d);
              $chId    = $tplChannel[(int) $d['template_id']] ?? 0;
              $chLabel = $chId > 0 ? ($channelName[$chId] ?? '') : '';
            ?>
            <div class="pcard <?= $cardCls ?>">
              <div class="pcard-top">
                <span class="pdot"></span>
                <div class="pcard-body">
                  <div class="dtop">
                    <span class="dname"><?= e($d['domain']) ?></span>
                    <span class="dtag <?= $tagCls ?>"><?= e($tagTxt) ?></span>
                  </div>
                  <div class="dmeta">
                    <span><?= e(board_meta($d, $layerId)) ?></span>
                    <?php if ($chLabel !== ''): ?>
                      <span class="sep">·</span><span>渠道：<?= e($chLabel) ?></span>
                    <?php endif; ?>
                    <span class="sep">·</span>
                    <span class="<?= $chkCls ?>"><?= e($chkTxt) ?><?= $chkTime ? ' ' . e($chkTime) : '' ?></span>
                    <?php if ((int) $d['weight'] !== 100 && $pool['strategy'] === 'weight'): ?>
                      <span class="sep">·</span><span>权重 <?= (int) $d['weight'] ?></span>
                    <?php endif; ?>
                  </div>
                  <?php if (trim((string) $d['remark']) !== ''): ?>
                    <div class="pcard-note"><?= e((string) $d['remark']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
              <div class="pcard-acts">
                <button class="pact" type="button" onclick="act('check_one', [{k:'id',v:<?= (int) $d['id'] ?>}])">检测</button>
                <button class="pact" type="button"
                  onclick='openEdit(<?= json_encode($d, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>编辑</button>
                <?php if ($layerId === 1): ?>
                  <?php if ($d['status'] === 'active'): ?>
                    <button class="pact" type="button"
                      onclick="act('set_status', [{k:'id',v:<?= (int) $d['id'] ?>},{k:'status',v:'disabled'}])">关闭</button>
                  <?php else: ?>
                    <button class="pact" type="button"
                      onclick="act('set_status', [{k:'id',v:<?= (int) $d['id'] ?>},{k:'status',v:'active'}])">启用</button>
                  <?php endif; ?>
                <?php elseif ($d['status'] !== 'active' && !$isFinal): ?>
                  <button class="pact" type="button"
                    onclick="act('set_status', [{k:'id',v:<?= (int) $d['id'] ?>},{k:'status',v:'active'}])">设为使用</button>
                <?php endif; ?>
                <?php if ($d['status'] === 'failed'): ?>
                  <button class="pact" type="button"
                    onclick="act('reset_failed', [{k:'ids[]',v:<?= (int) $d['id'] ?>}])">恢复</button>
                <?php endif; ?>
                <button class="pact danger" type="button"
                  onclick="act('delete', [{k:'ids[]',v:<?= (int) $d['id'] ?>}], '确定删除该域名？')">删除</button>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (count($items) > $maxPerColumn): ?>
            <div class="pool-empty">另有 <?= count($items) - $maxPerColumn ?> 个域名未显示，请用筛选缩小范围</div>
          <?php endif; ?>
        </div>

        <div class="pool-foot">
          <span class="muted" style="font-size:11px">
            <?= $isFinal ? '最终层不参与检测' : '在线 ' . $activeN . ' / ' . $need . ' · 策略 ' . e($strategyLabel[$pool['strategy']] ?? $pool['strategy']) ?>
          </span>
          <button class="pact" type="button" onclick="openAdd(<?= $layerId ?>, <?= $pid ?>)">+ 添加到此池</button>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if ($ungrouped): ?>
      <div class="pool-col">
        <div class="pool-head">
          <div class="pool-title">
            <span class="layer-num">--</span>
            <span class="pool-name">未分组</span>
          </div>
          <span class="pool-badge"><?= count($ungrouped) ?> 个域名</span>
        </div>
        <div class="pool-meta"><span>未指定域名池的域名</span></div>
        <div class="pool-list">
          <?php foreach (array_slice($ungrouped, 0, $maxPerColumn) as $d): ?>
            <?php
              $lid = (int) $d['layer_id'];
              [$cardCls, $tagCls, $tagTxt] = board_card((string) $d['status'], $lid);
              [$chkCls, $chkTxt, $chkTime] = board_check($d);
              $chId    = $tplChannel[(int) $d['template_id']] ?? 0;
              $chLabel = $chId > 0 ? ($channelName[$chId] ?? '') : '';
            ?>
            <div class="pcard <?= $cardCls ?>">
              <div class="pcard-top">
                <span class="pdot"></span>
                <div class="pcard-body">
                  <div class="dtop">
                    <span class="dname"><?= e($d['domain']) ?></span>
                    <span class="dtag <?= $tagCls ?>"><?= e($tagTxt) ?></span>
                  </div>
                  <div class="dmeta">
                    <span>第 <?= $lid ?> 层</span>
                    <span class="sep">·</span>
                    <span><?= e(board_meta($d, $lid)) ?></span>
                    <?php if ($chLabel !== ''): ?>
                      <span class="sep">·</span><span>渠道：<?= e($chLabel) ?></span>
                    <?php endif; ?>
                    <span class="sep">·</span>
                    <span class="<?= $chkCls ?>"><?= e($chkTxt) ?><?= $chkTime ? ' ' . e($chkTime) : '' ?></span>
                  </div>
                </div>
              </div>
              <div class="pcard-acts">
                <button class="pact" type="button" onclick="act('check_one', [{k:'id',v:<?= (int) $d['id'] ?>}])">检测</button>
                <button class="pact" type="button"
                  onclick='openEdit(<?= json_encode($d, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>编辑</button>
                <?php if ($lid === 1): ?>
                  <?php if ($d['status'] === 'active'): ?>
                    <button class="pact" type="button"
                      onclick="act('set_status', [{k:'id',v:<?= (int) $d['id'] ?>},{k:'status',v:'disabled'}])">关闭</button>
                  <?php else: ?>
                    <button class="pact" type="button"
                      onclick="act('set_status', [{k:'id',v:<?= (int) $d['id'] ?>},{k:'status',v:'active'}])">启用</button>
                  <?php endif; ?>
                <?php endif; ?>
                <button class="pact danger" type="button"
                  onclick="act('delete', [{k:'ids[]',v:<?= (int) $d['id'] ?>}], '确定删除该域名？')">删除</button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!$list): ?>
    <div class="panel">
      <p class="muted" style="text-align:center;padding:26px 0">
        没有匹配的域名。可点击右上角「+ 添加域名」或「批量导入」开始添加。
      </p>
    </div>
  <?php endif; ?>
</form>

<!-- 添加域名 -->
<dialog id="addDlg">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <div class="dlg-head">添加域名</div>
    <div class="dlg-body">
      <div class="form-row"><label>域名</label><input type="text" name="domain" placeholder="例如 entry-01.com（可带 http:// 前缀）" required></div>
      <div class="form-row"><label>所属层级</label>
        <select name="layer_id" id="a_layer" required>
          <?php foreach ($layers as $l): ?>
            <?php if ((int) $l['is_final'] === 1) continue; ?>
            <option value="<?= (int) $l['id'] ?>"><?= e($l['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row"><label>域名池</label>
        <select name="pool_id" id="a_pool">
          <option value="0">不指定</option>
          <?php foreach ($pools as $p): ?>
            <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row"><label>初始状态</label>
        <select name="status">
          <option value="">自动（第 2 层为备用，其他为待使用）</option>
          <option value="pending">待使用</option>
          <option value="standby">备用</option>
        </select>
      </div>
      <div class="form-row"><label>海报模板</label>
        <select name="template_id">
          <option value="0">使用默认模板</option>
          <?php foreach ($templates as $t): ?>
            <option value="<?= (int) $t['id'] ?>"><?= e((string) $t['name']) ?><?= (int) $t['is_default'] === 1 ? '（默认）' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row"><label>权重</label><input type="number" name="weight" value="100" min="1"></div>
      <div class="form-row"><label>排序</label><input type="number" name="sort" value="0"></div>
      <div class="form-row"><label>备注</label><input type="text" name="remark"></div>
    </div>
    <div class="dlg-foot">
      <button class="btn" type="button" onclick="document.getElementById('addDlg').close()">取消</button>
      <button class="btn primary" type="submit">保存</button>
    </div>
  </form>
</dialog>

<!-- 批量导入 -->
<dialog id="importDlg">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import">
    <div class="dlg-head">批量导入域名</div>
    <div class="dlg-body">
      <div class="form-row"><label>导入到层级</label>
        <select name="layer_id" required>
          <?php foreach ($layers as $l): ?>
            <?php if ((int) $l['is_final'] === 1) continue; ?>
            <option value="<?= (int) $l['id'] ?>"><?= e($l['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row"><label>域名池</label>
        <select name="pool_id"><option value="0">不指定</option>
          <?php foreach ($pools as $p): ?>
            <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row col">
        <label>域名列表（每行一个，可写 <code>域名,备注</code>）</label>
        <textarea name="domains" placeholder="entry-01.com&#10;entry-02.com,备用入口" required></textarea>
      </div>
    </div>
    <div class="dlg-foot">
      <button class="btn" type="button" onclick="document.getElementById('importDlg').close()">取消</button>
      <button class="btn primary" type="submit">开始导入</button>
    </div>
  </form>
</dialog>

<!-- 编辑域名 -->
<dialog id="editDlg">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="edit">
    <input type="hidden" name="id" id="e_id">
    <div class="dlg-head">编辑域名</div>
    <div class="dlg-body">
      <div class="form-row"><label>域名</label><input type="text" name="domain" id="e_domain" required></div>
      <div class="form-row"><label>所属层级</label>
        <select name="layer_id" id="e_layer" required>
          <?php foreach ($layers as $l): ?>
            <?php if ((int) $l['is_final'] === 1) continue; ?>
            <option value="<?= (int) $l['id'] ?>"><?= e($l['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row"><label>域名池</label>
        <select name="pool_id" id="e_pool"><option value="0">不指定</option>
          <?php foreach ($pools as $p): ?>
            <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row"><label>状态</label>
        <select name="status" id="e_status">
          <?php foreach ($statusOptions as $k => $v): ?>
            <option value="<?= $k ?>"><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row"><label>角色（第 2 层）</label>
        <select name="role" id="e_role"><option value="">无</option><option value="main">主用</option><option value="backup">备用</option></select>
      </div>
      <div class="form-row"><label>海报模板</label>
        <select name="template_id" id="e_template" onchange="updateChannelDisplay()">
          <option value="0">使用默认模板</option>
          <?php foreach ($templates as $t): ?>
            <?php $chLabel = $channelName[$tplChannel[(int) $t['id']] ?? 0] ?? ''; ?>
            <option value="<?= (int) $t['id'] ?>" data-channel="<?= e($chLabel) ?>"><?= e((string) $t['name']) ?><?= (int) $t['is_default'] === 1 ? '（默认）' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint" id="e_channel">所属渠道：－</span>
      </div>
      <div class="form-row"><label>权重</label><input type="number" name="weight" id="e_weight" min="1"></div>
      <div class="form-row"><label>排序</label><input type="number" name="sort" id="e_sort"></div>
      <div class="form-row"><label>备注</label><input type="text" name="remark" id="e_remark"></div>
    </div>
    <div class="dlg-foot">
      <button class="btn" type="button" onclick="document.getElementById('editDlg').close()">取消</button>
      <button class="btn primary" type="submit">保存</button>
    </div>
  </form>
</dialog>

<script>
function openAdd(layerId, poolId) {
  if (layerId) { document.getElementById('a_layer').value = layerId; }
  document.getElementById('a_pool').value = poolId || 0;
  document.getElementById('addDlg').showModal();
}
function openImport() {
  document.getElementById('importDlg').showModal();
}
function openEdit(d) {
  document.getElementById('e_id').value = d.id;
  document.getElementById('e_domain').value = d.domain;
  document.getElementById('e_layer').value = d.layer_id;
  document.getElementById('e_pool').value = d.pool_id;
  document.getElementById('e_status').value = d.status;
  document.getElementById('e_role').value = d.role || '';
  document.getElementById('e_weight').value = d.weight;
  document.getElementById('e_sort').value = d.sort;
  document.getElementById('e_remark').value = d.remark || '';
  document.getElementById('e_template').value = d.template_id || '0';
  updateChannelDisplay();
  document.getElementById('editDlg').showModal();
}
// 所属渠道由所选模板决定（用哪个模板就属于哪个渠道）
function updateChannelDisplay() {
  const sel = document.getElementById('e_template');
  const opt = sel.options[sel.selectedIndex];
  const ch  = opt ? (opt.getAttribute('data-channel') || '') : '';
  document.getElementById('e_channel').textContent = '所属渠道：' + (ch !== '' ? ch : '未指定');
}
// 单条操作：动态构造表单提交
function act(action, fields, confirmMsg) {
  if (confirmMsg && !confirm(confirmMsg)) return;
  const f = document.createElement('form');
  f.method = 'post'; f.style.display = 'none';
  const add = (k, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i); };
  add('_token', '<?= csrf_token() ?>');
  add('action', action);
  (fields || []).forEach(it => add(it.k, it.v));
  document.body.appendChild(f);
  f.submit();
}
</script>

<?php admin_footer(); ?>
