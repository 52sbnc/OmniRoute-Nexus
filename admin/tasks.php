<?php
/**
 * 计划任务管理
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) post('action');

    if ($action === 'run') {
        $code = (string) post('code');
        $task = Database::one('SELECT * FROM `tasks` WHERE `code`=?', [$code]);
        if (!$task) {
            flash('任务不存在。', 'danger');
            redirect('tasks.php');
        }
        $r = TaskRunner::run($code, true);
        audit('手动执行任务', $code . ' · ' . $r['result']);
        flash(
            sprintf('任务「%s」执行%s：%s（耗时 %d ms）', $code, $r['result'] === 'ok' ? '成功' : ($r['result'] === 'warn' ? '完成但有告警' : '失败'), $r['message'], $r['ms']),
            $r['result'] === 'fail' ? 'danger' : ($r['result'] === 'warn' ? 'warn' : 'success')
        );
        redirect('tasks.php');
    }

    if ($action === 'toggle') {
        $id = (int) post('id');
        $task = Database::one('SELECT * FROM `tasks` WHERE `id`=?', [$id]);
        if ($task) {
            Database::update('tasks', ['enabled' => (int) $task['enabled'] === 1 ? 0 : 1], '`id`=?', [$id]);
            audit('切换任务状态', $task['code'] . ' → ' . ((int) $task['enabled'] === 1 ? '停用' : '启用'));
            flash('任务状态已切换。');
        }
        redirect('tasks.php');
    }

    if ($action === 'clear_logs') {
        Database::exec('DELETE FROM `task_logs` WHERE `id` > 0');
        audit('清空任务日志', '');
        flash('任务日志已清空。');
        redirect('tasks.php');
    }
}

$tasks = Database::all('SELECT * FROM `tasks` ORDER BY `id` ASC');

// 生成可复制的调用地址（用当前后台访问地址拼）
$scheme   = resolve_scheme(Config::setting('jump_scheme', 'auto'));
$baseUrl  = $scheme . '://' . request_host() . app_base_path();
$token    = (string) Config::get('app.cron_token', '');
$taskDesc = [
    'check'   => '检测各层正在使用域名的 DNS 解析；中转层/落地层失败自动替换，入口层失败仅标记',
    'rotate'  => '入口层使用期满转「已到期」、保留期满转「待处理」；中转层/落地层自动补位',
    'cleanup' => '汇总历史数据、清理过期日志、备份数据库（每天首次真正执行）',
];

$page  = (int) get_param('page', 1);
$total = (int) Database::value('SELECT COUNT(*) FROM `task_logs`');
$pg    = pagination($total, $page, 20);
$logs  = Database::all("SELECT * FROM `task_logs` ORDER BY `id` DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");

admin_header('计划任务', 'tasks', '每个任务一条调用地址，粘贴到服务器计划任务里，执行周期设「每 5 分钟」即可。');
?>

<div class="panel">
  <h2>任务与调用地址</h2>
  <table class="table">
    <thead><tr><th>任务</th><th>状态</th><th>上次执行</th><th>结果</th><th>耗时</th><th class="right">操作</th></tr></thead>
    <tbody>
      <?php foreach ($tasks as $t): ?>
        <?php $url = $baseUrl . '/task.php?token=' . urlencode($token) . '&job=' . $t['code']; ?>
        <tr>
          <td>
            <b><?= e($t['name']) ?></b>
            <div class="muted" style="font-size:11.5px"><?= e($taskDesc[$t['code']] ?? (string) $t['remark']) ?></div>
          </td>
          <td><?= (int) $t['enabled'] === 1 ? '<span class="tag green">启用</span>' : '<span class="tag gray">停用</span>' ?></td>
          <td class="muted nowrap"><?= e(human_time((string) ($t['last_run_at'] ?? null))) ?></td>
          <td>
            <?php if ($t['last_result'] === ''): ?><span class="muted">未执行</span>
            <?php else: ?>
              <span class="tag <?= $t['last_result'] === 'ok' ? 'green' : ($t['last_result'] === 'warn' ? 'orange' : 'red') ?>">
                <?= e(['ok' => '成功', 'warn' => '有告警', 'fail' => '失败'][$t['last_result']] ?? $t['last_result']) ?>
              </span>
            <?php endif; ?>
          </td>
          <td class="muted"><?= (int) $t['last_ms'] ?> ms</td>
          <td class="right nowrap">
            <button class="btn sm primary" type="button" onclick="act('run', {code:'<?= e($t['code']) ?>'})">立即执行</button>
            <button class="btn sm" type="button" onclick="act('toggle', {id:<?= (int) $t['id'] ?>})"><?= (int) $t['enabled'] === 1 ? '停用' : '启用' ?></button>
          </td>
        </tr>
        <tr>
          <td colspan="6" style="background:var(--surface-2)">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
              <span class="muted" style="font-size:12px;flex:0 0 auto">调用地址 · 每 5 分钟</span>
              <input type="text" id="url_<?= e($t['code']) ?>" value="<?= e($url) ?>" readonly
                     style="max-width:520px;font-family:Consolas,monospace;font-size:12px"
                     onclick="this.select()">
              <button class="btn sm" type="button" onclick="copyUrl('url_<?= e($t['code']) ?>')">复制</button>
            </div>
          </td>
        </tr>
        <?php if ($t['last_message'] !== ''): ?>
          <tr><td colspan="6" class="muted" style="font-size:12px">上次说明：<?= e((string) $t['last_message']) ?></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>

  <p class="muted mt10" style="font-size:12px">
    宝塔面板：计划任务 → 添加任务 → 类型选「访问 URL」，执行周期选「N 分钟 → 5 分钟」，把上面的地址粘进去保存即可。
    若服务器能用 CLI，也可用 <code>*/5 * * * * /usr/bin/php <?= e(str_replace('\\', '/', ROOT_PATH)) ?>/task.php</code> 一次跑完全部任务。
  </p>
</div>

<div class="panel">
  <div class="panel-head">
    <div class="panel-title">执行日志</div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="clear_logs">
      <button class="btn sm" type="submit" data-confirm="确定清空任务日志？">清空</button>
    </form>
  </div>
  <?php if (!$logs): ?>
    <p class="muted">暂无执行记录。配置好计划任务后这里会显示每次执行的时间和结果。</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>时间</th><th>任务</th><th>结果</th><th>耗时</th><th>说明</th></tr></thead>
      <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td class="muted nowrap"><?= e((string) $l['created_at']) ?></td>
            <td class="mono"><?= e((string) $l['task_code']) ?></td>
            <td><span class="tag <?= $l['result'] === 'ok' ? 'green' : ($l['result'] === 'warn' ? 'orange' : 'red') ?>"><?= e($l['result']) ?></span></td>
            <td><?= (int) $l['duration_ms'] ?> ms</td>
            <td class="muted"><?= e((string) $l['message']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?= pager_html($pg) ?>
  <?php endif; ?>
</div>

<script>
function copyUrl(id) {
  const el = document.getElementById(id);
  el.select();
  navigator.clipboard.writeText(el.value).then(
    () => alert('调用地址已复制'),
    () => alert('复制失败，请手动选中复制')
  );
}
function act(action, fields) {
  const f = document.createElement('form');
  f.method = 'post'; f.style.display = 'none';
  const add = (k, v) => { const i = document.createElement('input'); i.type='hidden'; i.name=k; i.value=v; f.appendChild(i); };
  add('_token', '<?= csrf_token() ?>');
  add('action', action);
  Object.keys(fields).forEach(k => add(k, fields[k]));
  document.body.appendChild(f);
  f.submit();
}
</script>

<?php admin_footer(); ?>
