<?php
/**
 * 通知设置
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$eventList = [
    'domain_fail'    => '域名检测失败',
    'domain_replace' => '域名自动替换 / 补位',
    'main_switch'    => '中转层主备切换',
    'expire'         => '域名使用期结束（已到期）',
    'review'         => '入口层域名转入待处理',
    'rotate'         => '域名恢复待使用',
    'task_error'     => '计划任务异常',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) post('action');

    if ($action === 'save') {
        $events = array_keys(array_filter((array) post('events', []), static fn($v) => (string) $v === '1'));
        Config::saveSetting('notify_enabled', post('notify_enabled') === '1' ? '1' : '0');
        Config::saveSetting('notify_events', implode(',', $events));

        foreach (['email', 'telegram', 'wecom', 'dingtalk', 'webhook'] as $ch) {
            Config::saveSetting('notify_' . $ch . '_enabled', post('notify_' . $ch . '_enabled') === '1' ? '1' : '0');
        }
        Config::saveSetting('notify_email_to', (string) post('notify_email_to'));
        Config::saveSetting('notify_telegram_bot', (string) post('notify_telegram_bot'));
        Config::saveSetting('notify_telegram_chat', (string) post('notify_telegram_chat'));
        Config::saveSetting('notify_wecom_webhook', (string) post('notify_wecom_webhook'));
        Config::saveSetting('notify_dingtalk_webhook', (string) post('notify_dingtalk_webhook'));
        Config::saveSetting('notify_webhook_url', (string) post('notify_webhook_url'));

        audit('保存通知设置', '已开启渠道：' . (implode('、', array_filter([
            Config::settingBool('notify_email_enabled') ? '邮件' : '',
            Config::settingBool('notify_telegram_enabled') ? 'Telegram' : '',
            Config::settingBool('notify_wecom_enabled') ? '企业微信' : '',
            Config::settingBool('notify_dingtalk_enabled') ? '钉钉' : '',
            Config::settingBool('notify_webhook_enabled') ? 'Webhook' : '',
        ])) ?: '无'));
        flash('通知设置已保存。');
        redirect('notify.php');
    }

    if ($action === 'test') {
        $ch = (string) post('channel');
        [$ok, $msg] = Notifier::test($ch);
        Database::insert('notifications', [
            'channel'    => $ch,
            'event'      => 'test',
            'title'      => '通知渠道测试',
            'content'    => '测试消息',
            'status'     => $ok ? 'ok' : 'fail',
            'error'      => mb_substr((string) $msg, 0, 480),
            'created_at' => Database::now(),
        ]);
        flash('测试结果：' . ($ok ? '发送成功' : '发送失败 - ' . $msg), $ok ? 'success' : 'danger');
        redirect('notify.php?tab=' . $ch);
    }

    if ($action === 'clear') {
        Database::exec('DELETE FROM `notifications` WHERE `id` > 0');
        audit('清空通知记录', '');
        flash('通知记录已清空。');
        redirect('notify.php');
    }
}

$selectedEvents = array_filter(array_map('trim', explode(',', Config::setting('notify_events'))));
$recent = Database::all('SELECT * FROM `notifications` ORDER BY `id` DESC LIMIT 20');

admin_header('通知设置', 'notify', '检测失败、自动替换、主备切换、轮换与禁用等事件可推送到指定渠道。');
?>

<form class="panel" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <h2>通知总开关</h2>
  <div class="form-row">
    <label>启用通知</label>
    <select name="notify_enabled">
      <option value="1" <?= Config::settingBool('notify_enabled') ? 'selected' : '' ?>>开启</option>
      <option value="0" <?= !Config::settingBool('notify_enabled') ? 'selected' : '' ?>>关闭</option>
    </select>
    <span class="hint">关闭后仅记录站内通知，不对外发送</span>
  </div>
  <div class="form-row col">
    <label>触发事件</label>
    <div style="display:flex;flex-wrap:wrap;gap:16px">
      <?php foreach ($eventList as $k => $label): ?>
        <label style="width:auto;flex:none;display:flex;gap:6px;align-items:center">
          <input type="checkbox" name="events[<?= $k ?>]" value="1" <?= in_array($k, $selectedEvents, true) ? 'checked' : '' ?>>
          <?= e($label) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <h2 class="mt20">渠道配置</h2>

  <div class="form-row">
    <label>邮件通知</label>
    <select name="notify_email_enabled" style="max-width:120px">
      <option value="1" <?= Config::settingBool('notify_email_enabled') ? 'selected' : '' ?>>开启</option>
      <option value="0" <?= !Config::settingBool('notify_email_enabled') ? 'selected' : '' ?>>关闭</option>
    </select>
    <input type="text" name="notify_email_to" value="<?= e(Config::setting('notify_email_to')) ?>" placeholder="收件邮箱，多个用英文逗号分隔">
    <button class="btn sm" type="button" onclick="testChannel('email')">测试</button>
  </div>

  <div class="form-row">
    <label>Telegram</label>
    <select name="notify_telegram_enabled" style="max-width:120px">
      <option value="1" <?= Config::settingBool('notify_telegram_enabled') ? 'selected' : '' ?>>开启</option>
      <option value="0" <?= !Config::settingBool('notify_telegram_enabled') ? 'selected' : '' ?>>关闭</option>
    </select>
    <input type="text" name="notify_telegram_bot" value="<?= e(Config::setting('notify_telegram_bot')) ?>" placeholder="Bot Token">
    <input type="text" name="notify_telegram_chat" value="<?= e(Config::setting('notify_telegram_chat')) ?>" placeholder="Chat ID">
    <button class="btn sm" type="button" onclick="testChannel('telegram')">测试</button>
  </div>

  <div class="form-row">
    <label>企业微信机器人</label>
    <select name="notify_wecom_enabled" style="max-width:120px">
      <option value="1" <?= Config::settingBool('notify_wecom_enabled') ? 'selected' : '' ?>>开启</option>
      <option value="0" <?= !Config::settingBool('notify_wecom_enabled') ? 'selected' : '' ?>>关闭</option>
    </select>
    <input type="text" name="notify_wecom_webhook" value="<?= e(Config::setting('notify_wecom_webhook')) ?>" placeholder="Webhook 地址">
    <button class="btn sm" type="button" onclick="testChannel('wecom')">测试</button>
  </div>

  <div class="form-row">
    <label>钉钉机器人</label>
    <select name="notify_dingtalk_enabled" style="max-width:120px">
      <option value="1" <?= Config::settingBool('notify_dingtalk_enabled') ? 'selected' : '' ?>>开启</option>
      <option value="0" <?= !Config::settingBool('notify_dingtalk_enabled') ? 'selected' : '' ?>>关闭</option>
    </select>
    <input type="text" name="notify_dingtalk_webhook" value="<?= e(Config::setting('notify_dingtalk_webhook')) ?>" placeholder="Webhook 地址">
    <button class="btn sm" type="button" onclick="testChannel('dingtalk')">测试</button>
  </div>

  <div class="form-row">
    <label>自定义 Webhook</label>
    <select name="notify_webhook_enabled" style="max-width:120px">
      <option value="1" <?= Config::settingBool('notify_webhook_enabled') ? 'selected' : '' ?>>开启</option>
      <option value="0" <?= !Config::settingBool('notify_webhook_enabled') ? 'selected' : '' ?>>关闭</option>
    </select>
    <input type="text" name="notify_webhook_url" value="<?= e(Config::setting('notify_webhook_url')) ?>" placeholder="POST JSON：title / content / event / time">
    <button class="btn sm" type="button" onclick="testChannel('webhook')">测试</button>
  </div>

  <div class="mt20">
    <button class="btn primary" type="submit">保存通知设置</button>
  </div>
</form>

<form id="testForm" method="post" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="test">
  <input type="hidden" name="channel" id="testChannel">
</form>

<div class="panel">
  <div class="panel-title">
    <h2>最近通知记录</h2>
    <form method="post" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="action" value="clear">
      <button class="btn sm" type="submit" data-confirm="确定清空全部通知记录？">清空</button>
    </form>
  </div>
  <?php if (!$recent): ?>
    <p class="muted">暂无通知记录。</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>时间</th><th>渠道</th><th>事件</th><th>标题</th><th>结果</th><th>详情</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $n): ?>
          <tr>
            <td class="muted nowrap"><?= e((string) $n['created_at']) ?></td>
            <td><?= e((string) $n['channel']) ?></td>
            <td><?= e((string) $n['event']) ?></td>
            <td><?= e((string) $n['title']) ?></td>
            <td><span class="tag <?= $n['status'] === 'ok' ? 'green' : 'red' ?>"><?= $n['status'] === 'ok' ? '成功' : '失败' ?></span></td>
            <td class="muted"><?= e(mb_substr((string) $n['error'], 0, 100)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script>
function testChannel(ch) {
  document.getElementById('testChannel').value = ch;
  document.getElementById('testForm').submit();
}
</script>

<?php admin_footer(); ?>
