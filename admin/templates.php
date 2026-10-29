<?php
/**
 * 海报模板管理
 *
 * 入口层域名被访问时展示的页面。模板内容里的 {{link}} 会被替换成中转层地址。
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$PLACEHOLDER = '{{link}}';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) post('action');

    if ($action === 'save_channel') {
        $id     = (int) post('id');
        $name   = trim((string) post('name'));
        if ($name === '') {
            flash('请填写渠道名称。', 'danger');
            redirect('templates.php');
        }
        $dup = Database::one('SELECT `id` FROM `channels` WHERE `name`=?', [$name]);
        if ($dup && (int) $dup['id'] !== $id) {
            flash('渠道名称已存在：' . $name, 'danger');
            redirect('templates.php');
        }
        $data = [
            'name'       => mb_substr($name, 0, 60),
            'sort'       => (int) post('sort'),
            'remark'     => mb_substr(trim((string) post('remark')), 0, 250),
            'updated_at' => Database::now(),
        ];
        if ($id > 0) {
            Database::update('channels', $data, '`id`=?', [$id]);
            audit('编辑渠道', $name);
            flash('渠道已保存。');
        } else {
            $data['created_at'] = Database::now();
            Database::insert('channels', $data);
            audit('新增渠道', $name);
            flash('渠道已添加。');
        }
        DomainService::rebuild();
        redirect('templates.php');
    }

    if ($action === 'delete_channel') {
        $id = (int) post('id');
        $ch = DomainService::channel($id);
        if (!$ch) {
            flash('渠道不存在。', 'danger');
            redirect('templates.php');
        }
        $used = (int) Database::value('SELECT COUNT(*) FROM `templates` WHERE `channel_id`=?', [$id]);
        if ($used > 0) {
            flash('还有 ' . $used . ' 个模板属于该渠道，请先把这些模板改到别的渠道再删除。', 'danger');
            redirect('templates.php');
        }
        Database::exec('DELETE FROM `channels` WHERE `id`=?', [$id]);
        audit('删除渠道', (string) $ch['name']);
        flash('渠道已删除。');
        DomainService::rebuild();
        redirect('templates.php');
    }

    if ($action === 'save') {
        $id      = (int) post('id');
        $name    = trim((string) post('name'));
        $remark  = trim((string) post('remark'));
        $content = (string) post('content');
        $isDef   = (int) post('is_default') === 1 ? 1 : 0;
        $chId    = (int) post('channel_id');

        if ($name === '') {
            flash('请填写模板名称。', 'danger');
            redirect('templates.php' . ($id > 0 ? '?edit=' . $id : '?new=1'));
        }

        if ($isDef === 1) {
            Database::exec('UPDATE `templates` SET `is_default`=0 WHERE `id`>0');
        }

        $data = [
            'name'       => mb_substr($name, 0, 60),
            'channel_id' => DomainService::channel($chId) ? $chId : 0,
            'remark'     => mb_substr($remark, 0, 250),
            'content'    => $content,
            'is_default' => $isDef,
            'updated_at' => Database::now(),
        ];

        if ($id > 0) {
            Database::update('templates', $data, '`id`=?', [$id]);
            audit('编辑海报模板', $name);
            flash('模板已保存。');
        } else {
            $data['created_at'] = Database::now();
            $id = Database::insert('templates', $data);
            // 库里如果还没有默认模板，第一个自动成为默认
            if (Database::value('SELECT COUNT(*) FROM `templates` WHERE `is_default`=1') == 0) {
                Database::update('templates', ['is_default' => 1], '`id`=?', [$id]);
            }
            audit('新增海报模板', $name);
            flash('模板已添加。');
        }
        redirect('templates.php');
    }

    if ($action === 'default') {
        $id = (int) post('id');
        Database::exec('UPDATE `templates` SET `is_default`=0 WHERE `id`>0');
        Database::update('templates', ['is_default' => 1], '`id`=?', [$id]);
        audit('设置默认模板', (string) $id);
        flash('已设为默认模板。');
        redirect('templates.php');
    }

    if ($action === 'delete') {
        $id  = (int) post('id');
        $tpl = DomainService::template($id);
        if (!$tpl) {
            flash('模板不存在。', 'danger');
            redirect('templates.php');
        }
        $used = (int) Database::value('SELECT COUNT(*) FROM `domains` WHERE `template_id`=?', [$id]);
        if ($used > 0) {
            flash('还有 ' . $used . ' 个域名在用这个模板，请先改掉再删除。', 'danger');
            redirect('templates.php');
        }
        Database::exec('DELETE FROM `templates` WHERE `id`=?', [$id]);
        audit('删除海报模板', (string) $tpl['name']);
        flash('模板已删除。');
        redirect('templates.php');
    }
}

$editId = (int) get_param('edit', 0);
$isNew  = (int) get_param('new', 0) === 1;
$edit   = $editId > 0 ? DomainService::template($editId) : null;

$templates = DomainService::templates();
$usage     = [];
foreach (Database::all('SELECT `template_id`,COUNT(*) AS c FROM `domains` WHERE `template_id`>0 GROUP BY `template_id`') as $r) {
    $usage[(int) $r['template_id']] = (int) $r['c'];
}

$channels  = DomainService::channels();
$chUsage   = [];
foreach (Database::all('SELECT `channel_id`,COUNT(*) AS c FROM `templates` WHERE `channel_id`>0 GROUP BY `channel_id`') as $r) {
    $chUsage[(int) $r['channel_id']] = (int) $r['c'];
}
$chName = [];
foreach ($channels as $cid => $c) {
    $chName[$cid] = (string) $c['name'];
}

admin_header('模板与渠道', 'templates', '先建渠道（如谷歌 / Facebook），再让模板归属渠道；入口层域名用哪个模板就走哪个渠道，内容里的 ' . $PLACEHOLDER . ' 会被自动替换成中转层地址。');
?>

<div class="panel">
  <div class="panel-head">
    <div class="panel-title">渠道设置</div>
    <button class="btn sm primary" type="button" onclick="openChannel()">+ 新增渠道</button>
  </div>
  <?php if (!$channels): ?>
    <p class="muted">还没有渠道。先建立渠道（例如：谷歌渠道、Facebook 渠道），再把模板归属到对应渠道。</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>渠道名称</th><th>排序</th><th>模板数</th><th>备注</th><th class="right">操作</th></tr></thead>
      <tbody>
        <?php foreach ($channels as $c): ?>
          <tr>
            <td><b><?= e((string) $c['name']) ?></b></td>
            <td class="muted"><?= (int) $c['sort'] ?></td>
            <td class="muted"><?= (int) ($chUsage[(int) $c['id']] ?? 0) ?> 个</td>
            <td class="muted"><?= e((string) $c['remark']) ?></td>
            <td class="right nowrap">
              <button class="btn sm" type="button"
                onclick='openChannel(<?= json_encode($c, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>编辑</button>
              <button class="btn sm danger" type="button"
                onclick="act('delete_channel', {id:<?= (int) $c['id'] ?>}, 1)">删除</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php if ($isNew || $edit): ?>
<div class="panel">
  <h2><?= $edit ? '编辑模板 · ' . e((string) $edit['name']) : '添加模板' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

    <div class="form-row">
      <div class="col">
        <label>模板名称</label>
        <input type="text" name="name" required value="<?= e((string) post('name', (string) ($edit['name'] ?? ''))) ?>" placeholder="例如：海报模板 1">
      </div>
      <div class="col">
        <label>备注</label>
        <input type="text" name="remark" value="<?= e((string) post('remark', (string) ($edit['remark'] ?? ''))) ?>" placeholder="可选，方便自己辨认">
      </div>
    </div>

    <?php $curCh = (int) post('channel_id', (int) ($edit['channel_id'] ?? 0)); ?>
    <div class="form-row">
      <div class="col">
        <label>所属渠道</label>
        <select name="channel_id">
          <option value="0">未指定</option>
          <?php foreach ($channels as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= $curCh === (int) $c['id'] ? 'selected' : '' ?>><?= e((string) $c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="muted" style="font-size:12px;margin-top:6px">入口层域名用这个模板，就归属该渠道；后台首页按渠道统计今日 / 昨日 / 总数。</div>
      </div>
      <div class="col"></div>
    </div>

    <div class="form-row col">
      <label>模板内容（HTML）</label>
      <textarea name="content" rows="22" spellcheck="false"
                style="font-family:Consolas,Monaco,monospace;font-size:12px;line-height:1.6"
                placeholder="把海报 HTML 整段粘进来，按钮的 href 写成 <?= e($PLACEHOLDER) ?>"><?= e((string) post('content', (string) ($edit['content'] ?? ''))) ?></textarea>
      <div class="muted" style="font-size:12px;margin-top:6px">
        跳转地址占位符：<code><?= e($PLACEHOLDER) ?></code>（也支持 <code>{{url}}</code>、<code>{{中转层地址}}</code>）。
        例如：<code>&lt;a href="<?= e($PLACEHOLDER) ?>"&gt;HEMEN BAŞVUR&lt;/a&gt;</code>
      </div>
    </div>

    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
      <input type="checkbox" name="is_default" value="1" <?= (int) ($edit['is_default'] ?? 0) === 1 ? 'checked' : '' ?> style="width:auto">
      <span class="muted">设为默认模板（入口层域名没有单独指定模板时使用这个）</span>
    </div>

    <div class="mt20">
      <button class="btn primary" type="submit">保存</button>
      <a class="btn" href="templates.php">取消</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head">
    <div class="panel-title">模板列表</div>
    <a class="btn sm primary" href="templates.php?new=1">+ 添加模板</a>
  </div>

  <?php if (!$templates): ?>
    <p class="muted">还没有模板。入口层域名被访问时，若没有可用模板会直接跳转到中转层。</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>名称</th><th>渠道</th><th>备注</th><th>使用域名</th><th>内容</th><th class="right">操作</th></tr></thead>
      <tbody>
        <?php foreach ($templates as $t): ?>
          <tr>
            <td>
              <b><?= e((string) $t['name']) ?></b>
              <?php if ((int) $t['is_default'] === 1): ?><span class="tag orange">默认</span><?php endif; ?>
            </td>
            <td>
              <?php $tcid = (int) ($t['channel_id'] ?? 0); ?>
              <?= $tcid > 0 ? '<span class="tag blue">' . e($chName[$tcid] ?? ('#' . $tcid)) . '</span>' : '<span class="muted">未指定</span>' ?>
            </td>
            <td class="muted"><?= e((string) $t['remark']) ?></td>
            <td class="muted"><?= (int) ($usage[(int) $t['id']] ?? 0) ?> 个</td>
            <td class="muted"><?= number_format(strlen((string) $t['content'])) ?> 字节</td>
            <td class="right nowrap">
              <a class="btn sm" href="templates.php?edit=<?= (int) $t['id'] ?>">编辑</a>
              <?php if ((int) $t['is_default'] !== 1): ?>
                <button class="btn sm" type="button" onclick="act('default', {id:<?= (int) $t['id'] ?>})">设为默认</button>
              <?php endif; ?>
              <button class="btn sm danger" type="button" onclick="act('delete', {id:<?= (int) $t['id'] ?>}, 1)">删除</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <p class="muted mt10" style="font-size:12px">
    每个入口层域名可以单独指定用哪个模板：到「域名池」编辑该域名，在「海报模板」里选择。
    未指定时使用默认模板。
  </p>
</div>

<dialog id="channelDlg">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_channel">
    <input type="hidden" name="id" id="c_id" value="0">
    <div class="dlg-head" id="c_title">新增渠道</div>
    <div class="dlg-body">
      <div class="form-row"><label>渠道名称</label><input type="text" name="name" id="c_name" placeholder="例如：谷歌渠道 / Facebook 渠道" required></div>
      <div class="form-row"><label>排序</label><input type="number" name="sort" id="c_sort" value="0"></div>
      <div class="form-row"><label>备注</label><input type="text" name="remark" id="c_remark"></div>
    </div>
    <div class="dlg-foot">
      <button class="btn" type="button" onclick="document.getElementById('channelDlg').close()">取消</button>
      <button class="btn primary" type="submit">保存</button>
    </div>
  </form>
</dialog>

<script>
function openChannel(c) {
  const isNew = !c;
  document.getElementById('c_title').textContent = isNew ? '新增渠道' : '编辑渠道：' + c.name;
  document.getElementById('c_id').value = isNew ? 0 : c.id;
  document.getElementById('c_name').value = isNew ? '' : c.name;
  document.getElementById('c_sort').value = isNew ? 0 : c.sort;
  document.getElementById('c_remark').value = isNew ? '' : (c.remark || '');
  document.getElementById('channelDlg').showModal();
}

function act(action, fields, confirmFirst) {
  if (confirmFirst && !confirm('确定执行此操作？')) return;
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
