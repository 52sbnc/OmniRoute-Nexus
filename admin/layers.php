<?php
/**
 * 层级与池子管理
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) post('action');

    if ($action === 'save_layer') {
        $id = (int) post('id');
        $data = [
            'name'           => mb_substr((string) post('name'), 0, 50),
            'sort'           => (int) post('sort'),
            'active_count'   => max(1, (int) post('active_count', 1)),
            'rotate_days'    => max(0, (int) post('rotate_days')),
            'observe_days'   => max(0, (int) post('observe_days')),
            'strategy'       => in_array(post('strategy'), ['fixed', 'random', 'weight', 'poll'], true) ? (string) post('strategy') : 'random',
            'fallback_url'   => (string) post('fallback_url'),
            'check_enabled'  => post('check_enabled') === '1' ? 1 : 0,
            'check_interval' => max(10, (int) post('check_interval', 60)),
            'remark'         => mb_substr((string) post('remark'), 0, 250),
            'updated_at'     => Database::now(),
        ];
        if ($data['name'] === '') {
            flash('层级名称不能为空。', 'danger');
            redirect('layers.php');
        }
        if ($id > 0) {
            Database::update('layers', $data, '`id`=?', [$id]);
            audit('修改层级配置', $data['name']);
            flash('层级「' . $data['name'] . '」已保存。');
        } else {
            $data['created_at'] = Database::now();
            Database::insert('layers', $data);
            audit('新增层级', $data['name']);
            flash('层级已新增。');
        }
        DomainService::rebuild();
        redirect('layers.php');
    }

    if ($action === 'delete_layer') {
        $id = (int) post('id');
        $layer = DomainService::layer($id);
        if (!$layer) {
            flash('层级不存在。', 'danger');
            redirect('layers.php');
        }
        if ((int) $layer['is_final'] === 1) {
            flash('最终落地层不可删除。', 'danger');
            redirect('layers.php');
        }
        $cnt = (int) Database::value('SELECT COUNT(*) FROM `domains` WHERE `layer_id`=?', [$id]);
        if ($cnt > 0) {
            flash('该层级下仍有 ' . $cnt . ' 个域名，请先移除后再删除层级。', 'danger');
            redirect('layers.php');
        }
        Database::exec('DELETE FROM `layers` WHERE `id`=?', [$id]);
        Database::exec('DELETE FROM `pools` WHERE `layer_id`=?', [$id]);
        audit('删除层级', (string) $layer['name']);
        flash('层级已删除。');
        DomainService::rebuild();
        redirect('layers.php');
    }

    if ($action === 'save_pool') {
        $id      = (int) post('id');
        $layerId = (int) post('layer_id');
        $data    = [
            'layer_id'   => $layerId,
            'name'       => mb_substr((string) post('name'), 0, 60),
            'strategy'   => in_array(post('strategy'), ['fixed', 'random', 'weight', 'poll'], true) ? (string) post('strategy') : 'random',
            'remark'     => mb_substr((string) post('remark'), 0, 250),
            'updated_at' => Database::now(),
        ];
        if ($data['name'] === '' || !DomainService::layer($layerId)) {
            flash('请填写池子名称并选择层级。', 'danger');
            redirect('layers.php');
        }
        if ($id > 0) {
            Database::update('pools', $data, '`id`=?', [$id]);
            flash('域名池已更新。');
        } else {
            $data['created_at'] = Database::now();
            Database::insert('pools', $data);
            flash('域名池已添加。');
        }
        audit('保存域名池', $data['name']);
        redirect('layers.php');
    }

    if ($action === 'delete_pool') {
        $id = (int) post('id');
        $cnt = (int) Database::value('SELECT COUNT(*) FROM `domains` WHERE `pool_id`=?', [$id]);
        if ($cnt > 0) {
            Database::exec('UPDATE `domains` SET `pool_id`=0 WHERE `pool_id`=?', [$id]);
        }
        Database::exec('DELETE FROM `pools` WHERE `id`=?', [$id]);
        audit('删除域名池', 'ID ' . $id . '（' . $cnt . ' 个域名已解除绑定）');
        flash('域名池已删除，原有域名已解除绑定。');
        redirect('layers.php');
    }
}

$layers = DomainService::layers();
$pools  = DomainService::pools();
$counts = [];
foreach (Database::all('SELECT `layer_id`, COUNT(*) c FROM `domains` GROUP BY `layer_id`') as $r) {
    $counts[(int) $r['layer_id']] = (int) $r['c'];
}

admin_header('层级与池子', 'layers', '层级参数决定轮换、观察期、禁用周期与跳转策略，域名池用于归类管理。');
?>

<div class="panel">
  <div class="panel-title">
    <h2>层级配置</h2>
    <button class="btn sm" type="button" onclick="openLayer()">+ 新增层级</button>
  </div>
  <table class="table">
    <thead>
      <tr>
        <th>层级</th><th>域名数</th><th>同时使用</th><th>使用期</th><th>到期保留</th>
        <th>跳转策略</th><th>实时检测</th><th class="right">操作</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($layers as $l): ?>
        <tr>
          <td><b><?= e($l['name']) ?></b><?= (int) $l['is_final'] === 1 ? ' <span class="tag blue">最终层</span>' : '' ?></td>
          <td><?= (int) ($counts[(int) $l['id']] ?? 0) ?></td>
          <td><?= (int) $l['id'] === 1 ? '不限' : (int) $l['active_count'] ?></td>
          <td><?= (int) $l['id'] === 1 ? '不限' : ((int) $l['rotate_days'] > 0 ? (int) $l['rotate_days'] . ' 天' : '不限') ?></td>
          <td><?= (int) $l['id'] === 1 ? '-' : ((int) $l['observe_days'] > 0 ? (int) $l['observe_days'] . ' 天' : '-') ?></td>
          <td><?= e(['fixed' => '固定', 'random' => '随机', 'weight' => '权重', 'poll' => '轮询'][$l['strategy']] ?? $l['strategy']) ?></td>
          <td>
            <?= (int) $l['check_enabled'] === 1
                ? '<span class="tag green">开启 · ' . (int) $l['check_interval'] . 's</span>'
                : '<span class="tag gray">关闭</span>' ?>
          </td>
          <td class="right nowrap">
            <button class="btn sm" type="button"
              onclick='openLayer(<?= json_encode($l, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>配置</button>
            <?php if ((int) $l['is_final'] === 0): ?>
              <button class="btn sm danger" type="button"
                onclick="act('delete_layer', <?= (int) $l['id'] ?>, '确定删除该层级？')">删除</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="panel">
  <div class="panel-title">
    <h2>域名池</h2>
    <button class="btn sm" type="button" onclick="openPool()">+ 新增池子</button>
  </div>
  <table class="table">
    <thead><tr><th>池名称</th><th>所属层级</th><th>选取策略</th><th>备注</th><th class="right">操作</th></tr></thead>
    <tbody>
      <?php if (!$pools): ?><tr><td colspan="5" class="empty">暂无域名池。</td></tr><?php endif; ?>
      <?php foreach ($pools as $p): ?>
        <tr>
          <td><b><?= e($p['name']) ?></b></td>
          <td><?= e((string) (DomainService::layer((int) $p['layer_id'])['name'] ?? '-')) ?></td>
          <td><?= e(['fixed' => '固定', 'random' => '随机', 'weight' => '权重', 'poll' => '轮询'][$p['strategy']] ?? $p['strategy']) ?></td>
          <td class="muted"><?= e((string) $p['remark']) ?></td>
          <td class="right nowrap">
            <button class="btn sm" type="button"
              onclick='openPool(<?= json_encode($p, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>编辑</button>
            <button class="btn sm danger" type="button"
              onclick="act('delete_pool', <?= (int) $p['id'] ?>, '确定删除该域名池？池内域名将解除绑定。')">删除</button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<template id="layerOptions">
  <?php foreach ($layers as $l): ?>
    <option value="<?= (int) $l['id'] ?>"><?= e($l['name']) ?></option>
  <?php endforeach; ?>
</template>

<dialog id="layerDlg">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_layer">
    <input type="hidden" name="id" id="l_id" value="0">
    <div class="dlg-head" id="l_title">配置层级</div>
    <div class="dlg-body">
      <div class="form-row"><label>层级名称</label><input type="text" name="name" id="l_name" required></div>
      <div class="form-row"><label>排序</label><input type="number" name="sort" id="l_sort" value="0"></div>
      <div class="form-row"><label>同时使用数量</label><input type="number" name="active_count" id="l_active_count" value="1" min="1"><span class="hint">该层同时在线的域名个数；第 1 层入口层不限制在线数量，每个域名可单独启用 / 关闭，此项不生效</span></div>
      <div class="form-row"><label>使用期（天）</label><input type="number" name="rotate_days" id="l_rotate_days" value="0" min="0"><span class="hint">投入使用的天数，到期后自动进入「已到期」；0 表示不限</span></div>
      <div class="form-row"><label>到期保留（天）</label><input type="number" name="observe_days" id="l_observe_days" value="0" min="0"><span class="hint">已到期后再保留的天数；入口层到期后转「待处理」，其它层恢复「待使用」</span></div>
      <div class="form-row"><label>跳转策略</label>
        <select name="strategy" id="l_strategy">
          <option value="fixed">固定（顺序）</option>
          <option value="random">随机</option>
          <option value="weight">权重</option>
          <option value="poll">轮询</option>
        </select>
      </div>
      <div class="form-row"><label>本层兜底地址</label><input type="text" name="fallback_url" id="l_fallback_url" placeholder="留空则使用全局兜底地址"></div>
      <div class="form-row"><label>实时检测</label>
        <select name="check_enabled" id="l_check_enabled"><option value="1">开启</option><option value="0">关闭</option></select>
      </div>
      <div class="form-row"><label>检测频率（秒）</label><input type="number" name="check_interval" id="l_check_interval" value="300" min="10"><span class="hint">实际巡检周期由 task.php 决定（每 5 分钟执行一次）</span></div>
      <div class="form-row"><label>备注</label><input type="text" name="remark" id="l_remark"></div>
    </div>
    <div class="dlg-foot">
      <button class="btn" type="button" onclick="document.getElementById('layerDlg').close()">取消</button>
      <button class="btn primary" type="submit">保存</button>
    </div>
  </form>
</dialog>

<dialog id="poolDlg">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_pool">
    <input type="hidden" name="id" id="p_id" value="0">
    <div class="dlg-head">域名池</div>
    <div class="dlg-body">
      <div class="form-row"><label>池名称</label><input type="text" name="name" id="p_name" required></div>
      <div class="form-row"><label>所属层级</label><select name="layer_id" id="p_layer"></select></div>
      <div class="form-row"><label>选取策略</label>
        <select name="strategy" id="p_strategy">
          <option value="random">随机</option>
          <option value="fixed">固定（顺序）</option>
          <option value="weight">权重</option>
          <option value="poll">轮询</option>
        </select>
      </div>
      <div class="form-row"><label>备注</label><input type="text" name="remark" id="p_remark"></div>
    </div>
    <div class="dlg-foot">
      <button class="btn" type="button" onclick="document.getElementById('poolDlg').close()">取消</button>
      <button class="btn primary" type="submit">保存</button>
    </div>
  </form>
</dialog>

<script>
const LAYER_OPTS = document.getElementById('layerOptions').innerHTML;

function openLayer(l) {
  const isNew = !l;
  document.getElementById('l_title').textContent = isNew ? '新增层级' : '配置层级：' + l.name;
  document.getElementById('l_id').value = isNew ? 0 : l.id;
  const v = (id, val) => document.getElementById(id).value = val;
  v('l_name', isNew ? '' : l.name);
  v('l_sort', isNew ? 9 : l.sort);
  v('l_active_count', isNew ? 1 : l.active_count);
  v('l_rotate_days', isNew ? 0 : l.rotate_days);
  v('l_observe_days', isNew ? 0 : l.observe_days);
  v('l_strategy', isNew ? 'random' : l.strategy);
  v('l_fallback_url', isNew ? '' : (l.fallback_url || ''));
  v('l_check_enabled', isNew ? '1' : l.check_enabled);
  v('l_check_interval', isNew ? 300 : l.check_interval);
  v('l_remark', isNew ? '' : (l.remark || ''));
  document.getElementById('layerDlg').showModal();
}

function openPool(p) {
  const isNew = !p;
  document.getElementById('p_id').value = isNew ? 0 : p.id;
  document.getElementById('p_name').value = isNew ? '' : p.name;
  document.getElementById('p_strategy').value = isNew ? 'random' : p.strategy;
  document.getElementById('p_remark').value = isNew ? '' : (p.remark || '');
  const sel = document.getElementById('p_layer');
  sel.innerHTML = LAYER_OPTS;
  if (!isNew) sel.value = p.layer_id;
  document.getElementById('poolDlg').showModal();
}

function act(action, id, msg) {
  if (msg && !confirm(msg)) return;
  const f = document.createElement('form');
  f.method = 'post'; f.style.display = 'none';
  const add = (k, val) => { const i = document.createElement('input'); i.type='hidden'; i.name=k; i.value=val; f.appendChild(i); };
  add('_token', '<?= csrf_token() ?>');
  add('action', action);
  add('id', id);
  document.body.appendChild(f);
  f.submit();
}
</script>

<?php admin_footer(); ?>
