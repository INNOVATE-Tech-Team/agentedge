<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$tab = preg_replace('/[^a-z]/', '', $_GET['tab'] ?? 'core');
$ok  = !empty($_GET['ok']);

// ── Handle POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) die('Invalid CSRF token.');

    $db     = local_db();
    $action = $_POST['action'] ?? '';
    $tab    = preg_replace('/[^a-z]/', '', $_POST['tab'] ?? 'core');

    // Reorder actions — return JSON, no redirect
    if ($action === 'reorder_nav') {
        $ids = array_values(array_filter(array_map('intval', explode(',', $_POST['ids'] ?? ''))));
        $st  = $db->prepare("UPDATE nav_ext_links SET sort_ord=? WHERE id=?");
        foreach ($ids as $i => $id) { if ($id > 0) $st->execute([($i + 1) * 10, $id]); }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]); exit;
    }
    if ($action === 'reorder_mc') {
        $ids = array_values(array_filter(array_map('intval', explode(',', $_POST['ids'] ?? ''))));
        $st  = $db->prepare("UPDATE mc_resource_links SET sort_ord=? WHERE id=?");
        foreach ($ids as $i => $id) { if ($id > 0) $st->execute([($i + 1) * 10, $id]); }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]); exit;
    }
    if ($action === 'reorder_core') {
        $keys = array_filter(array_map(fn($k) => preg_replace('/[^a-z0-9_]/','',$k), explode(',', $_POST['keys'] ?? '')));
        $st   = $db->prepare("INSERT OR REPLACE INTO nav_core_order (key,sort_ord) VALUES (?,?)");
        foreach (array_values($keys) as $i => $k) { if ($k) $st->execute([$k, ($i + 1) * 10]); }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'update_nav') {
        $id         = (int)($_POST['id'] ?? 0);
        $label      = trim($_POST['label'] ?? '');
        $url        = trim($_POST['url'] ?? '') ?: '#';
        $groupLabel = trim($_POST['group_label'] ?? '');
        if ($id && $label) {
            $db->prepare("UPDATE nav_ext_links SET label=?,url=?,group_label=? WHERE id=?")->execute([$label,$url,$groupLabel,$id]);
        }
    } elseif ($action === 'add_nav') {
        $key        = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['key'] ?? '')));
        $label      = trim($_POST['label'] ?? '');
        $url        = trim($_POST['url'] ?? '') ?: '#';
        $groupLabel = trim($_POST['group_label'] ?? '');
        if ($key && $label) {
            $max = (int)$db->query("SELECT COALESCE(MAX(sort_ord),0)+10 FROM nav_ext_links")->fetchColumn();
            try { $db->prepare("INSERT INTO nav_ext_links (key,label,url,sort_ord,group_label) VALUES (?,?,?,?,?)")->execute([$key,$label,$url,$max,$groupLabel]); }
            catch (\Exception $e) {}
        }
    } elseif ($action === 'delete_nav') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) $db->prepare("DELETE FROM nav_ext_links WHERE id=?")->execute([$id]);
    } elseif ($action === 'update_mc') {
        $id    = (int)($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $url   = trim($_POST['url'] ?? '') ?: '#';
        if ($id && $label) {
            $db->prepare("UPDATE mc_resource_links SET label=?,url=? WHERE id=?")->execute([$label,$url,$id]);
        }
    } elseif ($action === 'add_mc') {
        $slug  = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_POST['mc_slug'] ?? '')));
        $label = trim($_POST['label'] ?? '');
        $url   = trim($_POST['url'] ?? '') ?: '#';
        if ($slug && $label) {
            $s = $db->prepare("SELECT COALESCE(MAX(sort_ord),0)+10 FROM mc_resource_links WHERE mc_slug=?");
            $s->execute([$slug]); $max = (int)$s->fetchColumn();
            $db->prepare("INSERT INTO mc_resource_links (mc_slug,label,url,sort_ord) VALUES (?,?,?,?)")->execute([$slug,$label,$url,$max]);
        }
    } elseif ($action === 'delete_mc') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) $db->prepare("DELETE FROM mc_resource_links WHERE id=?")->execute([$id]);
    }

    header('Location: admin_links.php?tab=' . $tab . '&ok=1');
    exit;
}

// ── Load data ──────────────────────────────────────────────────────────────────
$navLinks  = local_db()->query("SELECT * FROM nav_ext_links ORDER BY sort_ord,id")->fetchAll(PDO::FETCH_ASSOC);
$mcRows    = local_db()->query("SELECT * FROM mc_resource_links ORDER BY mc_slug,sort_ord,id")->fetchAll(PDO::FETCH_ASSOC);
$coreOrder = local_db()->query("SELECT * FROM nav_core_order ORDER BY sort_ord")->fetchAll(PDO::FETCH_ASSOC);
$bySlug    = [];
foreach ($mcRows as $r) $bySlug[$r['mc_slug']][] = $r;
ksort($bySlug);

$coreLabels = [
    'dashboard'  => ['label' => 'Dashboard',              'access' => 'All agents'],
    'roster'     => ['label' => 'Agent Roster',           'access' => 'All agents'],
    'network'    => ['label' => 'My Network',             'access' => 'All agents'],
    'onboarding' => ['label' => 'Onboarding',             'access' => 'Admin only'],
    'calendar'   => ['label' => 'Company Calendar',       'access' => 'All agents'],
    'profile'    => ['label' => 'My Profile',             'access' => 'All agents'],
    'hud_submit' => ['label' => 'Submit HUD & Check',     'access' => 'All agents'],
    'docs'       => ['label' => 'Resources',              'access' => 'All agents'],
    'university' => ['label' => 'INNOVATE University',    'access' => 'All agents'],
    'tickets'    => ['label' => 'My Tickets',             'access' => 'All agents'],
];

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Link Settings — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .tabs{display:flex;gap:0;border-bottom:2px solid #E6E7E8;margin-bottom:24px}
    .tab-btn{padding:10px 20px;border:none;background:none;font-size:14px;font-weight:700;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;color:#666}
    .tab-btn.active{color:#000;border-bottom-color:#82C112}
    .tab-pane{display:none}.tab-pane.active{display:block}
    .settings-table{width:100%;border-collapse:collapse;font-size:13px}
    .settings-table th{text-align:left;padding:8px 10px;background:#f5f5f5;border-bottom:2px solid #e0e0e0;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#555}
    .settings-table td{padding:8px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
    .settings-table tr:last-child td{border-bottom:none}
    .settings-table tr.dragging{opacity:.4}
    .settings-table tr.drag-over{outline:2px solid #82C112;outline-offset:-2px}
    .inline-form{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
    .ifield{padding:5px 8px;font-size:12px;border:1px solid #ccc;border-radius:4px}
    .ifield-url{width:220px}
    .ifield-label{width:140px}
    .ifield-key{width:110px}
    .ifield-group{width:100px}
    .btn-save{padding:5px 12px;border:none;background:#82C112;color:#000;font-size:12px;font-weight:700;border-radius:4px;cursor:pointer}
    .btn-del{padding:5px 10px;border:1px solid #ddd;background:white;color:#c00;font-size:12px;border-radius:4px;cursor:pointer}
    .drag-handle{cursor:grab;color:#bbb;font-size:16px;padding:4px 6px;user-select:none;line-height:1}
    .drag-handle:hover{color:#666}
    .drag-handle:active{cursor:grabbing}
    .mc-group{margin-bottom:28px}
    .mc-group-head{display:flex;align-items:center;justify-content:space-between;padding:8px 10px;background:#f9f9f9;border:1px solid #e6e7e8;border-radius:6px 6px 0 0;font-weight:700;font-size:13px}
    .mc-group-body{border:1px solid #e6e7e8;border-top:none;border-radius:0 0 6px 6px;overflow:hidden}
    .mc-row{display:flex;align-items:center;gap:8px;padding:8px 12px;border-bottom:1px solid #f3f3f3;font-size:13px;transition:outline 80ms}
    .mc-row:last-child{border-bottom:none}
    .mc-row.dragging{opacity:.4}
    .mc-row.drag-over{outline:2px solid #82C112;outline-offset:-2px}
    .mc-row .ifield-label{flex:1;min-width:0}
    .mc-row .ifield-url{flex:2;min-width:0}
    .add-mc-form{padding:10px 12px;background:#fafafa;border:1px solid #e6e7e8;border-radius:6px;margin-top:8px}
    .add-mc-form h4{margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#888}
    .flash-ok{padding:10px 14px;background:#eef5e8;border:1px solid #c3dfa8;border-radius:6px;color:#3a6b1a;font-size:13px;margin-bottom:16px}
  </style>
  <script>const CSRF='<?= h($csrf) ?>';</script>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_links', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Link Settings</div>
    </header>
    <main class="wrap">
      <?php if ($ok): ?>
        <div class="flash-ok">Saved successfully.</div>
      <?php endif; ?>

      <div class="card" style="padding:20px 24px">

        <!-- TABS -->
        <div class="tabs">
          <button class="tab-btn<?= $tab==='core'?' active':'' ?>" data-tab="core" onclick="switchTab('core')">Core Menu</button>
          <button class="tab-btn<?= $tab==='nav'?' active':'' ?>"  data-tab="nav"  onclick="switchTab('nav')">External Links</button>
          <button class="tab-btn<?= $tab==='mc'?' active':'' ?>"   data-tab="mc"   onclick="switchTab('mc')">MC Resources</button>
          <button class="tab-btn<?= $tab==='bo'?' active':'' ?>"   data-tab="bo"   onclick="switchTab('bo')">Back Office</button>
        </div>

        <!-- ── TAB: CORE MENU ───────────────────────────────────────────────── -->
        <div id="tab-core" class="tab-pane<?= $tab==='core'?' active':'' ?>">
          <p style="font-size:13px;color:#666;margin:0 0 16px">Drag to change the order these built-in pages appear in the sidebar for all agents. Labels and access rules are fixed.</p>
          <table class="settings-table">
            <thead><tr>
              <th style="width:28px"></th>
              <th>Page</th>
              <th>Visible to</th>
            </tr></thead>
            <tbody id="core-tbody">
            <?php foreach ($coreOrder as $row):
                $info = $coreLabels[$row['key']] ?? ['label' => $row['key'], 'access' => '—'];
            ?>
              <tr class="core-row" data-key="<?= h($row['key']) ?>">
                <td><span class="drag-handle" title="Drag to reorder">⠿</span></td>
                <td style="font-weight:600"><?= h($info['label']) ?></td>
                <td style="font-size:12px;color:#888"><?= h($info['access']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- ── TAB: NAV LINKS ─────────────────────────────────────────────── -->
        <div id="tab-nav" class="tab-pane<?= $tab==='nav'?' active':'' ?>">
          <p style="font-size:13px;color:#666;margin:0 0 16px">
            These links appear in the sidebar for all agents. Drag <span style="font-size:16px">⠿</span> to reorder. Set a real URL to activate; leave <code>#</code> to hide.
          </p>
          <table class="settings-table">
            <thead><tr>
              <th style="width:28px"></th>
              <th>Key</th><th>Label</th><th>URL</th><th>Sub-menu</th><th></th>
            </tr></thead>
            <tbody id="nav-tbody">
            <?php foreach ($navLinks as $row): ?>
              <tr class="nav-row" data-id="<?= $row['id'] ?>">
                <td><span class="drag-handle" title="Drag to reorder">⠿</span></td>
                <td style="color:#888;font-size:11px;font-family:monospace"><?= h($row['key']) ?></td>
                <td colspan="3">
                  <form method="post" action="admin_links.php" class="inline-form">
                    <input type="hidden" name="csrf"   value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="update_nav">
                    <input type="hidden" name="id"     value="<?= $row['id'] ?>">
                    <input type="hidden" name="tab"    value="nav">
                    <input class="ifield ifield-label" name="label"       value="<?= h($row['label']) ?>" required>
                    <input class="ifield ifield-url"   name="url"         value="<?= h($row['url']) ?>"   placeholder="https://...">
                    <input class="ifield ifield-group" name="group_label" value="<?= h($row['group_label'] ?? '') ?>" placeholder="Sub-menu (blank = top-level)">
                    <button class="btn-save" type="submit">Save</button>
                  </form>
                </td>
                <td>
                  <form method="post" action="admin_links.php" onsubmit="return confirm('Remove this nav link?')">
                    <input type="hidden" name="csrf"   value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="delete_nav">
                    <input type="hidden" name="id"     value="<?= $row['id'] ?>">
                    <input type="hidden" name="tab"    value="nav">
                    <button class="btn-del" type="submit">Remove</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <!-- Add new nav link -->
          <div style="margin-top:20px;padding:14px;background:#fafafa;border:1px solid #e6e7e8;border-radius:6px">
            <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:8px">Add nav link</div>
            <form method="post" action="admin_links.php" class="inline-form">
              <input type="hidden" name="csrf"        value="<?= h($csrf) ?>">
              <input type="hidden" name="action"      value="add_nav">
              <input type="hidden" name="tab"         value="nav">
              <input class="ifield ifield-key"        name="key"         placeholder="unique-key" required>
              <input class="ifield ifield-label"      name="label"       placeholder="Label"       required>
              <input class="ifield ifield-url"        name="url"         placeholder="https://...">
              <input class="ifield ifield-group"      name="group_label" placeholder="Sub-menu" value="Links">
              <button class="btn-save" type="submit">Add</button>
            </form>
          </div>

        </div>

        <!-- ── TAB: MC RESOURCE LINKS ────────────────────────────────────── -->
        <div id="tab-mc" class="tab-pane<?= $tab==='mc'?' active':'' ?>">
          <p style="font-size:13px;color:#666;margin:0 0 16px">
            Agents see the links for their own market center in a <strong>My Resources</strong> sidebar section.
            Drag <span style="font-size:16px">⠿</span> to reorder within a market center. Links with URL <code>#</code> are hidden.
          </p>

          <?php foreach ($bySlug as $slug => $links): ?>
          <div class="mc-group">
            <div class="mc-group-head">
              <span><?= h($slug) ?></span>
              <span style="font-size:11px;color:#888;font-weight:400"><?= count($links) ?> link<?= count($links)!==1?'s':'' ?></span>
            </div>
            <div class="mc-group-body">
              <div class="mc-sortable" data-slug="<?= h($slug) ?>">
              <?php foreach ($links as $r): ?>
              <div class="mc-row" data-id="<?= $r['id'] ?>">
                <span class="drag-handle" title="Drag to reorder">⠿</span>
                <form method="post" action="admin_links.php" class="inline-form" style="flex:1">
                  <input type="hidden" name="csrf"   value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="update_mc">
                  <input type="hidden" name="id"     value="<?= $r['id'] ?>">
                  <input type="hidden" name="tab"    value="mc">
                  <input class="ifield ifield-label" name="label" value="<?= h($r['label']) ?>" required>
                  <input class="ifield ifield-url"   name="url"   value="<?= h($r['url']) ?>" placeholder="https://...">
                  <button class="btn-save" type="submit">Save</button>
                </form>
                <form method="post" action="admin_links.php" onsubmit="return confirm('Remove this link?')">
                  <input type="hidden" name="csrf"   value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="delete_mc">
                  <input type="hidden" name="id"     value="<?= $r['id'] ?>">
                  <input type="hidden" name="tab"    value="mc">
                  <button class="btn-del" type="submit">✕</button>
                </form>
              </div>
              <?php endforeach; ?>
              </div>
              <!-- Add link to this MC -->
              <div style="padding:8px 12px;background:#fafafa;border-top:1px solid #f0f0f0">
                <form method="post" action="admin_links.php" class="inline-form">
                  <input type="hidden" name="csrf"    value="<?= h($csrf) ?>">
                  <input type="hidden" name="action"  value="add_mc">
                  <input type="hidden" name="mc_slug" value="<?= h($slug) ?>">
                  <input type="hidden" name="tab"     value="mc">
                  <input class="ifield ifield-label"  name="label" placeholder="Label"    required>
                  <input class="ifield ifield-url"    name="url"   placeholder="https://...">
                  <button class="btn-save" type="submit">+ Add link</button>
                </form>
              </div>
            </div>
          </div>
          <?php endforeach; ?>

          <!-- Add a new MC slug -->
          <div class="add-mc-form">
            <h4>Add a new Market Center</h4>
            <form method="post" action="admin_links.php" class="inline-form">
              <input type="hidden" name="csrf"   value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="add_mc">
              <input type="hidden" name="tab"    value="mc">
              <input class="ifield ifield-key"   name="mc_slug" placeholder="mc-slug (e.g. myrtle-beach)" required>
              <input class="ifield ifield-label" name="label"   placeholder="First link label"            required>
              <input class="ifield ifield-url"   name="url"     placeholder="https://...">
              <button class="btn-save" type="submit">Create MC + Add link</button>
            </form>
            <p style="margin:8px 0 0;font-size:11px;color:#888">
              Slug must match the agent's <code>marketCenter</code> from the CRM, slugified (lowercase, spaces → hyphens).
            </p>
          </div>
        </div>

        <!-- ── TAB: BACK OFFICE ──────────────────────────────────────────────── -->
        <div id="tab-bo" class="tab-pane<?= $tab==='bo'?' active':'' ?>">
          <p style="font-size:13px;color:#666;margin:0 0 20px">
            Add links that appear under the <strong>Back Office</strong> section of the sidebar for admin users.
            Built-in items (Announcements, Tickets, Documents, etc.) always appear and cannot be changed here.
          </p>
          <?php
          $boItems = local_db()->query("SELECT * FROM backoffice_items ORDER BY sort_ord,id")->fetchAll(PDO::FETCH_ASSOC);
          ?>
          <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px;padding:14px;background:#fafafa;border:1px solid var(--border,#e6e7e8);border-radius:6px">
            <div>
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#888;margin-bottom:4px">Label</div>
              <input type="text" id="bo-newLabel" class="ifield ifield-label" placeholder="e.g. Recruiting Reports" style="min-width:200px">
            </div>
            <div>
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#888;margin-bottom:4px">URL</div>
              <input type="text" id="bo-newUrl" class="ifield ifield-url" placeholder="e.g. reports.php or https://...">
            </div>
            <div style="display:flex;align-items:center;gap:6px;padding-bottom:2px">
              <input type="checkbox" id="bo-newExt" style="accent-color:#82C112">
              <label for="bo-newExt" style="font-size:13px;cursor:pointer">New tab</label>
            </div>
            <button class="btn-save" onclick="boAdd()" style="padding:7px 16px">+ Add Item</button>
          </div>

          <table class="settings-table" id="bo-table">
            <thead><tr>
              <th style="width:40px">Order</th>
              <th>Label</th><th>URL</th>
              <th style="width:70px">Ext</th>
              <th style="width:70px">On</th>
              <th style="width:100px"></th>
            </tr></thead>
            <tbody id="bo-tbody">
            <?php if (empty($boItems)): ?>
              <tr id="bo-empty"><td colspan="6" style="text-align:center;color:#aaa;padding:24px;font-style:italic">No custom items yet.</td></tr>
            <?php else: ?>
              <?php foreach ($boItems as $r): ?>
              <tr id="bo-row-<?= $r['id'] ?>" data-id="<?= $r['id'] ?>">
                <td>
                  <button class="btn-del" style="font-size:11px;border:1px solid #ddd;padding:2px 6px" onclick="boMove(<?= $r['id'] ?>,-1)">↑</button>
                  <button class="btn-del" style="font-size:11px;border:1px solid #ddd;padding:2px 6px" onclick="boMove(<?= $r['id'] ?>, 1)">↓</button>
                </td>
                <td><input class="ifield" style="width:100%" type="text" value="<?= h($r['label']) ?>" data-field="label" data-id="<?= $r['id'] ?>" oninput="boMark(<?= $r['id'] ?>)"></td>
                <td><input class="ifield ifield-url" type="text" value="<?= h($r['url']) ?>"   data-field="url"   data-id="<?= $r['id'] ?>" oninput="boMark(<?= $r['id'] ?>)"></td>
                <td style="text-align:center"><input type="checkbox" style="accent-color:#82C112" data-field="is_ext"  data-id="<?= $r['id'] ?>" <?= $r['is_ext'] ?'checked':'' ?> onchange="boMark(<?= $r['id'] ?>)"></td>
                <td style="text-align:center"><input type="checkbox" style="accent-color:#82C112" data-field="enabled" data-id="<?= $r['id'] ?>" <?= $r['enabled']?'checked':'' ?> onchange="boMark(<?= $r['id'] ?>)"></td>
                <td>
                  <button class="btn-save" id="bo-save-<?= $r['id'] ?>" style="display:none" onclick="boSave(<?= $r['id'] ?>)">Save</button>
                  <button class="btn-del" onclick="boDel(<?= $r['id'] ?>)">✕</button>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </main>
  </div>
</div>
<script>
function switchTab(t) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === t));
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.toggle('active', p.id === 'tab-' + t));
  history.replaceState(null, '', 'admin_links.php?tab=' + t);
}

// ── Drag-to-reorder ─────────────────────────────────────────────────────────
function initDragSort(container, rowSelector, onSave) {
  let dragging = null;

  function getRows() { return [...container.querySelectorAll(rowSelector)]; }

  container.addEventListener('dragstart', e => {
    const row = e.target.closest(rowSelector);
    if (!row) return;
    dragging = row;
    row.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', row.dataset.id || row.dataset.key || '');
  });

  container.addEventListener('dragend', e => {
    const row = e.target.closest(rowSelector);
    if (!row) return;
    row.classList.remove('dragging');
    container.querySelectorAll(rowSelector).forEach(r => r.classList.remove('drag-over'));
    dragging = null;
    onSave(getRows());
  });

  container.addEventListener('dragover', e => {
    e.preventDefault();
    if (!dragging) return;
    const row = e.target.closest(rowSelector);
    if (!row || row === dragging) return;
    container.querySelectorAll(rowSelector).forEach(r => r.classList.remove('drag-over'));
    row.classList.add('drag-over');
    const rect = row.getBoundingClientRect();
    const after = e.clientY > rect.top + rect.height / 2;
    if (after) row.after(dragging); else row.before(dragging);
  });

  container.addEventListener('drop', e => e.preventDefault());

  container.addEventListener('mousedown', e => {
    const handle = e.target.closest('.drag-handle');
    if (!handle) return;
    const row = handle.closest(rowSelector);
    if (row) row.setAttribute('draggable', 'true');
  });
  container.addEventListener('mouseup', e => {
    getRows().forEach(r => r.removeAttribute('draggable'));
  });
}

function postOrder(params) {
  fetch('admin_links.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams({csrf: CSRF, ...params}),
  }).catch(() => {});
}

// Nav links table
const navTbody = document.getElementById('nav-tbody');
if (navTbody) initDragSort(navTbody, 'tr.nav-row', rows => {
  const ids = rows.map(r => r.dataset.id).filter(Boolean).join(',');
  if (ids) postOrder({action: 'reorder_nav', ids});
});

// MC resource links (one sortable per MC group)
document.querySelectorAll('.mc-sortable').forEach(wrap => {
  initDragSort(wrap, '.mc-row', rows => {
    const ids = rows.map(r => r.dataset.id).filter(Boolean).join(',');
    if (ids) postOrder({action: 'reorder_mc', ids});
  });
});

// Core page order
const coreTbody = document.getElementById('core-tbody');
if (coreTbody) initDragSort(coreTbody, 'tr.core-row', rows => {
  const keys = rows.map(r => r.dataset.key).filter(Boolean).join(',');
  if (keys) postOrder({action: 'reorder_core', keys});
});

// ── Back Office tab ──────────────────────────────────────────────────────────
function boApi(body) {
  return fetch('api/backoffice_menu.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(body)
  }).then(r => r.json());
}
function boMark(id) { document.getElementById('bo-save-' + id).style.display = 'inline-block'; }
function boSave(id) {
  const row = document.getElementById('bo-row-' + id);
  const label   = row.querySelector('[data-field="label"]').value.trim();
  const url     = row.querySelector('[data-field="url"]').value.trim();
  const is_ext  = row.querySelector('[data-field="is_ext"]').checked ? 1 : 0;
  const enabled = row.querySelector('[data-field="enabled"]').checked ? 1 : 0;
  if (!label || !url) return alert('Label and URL required.');
  boApi({action:'update', id, label, url, is_ext, enabled}).then(d => {
    if (d.ok) document.getElementById('bo-save-' + id).style.display = 'none';
  });
}
function boDel(id) {
  if (!confirm('Remove this Back Office item?')) return;
  boApi({action:'delete', id}).then(d => {
    if (!d.ok) return;
    const row = document.getElementById('bo-row-' + id);
    if (row) row.remove();
    if (!document.querySelector('#bo-tbody tr[data-id]')) {
      document.getElementById('bo-tbody').innerHTML =
        '<tr id="bo-empty"><td colspan="6" style="text-align:center;color:#aaa;padding:24px;font-style:italic">No custom items yet.</td></tr>';
    }
  });
}
function boMove(id, dir) {
  boApi({action:'move', id, dir}).then(d => { if (d.ok) location.reload(); });
}
function boAdd() {
  const label  = document.getElementById('bo-newLabel').value.trim();
  const url    = document.getElementById('bo-newUrl').value.trim();
  const is_ext = document.getElementById('bo-newExt').checked ? 1 : 0;
  if (!label || !url) return alert('Label and URL required.');
  boApi({action:'add', label, url, is_ext}).then(d => {
    if (!d.ok || !d.item) return;
    const item = d.item;
    const emp = document.getElementById('bo-empty');
    if (emp) emp.remove();
    const tbody = document.getElementById('bo-tbody');
    const tr = document.createElement('tr');
    tr.id = 'bo-row-' + item.id;
    tr.dataset.id = item.id;
    tr.innerHTML = `
      <td>
        <button class="btn-del" style="font-size:11px;border:1px solid #ddd;padding:2px 6px" onclick="boMove(${item.id},-1)">↑</button>
        <button class="btn-del" style="font-size:11px;border:1px solid #ddd;padding:2px 6px" onclick="boMove(${item.id}, 1)">↓</button>
      </td>
      <td><input class="ifield" style="width:100%" type="text" value="${esc(item.label)}" data-field="label" data-id="${item.id}" oninput="boMark(${item.id})"></td>
      <td><input class="ifield ifield-url" type="text" value="${esc(item.url)}" data-field="url" data-id="${item.id}" oninput="boMark(${item.id})"></td>
      <td style="text-align:center"><input type="checkbox" style="accent-color:#82C112" data-field="is_ext"  data-id="${item.id}" ${item.is_ext?'checked':''} onchange="boMark(${item.id})"></td>
      <td style="text-align:center"><input type="checkbox" style="accent-color:#82C112" data-field="enabled" data-id="${item.id}" checked onchange="boMark(${item.id})"></td>
      <td>
        <button class="btn-save" id="bo-save-${item.id}" style="display:none" onclick="boSave(${item.id})">Save</button>
        <button class="btn-del" onclick="boDel(${item.id})">✕</button>
      </td>`;
    tbody.appendChild(tr);
    document.getElementById('bo-newLabel').value = '';
    document.getElementById('bo-newUrl').value   = '';
    document.getElementById('bo-newExt').checked = false;
  });
}
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
</script>
</body>
</html>
