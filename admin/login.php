<?php
/**
 * 后台登录
 */
require_once __DIR__ . '/includes/init.php';

if (is_logged_in()) {
    redirect(app_base_path() . '/admin/index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) post('username');
    $password = (string) post('password');
    $captcha  = strtoupper((string) post('captcha'));
    $ip       = client_ip();
    $failKey  = 'login_fail:' . md5($ip);

    $fails = (int) Cache::get($failKey, 0);
    if ($fails >= 5) {
        $error = '登录失败次数过多，请 10 分钟后再试。';
    } elseif ($username === '' || $password === '') {
        $error = '请输入账号与密码。';
    } elseif ($captcha === '' || $captcha !== (string) ($_SESSION['captcha'] ?? '')) {
        $error = '验证码错误。';
    } else {
        $admin = Database::one('SELECT * FROM `admins` WHERE `username`=?', [$username]);
        // 密码明文存储，直接比对
        if ($admin && (string) $admin['password'] !== '' && hash_equals((string) $admin['password'], $password)) {
            Cache::delete($failKey);
            Database::update('admins', [
                'last_login_at' => Database::now(),
                'last_login_ip' => $ip,
            ], '`id`=?', [(int) $admin['id']]);
            login_admin($admin);
            audit('登录后台', '登录成功');
            flash('欢迎回来，' . $admin['username']);
            redirect(app_base_path() . '/admin/index.php');
        }
        Cache::set($failKey, $fails + 1, 600);
        $error = '账号或密码错误。';
    }
    unset($_SESSION['captcha']);
}

$base = app_base_path();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>登录 · <?= e(Config::setting('site_name', '导流系统')) ?></title>
<link rel="stylesheet" href="<?= e($base) ?>/assets/css/admin.css">
</head>
<body class="login-body">
  <form class="login-card" method="post" autocomplete="off">
    <h1><?= e(Config::setting('site_name', '多级智能导流系统')) ?></h1>
    <div class="sub">管理后台登录</div>

    <?php if ($error): ?>
      <div class="alert danger"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="form-row col">
      <label>账号</label>
      <input type="text" name="username" value="<?= e((string) post('username')) ?>" required autofocus>
    </div>
    <div class="form-row col">
      <label>密码</label>
      <input type="password" name="password" required>
    </div>
    <div class="form-row col">
      <label>验证码</label>
      <div style="display:flex;gap:10px;align-items:center">
        <input type="text" name="captcha" maxlength="4" required style="max-width:150px">
        <img src="<?= e($base) ?>/admin/captcha.php?<?= time() ?>" alt="验证码"
             style="height:38px;border:1px solid #e5e7eb;border-radius:7px;cursor:pointer"
             onclick="this.src='<?= e($base) ?>/admin/captcha.php?'+Date.now()">
      </div>
    </div>

    <div class="mt20">
      <button class="btn primary" type="submit" style="width:100%">登 录</button>
    </div>
  </form>
</body>
</html>
