<?php
/**
 * 账号安全
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$admin = current_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) post('action');

    if ($action === 'change_password') {
        $old  = (string) post('old_password');
        $new  = (string) post('new_password');
        $new2 = (string) post('new_password2');

        if (!hash_equals((string) $admin['password'], $old)) {
            flash('原密码不正确。', 'danger');
        } elseif (strlen($new) < 8) {
            flash('新密码至少 8 位。', 'danger');
        } elseif ($new !== $new2) {
            flash('两次输入的新密码不一致。', 'danger');
        } elseif ($new === $old) {
            flash('新密码不能与原密码相同。', 'danger');
        } else {
            Database::update('admins', [
                'password' => $new,
            ], '`id`=?', [(int) $admin['id']]);
            audit('修改密码', '管理员自行修改密码');
            flash('密码已更新。');
            redirect('account.php');
        }
        redirect('account.php');
    }

    if ($action === 'add_admin') {
        $username = trim((string) post('username'));
        $password = (string) post('password');
        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            flash('账号需为 3-30 位字母、数字或下划线。', 'danger');
        } elseif (strlen($password) < 8) {
            flash('密码至少 8 位。', 'danger');
        } elseif (Database::one('SELECT `id` FROM `admins` WHERE `username`=?', [$username])) {
            flash('该账号已存在。', 'danger');
        } else {
            Database::insert('admins', [
                'username'   => $username,
                'password'   => $password,
                'created_at' => Database::now(),
            ]);
            audit('新增管理员', $username);
            flash('管理员「' . $username . '」已创建。');
        }
        redirect('account.php');
    }

    if ($action === 'reset_admin') {
        $id       = (int) post('id');
        $password = (string) post('password');
        $target   = Database::one('SELECT * FROM `admins` WHERE `id`=?', [$id]);
        if (!$target) {
            flash('管理员不存在。', 'danger');
        } elseif (strlen($password) < 8) {
            flash('新密码至少 8 位。', 'danger');
        } else {
            Database::update('admins', [
                'password' => $password,
            ], '`id`=?', [$id]);
            audit('重置管理员密码', (string) $target['username']);
            flash('已重置「' . $target['username'] . '」的密码。');
        }
        redirect('account.php');
    }

    if ($action === 'del_admin') {
        $id     = (int) post('id');
        $target = Database::one('SELECT * FROM `admins` WHERE `id`=?', [$id]);
        $count  = (int) Database::value('SELECT COUNT(*) FROM `admins`');
        if (!$target) {
            flash('管理员不存在。', 'danger');
        } elseif ($id === (int) $admin['id']) {
            flash('不能删除当前登录账号。', 'danger');
        } elseif ($count <= 1) {
            flash('至少需要保留一个管理员账号。', 'danger');
        } else {
            Database::exec('DELETE FROM `admins` WHERE `id`=?', [$id]);
            audit('删除管理员', (string) $target['username']);
            flash('管理员已删除。');
        }
        redirect('account.php');
    }
}

// 上传目录 / 版本信息
$phpOk    = version_compare(PHP_VERSION, '8.0.0', '>=');
$redisOk  = Cache::usingRedis();
$curlOk   = extension_loaded('curl');
$gdOk     = extension_loaded('gd');
$backups  = glob(ROOT_PATH . '/runtime/backup/backup-*.sql') ?: [];
rsort($backups);

$admins = Database::all('SELECT `id`,`username`,`last_login_at`,`last_login_ip`,`created_at` FROM `admins` ORDER BY `id` ASC');

admin_header('账号安全', 'account', '管理登录密码与管理员账号，查看运行环境与数据库备份。');
?>

<div class="panel" style="max-width:620px">
  <h2>修改密码</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change_password">
    <div class="form-row"><label>原密码</label><input type="password" name="old_password" required></div>
    <div class="form-row"><label>新密码</label><input type="password" name="new_password" placeholder="至少 8 位" required></div>
    <div class="form-row"><label>确认新密码</label><input type="password" name="new_password2" required></div>
    <div class="mt10"><button class="btn primary" type="submit">保存新密码</button></div>
  </form>
</div>

<div class="panel">
  <div class="panel-title">
    <h2>管理员账号</h2>
    <button class="btn sm" type="button" onclick="document.getElementById('addAdminDlg').showModal()">+ 新增管理员</button>
  </div>
  <table class="table">
    <thead><tr><th>账号</th><th>最近登录</th><th>登录 IP</th><th>创建时间</th><th class="right">操作</th></tr></thead>
    <tbody>
      <?php foreach ($admins as $a): ?>
        <tr>
          <td><b><?= e($a['username']) ?></b><?= (int) $a['id'] === (int) $admin['id'] ? ' <span class="tag blue">当前账号</span>' : '' ?></td>
          <td class="muted nowrap"><?= e((string) ($a['last_login_at'] ?? '-')) ?></td>
          <td class="muted"><?= e((string) $a['last_login_ip']) ?></td>
          <td class="muted nowrap"><?= e((string) $a['created_at']) ?></td>
          <td class="right nowrap">
            <button class="btn sm" type="button"
              onclick="resetAdmin(<?= (int) $a['id'] ?>, '<?= e($a['username']) ?>')">重置密码</button>
            <?php if ((int) $a['id'] !== (int) $admin['id']): ?>
              <button class="btn sm danger" type="button"
                onclick="delAdmin(<?= (int) $a['id'] ?>, '<?= e($a['username']) ?>')">删除</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<dialog id="addAdminDlg">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_admin">
    <div class="dlg-head">新增管理员</div>
    <div class="dlg-body">
      <div class="form-row"><label>账号</label><input type="text" name="username" placeholder="3-30 位字母、数字或下划线" required></div>
      <div class="form-row"><label>初始密码</label><input type="password" name="password" placeholder="至少 8 位" required></div>
      <p class="muted">新管理员创建后即可直接登录后台。</p>
    </div>
    <div class="dlg-foot">
      <button class="btn" type="button" onclick="document.getElementById('addAdminDlg').close()">取消</button>
      <button class="btn primary" type="submit">创建</button>
    </div>
  </form>
</dialog>

<dialog id="resetAdminDlg">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset_admin">
    <input type="hidden" name="id" id="ra_id">
    <div class="dlg-head">重置密码</div>
    <div class="dlg-body">
      <div class="form-row"><label>账号</label><input type="text" id="ra_name" disabled></div>
      <div class="form-row"><label>新密码</label><input type="password" name="password" placeholder="至少 8 位" required></div>
    </div>
    <div class="dlg-foot">
      <button class="btn" type="button" onclick="document.getElementById('resetAdminDlg').close()">取消</button>
      <button class="btn primary" type="submit">确定重置</button>
    </div>
  </form>
</dialog>

<form id="delAdminForm" method="post" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="del_admin">
  <input type="hidden" name="id" id="da_id">
</form>

<div class="panel">
  <h2>运行环境</h2>
  <table class="table">
    <tbody>
      <tr><td style="width:220px">PHP 版本</td><td><?= e(PHP_VERSION) ?> <?= $phpOk ? '<span class="tag green">符合要求</span>' : '<span class="tag red">需 8.0+</span>' ?></td></tr>
      <tr><td>缓存驱动</td><td><?= $redisOk ? '<span class="tag green">Redis</span>' : '<span class="tag orange">文件缓存</span>' ?></td></tr>
      <tr><td>cURL 扩展</td><td><?= $curlOk ? '<span class="tag green">已安装</span>' : '<span class="tag red">未安装（检测与通知不可用）</span>' ?></td></tr>
      <tr><td>GD 扩展（验证码）</td><td><?= $gdOk ? '<span class="tag green">已安装</span>' : '<span class="tag orange">未安装</span>' ?></td></tr>
      <tr><td>系统根目录</td><td class="mono"><?= e(str_replace('\\', '/', ROOT_PATH)) ?></td></tr>
      <tr><td>最近登录</td><td class="muted"><?= e((string) ($admin['last_login_at'] ?? '-')) ?> · <?= e((string) ($admin['last_login_ip'] ?? '')) ?></td></tr>
    </tbody>
  </table>
</div>

<div class="panel">
  <h2>数据库备份（runtime/backup，最多保留 10 份）</h2>
  <?php if (!$backups): ?>
    <p class="muted">暂无备份文件，可执行「日志清理与备份」计划任务生成。</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>文件名</th><th>大小</th><th>时间</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($backups, 0, 10) as $f): ?>
          <tr>
            <td class="mono"><?= e(basename($f)) ?></td>
            <td><?= number_format(filesize($f) / 1024, 1) ?> KB</td>
            <td class="muted"><?= e(date('Y-m-d H:i:s', (int) filemtime($f))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script>
function resetAdmin(id, name) {
  document.getElementById('ra_id').value = id;
  document.getElementById('ra_name').value = name;
  document.getElementById('resetAdminDlg').showModal();
}
function delAdmin(id, name) {
  if (!confirm('确定删除管理员「' + name + '」？')) return;
  document.getElementById('da_id').value = id;
  document.getElementById('delAdminForm').submit();
}
</script>

<?php admin_footer(); ?>
