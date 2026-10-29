<?php
/**
 * 后台统一布局（天枢 · 控制台风格）
 */

function admin_nav(): array
{
    return [
        'dash'     => ['index.php', '总览'],
        'domains'  => ['domains.php', '域名池'],
        'templates'=> ['templates.php', '模板'],
        'layers'   => ['layers.php', '层级配置'],
        'tasks'    => ['tasks.php', '计划任务'],
        'logs'     => ['logs.php', '日志'],
        'notify'   => ['notify.php', '通知'],
        'settings' => ['settings.php', '系统设置'],
    ];
}

/**
 * 输出页面头部（含顶栏）
 *
 * @param string $title  页面标题，传空串则只输出顶栏（首页用）
 * @param string $active 当前导航标识
 * @param string $desc   页面标题下方的说明文字
 */
function admin_header(string $title, string $active = '', string $desc = ''): void
{
    $base     = app_base_path();
    $admin    = current_admin();
    $siteName = Config::setting('site_name', '天枢 · 多级智能导流系统');
    $brand    = str_replace('智能导流', '<em>智能导流</em>', e($siteName));
    $mark     = mb_substr($siteName, 1, 1) ?: '枢';
    $avatar   = mb_strtoupper(mb_substr((string) ($admin['username'] ?? 'A'), 0, 1));
    $redisOk  = Cache::usingRedis();
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title !== '' ? $title . ' · ' : '') ?><?= e($siteName) ?></title>
<link rel="stylesheet" href="<?= e($base) ?>/assets/css/admin.css">
</head>
<body>
<div class="wrap">

  <header class="topbar">
    <div class="brand">
      <div class="brand-mark"><?= e($mark) ?></div>
      <div>
        <div class="brand-name"><?= $brand ?></div>
        <div class="brand-sub">Multi-Tier Traffic Orchestration</div>
      </div>
    </div>
    <nav class="nav">
      <?php foreach (admin_nav() as $key => [$file, $label]): ?>
        <a href="<?= e($base) ?>/admin/<?= e($file) ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="user">
      <div class="status-pill<?= $redisOk ? '' : ' is-bad' ?>">
        <span class="pulse"></span><?= $redisOk ? '系统运行中' : 'Redis 未连接' ?>
      </div>
      <div class="user-links">
        <a href="<?= e($base) ?>/admin/account.php"><?= e((string) ($admin['username'] ?? '')) ?></a>
        <a href="<?= e($base) ?>/admin/logout.php">退出</a>
      </div>
      <div class="avatar"><?= e($avatar) ?></div>
    </div>
  </header>

  <?php if ($title !== ''): ?>
    <div class="page-head">
      <div>
        <h1><?= e($title) ?></h1>
        <?php if ($desc !== ''): ?><p><?= e($desc) ?></p><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php foreach (take_flash() as $f): ?>
    <div class="alert <?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
  <?php endforeach; ?>
    <?php
}

function admin_footer(): void
{
    ?>
</div>
<script>
document.querySelectorAll('[data-confirm]').forEach(function (el) {
  el.addEventListener('click', function (ev) {
    if (!window.confirm(el.getAttribute('data-confirm'))) {
      ev.preventDefault();
    }
  });
});
</script>
</body>
</html>
    <?php
}
