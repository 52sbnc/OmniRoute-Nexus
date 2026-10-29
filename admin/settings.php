<?php
/**
 * 跳转配置 / 系统设置
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) post('action');

    if ($action === 'save_jump') {
        $final    = trim((string) post('final_url'));
        $fallback = trim((string) post('fallback_url'));

        if ($final === '' || !is_valid_url($final)) {
            flash('最终落地 URL 格式不正确（需包含 http/https）。', 'danger');
            redirect('settings.php');
        }
        if ($fallback !== '' && !is_valid_url($fallback)) {
            flash('兜底地址格式不正确（需包含 http/https）。', 'danger');
            redirect('settings.php');
        }

        Config::saveSetting('final_url', $final);
        Config::saveSetting('fallback_url', $fallback);
        $jumpType = (string) post('jump_type');
        Config::saveSetting('jump_type', in_array($jumpType, ['301', '302', 'meta'], true) ? $jumpType : 'meta');
        $schemeCfg = (string) post('jump_scheme');
        Config::saveSetting('jump_scheme', in_array($schemeCfg, ['https', 'http', 'auto'], true) ? $schemeCfg : 'auto');
        Config::saveSetting('force_https', post('force_https') === '1' ? '1' : '0');
        Config::saveSetting('ip_limit', (string) max(0, (int) post('ip_limit')));
        Config::saveSetting('ua_blacklist', (string) post('ua_blacklist'));

        audit('保存跳转配置', '落地 URL：' . $final);
        DomainService::rebuild();
        flash('跳转配置已保存，已立即生效。');
        redirect('settings.php');
    }

    if ($action === 'save_system') {
        $name = trim((string) post('site_name'));
        Config::saveSetting('site_name', $name !== '' ? $name : '多级智能导流系统');
        Config::saveSetting('log_keep_days', (string) max(1, (int) post('log_keep_days', 30)));
        audit('保存系统设置', '站点名称：' . $name);
        flash('系统设置已保存。');
        redirect('settings.php');
    }
}

$route = DomainService::route();

admin_header('跳转配置', 'settings', '最终落地 URL 与兜底地址全系统唯一，修改后立即生效，上层域名无需改动。');
?>

<?php
$jumpLabel = [
    'meta' => '网页跳转',
    '302'  => '302 跳转',
    '301'  => '301 跳转',
][Config::setting('jump_type', 'meta')] ?? '网页跳转';
?>

<div class="panel">
  <h2>跳转链路</h2>
  <p class="muted">全链路<?= e($jumpLabel) ?>，上层所有域名无需改动，仅需修改此处即可切换业务目标。</p>
  <pre class="code">用户 → 第 1 层（入口域名，同时仅 1 个）
     → <?= e($jumpLabel) ?> → 第 2 层（中转域名，主备）
     → <?= e($jumpLabel) ?> → 第 3 层（落地域名，同时在线 <?= (int) (DomainService::layer(3)['active_count'] ?? 3) ?> 个）
     → <?= e($jumpLabel) ?> → 第 4 层（最终落地 URL）</pre>

  <table class="table mt20">
    <thead><tr><th>环节</th><th>当前生效</th><th>缓存刷新</th></tr></thead>
    <tbody>
      <tr>
        <td>第 1 层 · 入口域名</td>
        <td class="mono"><?= e(implode(', ', array_keys(array_filter($route['l1']['map'] ?? [], static fn($s) => $s === 'active'))) ?: '暂无') ?></td>
        <td class="muted">轮换 / 故障替换时自动刷新</td>
      </tr>
      <tr>
        <td>第 2 层 · 中转域名</td>
        <td class="mono"><?= e((string) ($route['l2']['active'] ?? '') ?: '暂无') ?>
          <?php if (!empty($route['l2']['standby'])): ?>
            <span class="muted">（备用：<?= e(implode(', ', $route['l2']['standby'])) ?>）</span>
          <?php endif; ?>
        </td>
        <td class="muted">主备切换时自动刷新</td>
      </tr>
      <tr>
        <td>第 3 层 · 落地域名</td>
        <td class="mono"><?= e(implode(', ', $route['l3'] ?? []) ?: '暂无') ?></td>
        <td class="muted">故障替换时自动刷新</td>
      </tr>
      <tr>
        <td>第 4 层 · 最终落地 URL</td>
        <td class="mono"><?= e((string) ($route['final'] ?? '')) ?></td>
        <td class="muted">保存后立即生效</td>
      </tr>
      <tr>
        <td>兜底地址</td>
        <td class="mono"><?= e((string) ($route['fallback'] ?? '')) ?></td>
        <td class="muted">禁用 / 无健康域名时使用</td>
      </tr>
    </tbody>
  </table>
</div>

<form class="panel" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_jump">
  <h2>跳转参数</h2>

  <div class="form-row">
    <label>最终落地 URL（第 4 层）</label>
    <input type="text" name="final_url" value="<?= e(Config::setting('final_url')) ?>" placeholder="https://example.com/" required>
  </div>
  <div class="form-row">
    <label>兜底地址</label>
    <input type="text" name="fallback_url" value="<?= e(Config::setting('fallback_url')) ?>" placeholder="https://example.com/">
    <span class="hint">域名被禁用或无可用域名时跳转到这里，必须配置</span>
  </div>
  <div class="form-row">
    <label>跳转类型</label>
    <select name="jump_type">
      <option value="meta" <?= Config::setting('jump_type', 'meta') === 'meta' ? 'selected' : '' ?>>网页跳转 · meta 刷新（默认，不发送 Location 头）</option>
      <option value="302" <?= Config::setting('jump_type', 'meta') === '302' ? 'selected' : '' ?>>302 跳转（HTTP 头 + 网页兜底）</option>
      <option value="301" <?= Config::setting('jump_type', 'meta') === '301' ? 'selected' : '' ?>>301 跳转（HTTP 头 + 网页兜底）</option>
    </select>
    <span class="hint">网页跳转返回 200 + HTML，由页面自动刷新到下一层；301/302 走 HTTP 头，仅在中间层剥离 Location 时用页面 meta 兜底</span>
  </div>
  <div class="form-row">
    <label>下级域名协议</label>
    <select name="jump_scheme">
      <option value="auto" <?= Config::setting('jump_scheme', 'auto') === 'auto' ? 'selected' : '' ?>>自动 · 跟随访问协议（http 与 https 都可用）</option>
      <option value="https" <?= Config::setting('jump_scheme', 'auto') === 'https' ? 'selected' : '' ?>>HTTPS（强制下级域名走 https）</option>
      <option value="http" <?= Config::setting('jump_scheme', 'auto') === 'http' ? 'selected' : '' ?>>HTTP（强制下级域名走 http）</option>
    </select>
    <span class="hint">各层域名之间跳转所使用的协议；选「自动」时访问者用什么协议就跳什么协议</span>
  </div>
  <div class="form-row">
    <label>强制 HTTPS</label>
    <select name="force_https">
      <option value="0" <?= !Config::settingBool('force_https') ? 'selected' : '' ?>>关闭 · http 与 https 同时可用</option>
      <option value="1" <?= Config::settingBool('force_https') ? 'selected' : '' ?>>开启 · http 访问自动 301 跳到 https</option>
    </select>
    <span class="hint">一键开关；关闭后 http 和 https 都能正常访问（站点需已配置证书才能开启）</span>
  </div>

  <h2 class="mt20">防刷策略</h2>
  <div class="form-row">
    <label>单 IP 每分钟上限</label>
    <input type="number" name="ip_limit" value="<?= (int) Config::setting('ip_limit', '0') ?>" min="0">
    <span class="hint">0 表示不限制；超限请求将跳转兜底并记录为 blocked</span>
  </div>
  <div class="form-row col">
    <label>UA 黑名单关键字（每行一个，命中即拦截）</label>
    <textarea name="ua_blacklist" placeholder="curl&#10;python-requests&#10;scrapy"><?= e(Config::setting('ua_blacklist')) ?></textarea>
  </div>

  <div class="mt20">
    <button class="btn primary" type="submit">保存跳转配置</button>
    <a class="btn" href="index.php">返回仪表盘</a>
  </div>
</form>

<form class="panel" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_system">
  <h2>系统设置</h2>
  <div class="form-row">
    <label>站点名称</label>
    <input type="text" name="site_name" value="<?= e(Config::setting('site_name')) ?>">
  </div>
  <div class="form-row">
    <label>日志保留天数</label>
    <input type="number" name="log_keep_days" value="<?= (int) Config::setting('log_keep_days', '30') ?>" min="1">
    <span class="hint">超出天数的跳转/检测日志将被清理任务删除</span>
  </div>
  <div class="mt20">
    <button class="btn primary" type="submit">保存系统设置</button>
  </div>
</form>

<?php admin_footer(); ?>
