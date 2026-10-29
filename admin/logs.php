<?php
/**
 * 日志中心：跳转日志 / 检测日志 / 状态变更 / 操作日志
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$tabs = [
    'jump'   => '跳转日志',
    'check'  => '检测日志',
    'domain' => '状态变更',
    'op'     => '操作日志',
];
$tab = (string) get_param('tab', 'jump');
if (!isset($tabs[$tab])) {
    $tab = 'jump';
}

$layerFilter = (int) get_param('layer', 0);
$resultFilter = (string) get_param('result');
$kw          = (string) get_param('kw');
$page        = (int) get_param('page', 1);
$perPage     = 25;

$where  = [];
$params = [];
if ($layerFilter > 0) {
    $where[]  = '`layer_id` = ?';
    $params[] = $layerFilter;
}
if ($kw !== '') {
    $where[]  = '(`domain` LIKE ? OR `message` LIKE ?)';
    $params[] = '%' . $kw . '%';
    $params[] = '%' . $kw . '%';
}
$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

switch ($tab) {
    case 'check':
        $w = $where;
        $p = $params;
        if ($resultFilter !== '') {
            $w[] = '`result` = ?';
            $p[] = $resultFilter;
        }
        $sqlWhere = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
        $total = (int) Database::value("SELECT COUNT(*) FROM `check_logs` $sqlWhere", $p);
        $pg    = pagination($total, $page, $perPage);
        $rows  = Database::all("SELECT * FROM `check_logs` $sqlWhere ORDER BY `id` DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $p);
        break;

    case 'domain':
        $total = (int) Database::value("SELECT COUNT(*) FROM `domain_logs` $sqlWhere", $params);
        $pg    = pagination($total, $page, $perPage);
        $rows  = Database::all("SELECT * FROM `domain_logs` $sqlWhere ORDER BY `id` DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);
        break;

    case 'op':
        $w = [];
        $p = [];
        if ($kw !== '') {
            $w[] = '(`action` LIKE ? OR `detail` LIKE ? OR `username` LIKE ?)';
            $p[] = '%' . $kw . '%';
            $p[] = '%' . $kw . '%';
            $p[] = '%' . $kw . '%';
        }
        $sqlWhere = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
        $total = (int) Database::value("SELECT COUNT(*) FROM `operation_logs` $sqlWhere", $p);
        $pg    = pagination($total, $page, $perPage);
        $rows  = Database::all("SELECT * FROM `operation_logs` $sqlWhere ORDER BY `id` DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $p);
        break;

    default: // jump
        $w = [];
        $p = [];
        if ($layerFilter > 0) {
            $w[] = '`layer` = ?';
            $p[] = $layerFilter;
        }
        if ($resultFilter !== '') {
            $w[] = '`result` = ?';
            $p[] = $resultFilter;
        }
        if ($kw !== '') {
            $w[] = '(`host` LIKE ? OR `target` LIKE ? OR `ip` LIKE ?)';
            $p[] = '%' . $kw . '%';
            $p[] = '%' . $kw . '%';
            $p[] = '%' . $kw . '%';
        }
        $sqlWhere = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
        $total = (int) Database::value("SELECT COUNT(*) FROM `jump_logs` $sqlWhere", $p);
        $pg    = pagination($total, $page, $perPage);
        $rows  = Database::all("SELECT * FROM `jump_logs` $sqlWhere ORDER BY `id` DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $p);
}

$q = array_filter(['tab' => $tab, 'layer' => $layerFilter ?: '', 'result' => $resultFilter, 'kw' => $kw], static fn($v) => $v !== '' && $v !== null);

admin_header('日志中心', 'logs', '跳转、检测、状态变更与后台操作记录，可按层级与结果筛选。');
?>

<div class="panel">
  <div class="filter">
    <a class="btn sm <?= $tab === 'jump' ? 'primary' : '' ?>" href="?tab=jump">跳转日志</a>
    <a class="btn sm <?= $tab === 'check' ? 'primary' : '' ?>" href="?tab=check">检测日志</a>
    <a class="btn sm <?= $tab === 'domain' ? 'primary' : '' ?>" href="?tab=domain">状态变更</a>
    <a class="btn sm <?= $tab === 'op' ? 'primary' : '' ?>" href="?tab=op">操作日志</a>
  </div>

  <form class="filter" method="get">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <?php if ($tab === 'jump' || $tab === 'check' || $tab === 'domain'): ?>
      <select name="layer">
        <option value="">全部层级</option>
        <?php foreach (DomainService::layers() as $l): ?>
          <option value="<?= (int) $l['id'] ?>" <?= $layerFilter === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <?php if ($tab === 'jump'): ?>
      <select name="result">
        <option value="">全部结果</option>
        <?php foreach (['ok' => '正常跳转', 'fallback' => '兜底跳转', 'blocked' => '被拦截'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $resultFilter === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
    <?php elseif ($tab === 'check'): ?>
      <select name="result">
        <option value="">全部结果</option>
        <option value="ok" <?= $resultFilter === 'ok' ? 'selected' : '' ?>>正常</option>
        <option value="fail" <?= $resultFilter === 'fail' ? 'selected' : '' ?>>异常</option>
      </select>
    <?php endif; ?>
    <input type="text" name="kw" value="<?= e($kw) ?>" placeholder="<?= $tab === 'jump' ? '搜索域名 / 目标 / IP' : ($tab === 'op' ? '搜索操作 / 内容 / 账号' : '搜索域名 / 说明') ?>">
    <button class="btn sm" type="submit">筛选</button>
    <a class="btn sm" href="?tab=<?= e($tab) ?>">重置</a>
  </form>

  <table class="table">
    <?php if ($tab === 'jump'): ?>
      <thead><tr><th>时间</th><th>层级</th><th>访问域名</th><th>跳转目标</th><th>IP</th><th>结果</th><th>耗时</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty">暂无数据</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="muted nowrap"><?= e((string) $r['created_at']) ?></td>
            <td>第 <?= (int) $r['layer'] ?> 层</td>
            <td class="mono"><?= e((string) $r['host']) ?></td>
            <td class="mono" style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e((string) $r['target']) ?></td>
            <td class="muted"><?= e((string) $r['ip']) ?></td>
            <td><span class="tag <?= $r['result'] === 'ok' ? 'green' : ($r['result'] === 'blocked' ? 'red' : 'orange') ?>"><?= e(['ok' => '正常', 'fallback' => '兜底', 'blocked' => '拦截'][$r['result']] ?? $r['result']) ?></span></td>
            <td class="muted"><?= (int) $r['cost_ms'] ?> ms</td>
          </tr>
        <?php endforeach; ?>
      </tbody>

    <?php elseif ($tab === 'check'): ?>
      <thead><tr><th>时间</th><th>层级</th><th>域名</th><th>结果</th><th>解析 IP</th><th>耗时</th><th>说明</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty">暂无数据</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="muted nowrap"><?= e((string) $r['created_at']) ?></td>
            <td>第 <?= (int) $r['layer_id'] ?> 层</td>
            <td class="mono"><?= e((string) $r['domain']) ?></td>
            <td><span class="tag <?= $r['result'] === 'ok' ? 'green' : 'red' ?>"><?= $r['result'] === 'ok' ? '解析正常' : '解析失败' ?></span></td>
            <td class="mono"><?= e((string) ($r['resolved_ip'] ?? '') ?: '—') ?></td>
            <td class="muted"><?= (int) $r['response_ms'] ?> ms</td>
            <td class="muted"><?= e((string) $r['message']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>

    <?php elseif ($tab === 'domain'): ?>
      <thead><tr><th>时间</th><th>层级</th><th>域名</th><th>状态变更</th><th>动作</th><th>说明</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="6" class="empty">暂无数据</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="muted nowrap"><?= e((string) $r['created_at']) ?></td>
            <td>第 <?= (int) $r['layer_id'] ?> 层</td>
            <td class="mono"><?= e((string) $r['domain']) ?></td>
            <td class="nowrap">
              <span class="tag <?= status_class((string) $r['from_status']) ?>"><?= e(status_label((string) $r['from_status'], (int) $r['layer_id'])) ?></span>
              →
              <span class="tag <?= status_class((string) $r['to_status']) ?>"><?= e(status_label((string) $r['to_status'], (int) $r['layer_id'])) ?></span>
            </td>
            <td><?= e((string) $r['action']) ?></td>
            <td class="muted"><?= e((string) $r['message']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>

    <?php else: ?>
      <thead><tr><th>时间</th><th>账号</th><th>操作</th><th>详情</th><th>IP</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="5" class="empty">暂无数据</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="muted nowrap"><?= e((string) $r['created_at']) ?></td>
            <td><?= e((string) $r['username']) ?></td>
            <td><?= e((string) $r['action']) ?></td>
            <td class="muted"><?= e((string) $r['detail']) ?></td>
            <td class="muted"><?= e((string) $r['ip']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    <?php endif; ?>
  </table>

  <?= pager_html($pg, $q) ?>
</div>

<?php admin_footer(); ?>
