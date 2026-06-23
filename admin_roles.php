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

// ── Fetch roster + known MCs from CRM ──────────────────────────────────────
$c     = cfg();
$base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
$token = $c['crm_token'] ?? '';
$url   = $base . '/public/retention-roster' . ($token ? '?token=' . urlencode($token) : '');
$ctx   = stream_context_create(['http' => ['timeout' => 12, 'header' => "Accept: application/json\r\n"]]);
$raw   = @file_get_contents($url, false, $ctx);
$roster = ($raw !== false) ? (json_decode($raw, true) ?? []) : [];

$rosterByEmail = [];
$mc_opts       = [];
foreach ($roster as $a) {
    $mc    = $a['marketCenter'] ?? '';
    if ($mc === '' && !empty($a['marketCenters'])) $mc = $a['marketCenters'][0]['name'] ?? '';
    $email = strtolower(trim($a['email'] ?? ''));
    if (!$email) continue;
    $slug  = slugify_mc($mc);
    if ($mc && $slug && !isset($mc_opts[$slug])) $mc_opts[$slug] = $mc;
    $rosterByEmail[$email] = ['email' => $email, 'name' => $a['fullName'] ?? $email, 'mc' => $mc];
}
// Merge in market centers added manually (no agents required)
foreach (local_db()->query("SELECT slug, name FROM market_centers WHERE enabled=1 ORDER BY sort_ord, name")->fetchAll(PDO::FETCH_ASSOC) as $mc) {
    if (!isset($mc_opts[$mc['slug']])) $mc_opts[$mc['slug']] = $mc['name'];
}
ksort($mc_opts);

// Load all rows that have a special role OR placement data.
$roleRows = local_db()->query(
    "SELECT email, role, mc_slugs, own_mc_slug, bic_email, updated_at
     FROM agent_roles
     WHERE role != 'agent' OR own_mc_slug != '' OR bic_email != ''
     ORDER BY email"
)->fetchAll(PDO::FETCH_ASSOC);
$assigned = [];
foreach ($roleRows as $r) $assigned[strtolower($r['email'])] = $r;

// Build list of BICs for the bic_email dropdown.
$bicList = local_db()->query(
    "SELECT email FROM agent_roles WHERE role='bic' ORDER BY email"
)->fetchAll(PDO::FETCH_COLUMN);

// ── Handle POST ─────────────────────────────────────────────────────────────
$saved     = null;
$saveError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) die('Invalid CSRF token.');

    $action = $_POST['action'] ?? 'save';
    $email  = strtolower(trim($_POST['email'] ?? ''));

    if ($action === 'remove' && $email) {
        try {
            local_db()->prepare("DELETE FROM agent_roles WHERE email=?")->execute([$email]);
            unset($assigned[$email]);
            $saved = 'removed';
        } catch (\Throwable $e) { $saveError = $e->getMessage(); }
    } elseif ($email) {
        $role = preg_replace('/[^a-z_]/', '', $_POST['role'] ?? 'agent');
        if (!isset(ROLE_LABELS[$role])) $role = 'agent';

        $mcs = [];
        if (in_array($role, ['bic', 'mc_leader'], true) && !empty($_POST['mc_slugs'])) {
            foreach ((array)$_POST['mc_slugs'] as $s) {
                $s = preg_replace('/[^a-z0-9\-]/', '', $s);
                if ($s) $mcs[] = $s;
            }
        }

        $ownMcSlug = preg_replace('/[^a-z0-9\-]/', '', $_POST['own_mc_slug'] ?? '');
        $bicEmail  = strtolower(trim($_POST['bic_email'] ?? ''));
        // Only agents get a bic_email assignment; leaders/admins don't need one.
        if (in_array($role, ['super_admin', 'staff', 'mc_leader', 'bic', 'recruiter'], true)) {
            $bicEmail = '';
        }

        try {
            $json = json_encode(array_values(array_unique($mcs)));
            local_db()->prepare(
                "INSERT INTO agent_roles (email, role, mc_slugs, own_mc_slug, bic_email, updated_by, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
                 ON CONFLICT(email) DO UPDATE SET
                   role=excluded.role, mc_slugs=excluded.mc_slugs,
                   own_mc_slug=excluded.own_mc_slug, bic_email=excluded.bic_email,
                   updated_by=excluded.updated_by, updated_at=excluded.updated_at"
            )->execute([$email, $role, $json, $ownMcSlug, $bicEmail, strtolower($agent['email'])]);

            if ($role === 'agent' && $ownMcSlug === '' && $bicEmail === '') {
                local_db()->prepare("DELETE FROM agent_roles WHERE email=?")->execute([$email]);
                unset($assigned[$email]);
            } else {
                $assigned[$email] = [
                    'email'        => $email,
                    'role'         => $role,
                    'mc_slugs'     => $json,
                    'own_mc_slug'  => $ownMcSlug,
                    'bic_email'    => $bicEmail,
                    'updated_at'   => date('Y-m-d H:i:s'),
                ];
            }
            $saved = $email;
            if (strtolower($agent['email']) === $email) unset($_SESSION['perms']);
        } catch (\Throwable $e) { $saveError = $e->getMessage(); }
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Role Assignments — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .role-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
    .role-agent{background:#f0f0f0;color:#666}
    .role-mc_leader{background:#eef5e8;color:#5b8e0d}
    .role-bic{background:#fff4e0;color:#a07221}
    .role-staff{background:#e8f0ff;color:#2255cc}
    .role-recruiter{background:#f5e8ff;color:#7a22cc}
    .role-super_admin{background:#000;color:#82C112}
    .mc-chips{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px}
    .mc-chip{font-size:10px;padding:2px 6px;border-radius:3px;background:#eef5e8;color:#5b8e0d;font-weight:600}
    .place-chip{font-size:10px;padding:2px 6px;border-radius:3px;background:#fff3e0;color:#a05000;font-weight:600;margin-top:2px;display:inline-block}
    .assign-table{width:100%;border-collapse:collapse;font-size:13px}
    .assign-table th{text-align:left;padding:9px 12px;background:#f5f5f5;border-bottom:2px solid #e0e0e0;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#555}
    .assign-table td{padding:9px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
    .assign-table tr:last-child td{border-bottom:none}
    .assign-table tr.edit-row td{padding:0;background:#f9fdf5;border-bottom:2px solid #82C112}
    .edit-panel{padding:16px 20px}
    .edit-panel h4{margin:0 0 12px;font-size:13px;font-weight:700}
    .form-grid{display:grid;grid-template-columns:180px 1fr 1fr;gap:16px;align-items:start}
    .field-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#666;margin-bottom:4px}
    .field-select{padding:7px 10px;font-size:13px;border:1px solid #ccc;border-radius:4px;background:white;width:100%}
    .mc-checks{display:flex;flex-wrap:wrap;gap:8px 16px}
    .mc-check{display:flex;align-items:center;gap:5px;font-size:12px;cursor:pointer}
    .mc-check input{accent-color:#82C112}
    .btn-save{padding:8px 18px;border:none;background:#82C112;color:#000;font-size:13px;font-weight:700;border-radius:4px;cursor:pointer}
    .btn-cancel{padding:8px 14px;border:1px solid #ccc;background:white;color:#555;font-size:13px;border-radius:4px;cursor:pointer;margin-left:6px}
    .btn-remove{padding:5px 10px;border:1px solid #ddd;background:white;color:#c00;font-size:12px;border-radius:4px;cursor:pointer}
    .btn-edit{padding:5px 10px;border:1px solid #ddd;background:white;color:#333;font-size:12px;border-radius:4px;cursor:pointer}
    .btn-loginas{padding:5px 10px;border:1px solid #82C112;background:white;color:#5b8e0d;font-size:12px;font-weight:700;border-radius:4px;cursor:pointer}
    .btn-loginas:hover{background:#f9fdf5}
    .mc-led-section{display:none}.mc-led-section.visible{display:block}
    .flash-ok{padding:10px 14px;background:#eef5e8;border:1px solid #c3dfa8;border-radius:6px;color:#3a6b1a;font-size:13px;margin-bottom:16px}
    .flash-err{padding:10px 14px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:6px;color:#c00;font-size:13px;margin-bottom:16px}
    .assign-search{display:flex;gap:0;margin-bottom:4px}
    .assign-search input{flex:1;padding:9px 12px;font-size:13px;border:1px solid #ccc;border-radius:6px;outline:none}
    .assign-search input:focus{border-color:#82C112}
    .search-results{border:1px solid #e0e0e0;border-top:none;border-radius:0 0 6px 6px;max-height:220px;overflow-y:auto;display:none;background:white;margin-bottom:20px}
    .search-result-row{display:flex;align-items:center;justify-content:space-between;padding:9px 14px;border-bottom:1px solid #f3f3f3;font-size:13px;cursor:pointer}
    .search-result-row:last-child{border-bottom:none}
    .search-result-row:hover{background:#f9fdf5}
    .sr-name{font-weight:600}
    .sr-meta{font-size:11px;color:#888}
    .sr-assign{padding:4px 12px;border:none;background:#82C112;color:#000;font-size:12px;font-weight:700;border-radius:4px;cursor:pointer}
    .assign-form-panel{background:#f9fdf5;border:1px solid #c3dfa8;border-radius:8px;padding:16px 20px;margin-bottom:20px;display:none}
    .assign-form-panel h4{margin:0 0 2px;font-size:14px;font-weight:700}
    .assign-form-panel .sub{font-size:12px;color:#666;margin-bottom:14px}
    .section-divider{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#aaa;margin:16px 0 6px;border-top:1px solid #eee;padding-top:12px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_roles', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Role Assignments</div>
    </header>
    <main class="wrap">

      <?php if ($saveError): ?>
        <div class="flash-err">Save failed: <?= h($saveError) ?></div>
      <?php elseif ($saved === 'removed'): ?>
        <div class="flash-ok">Role removed — agent reverts to default.</div>
      <?php elseif ($saved): ?>
        <div class="flash-ok">Saved settings for <strong><?= h($saved) ?></strong>.</div>
      <?php endif; ?>

      <div class="card" style="padding:20px 24px">
        <p style="font-size:13px;color:#555;margin:0 0 16px">
          Assign roles and set each agent's Market Center and BIC so announcements reach the right people. Changes take effect within 5 minutes (or immediately on next login).
        </p>

        <!-- ── SEARCH TO ASSIGN ─────────────────────────────────────────── -->
        <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:6px">Assign a role or set placement for an agent</div>
        <div class="assign-search">
          <input type="text" id="agent-search" placeholder="Search by name, email, or market center…" autocomplete="off" oninput="searchAgents(this.value)">
        </div>
        <div class="search-results" id="search-results"></div>

        <!-- ── ASSIGN FORM ─────────────────────────────────────────────── -->
        <div class="assign-form-panel" id="assign-panel">
          <h4 id="assign-name"></h4>
          <div class="sub" id="assign-sub"></div>
          <form method="post" action="admin_roles.php" id="assign-form">
            <input type="hidden" name="csrf"  value="<?= h($csrf) ?>">
            <input type="hidden" name="email" id="assign-email" value="">
            <div class="form-grid">
              <div>
                <div class="field-label">Role</div>
                <select name="role" id="assign-role" class="field-select" onchange="onRoleChange(this,'assign-mc-led','assign-bic-row')">
                  <?php foreach (ROLE_LABELS as $k => $lbl): ?>
                    <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <div class="field-label">Their Market Center</div>
                <select name="own_mc_slug" id="assign-own-mc" class="field-select">
                  <option value="">— not set —</option>
                  <?php foreach ($mc_opts as $slug => $name): ?>
                    <option value="<?= h($slug) ?>"><?= h($name) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div id="assign-bic-row">
                <div class="field-label">Assigned BIC</div>
                <?php if ($bicList): ?>
                  <select name="bic_email" id="assign-bic-email" class="field-select">
                    <option value="">— not set —</option>
                    <?php foreach ($bicList as $be): ?>
                      <option value="<?= h($be) ?>"><?= h($rosterByEmail[$be]['name'] ?? $be) ?> (<?= h($be) ?>)</option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <input type="text" name="bic_email" id="assign-bic-email" class="field-select" placeholder="bic@example.com" style="font-size:12px">
                <?php endif; ?>
              </div>
            </div>
            <div id="assign-mc-led" class="mc-led-section" style="margin-top:12px">
              <div class="field-label">Market Centers They Lead</div>
              <div class="mc-checks">
                <?php foreach ($mc_opts as $slug => $name): ?>
                  <label class="mc-check">
                    <input type="checkbox" name="mc_slugs[]" value="<?= h($slug) ?>">
                    <?= h($name) ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <div style="margin-top:14px">
              <button class="btn-save" type="submit">Save</button>
              <button class="btn-cancel" type="button" onclick="closeAssign()">Cancel</button>
            </div>
          </form>
        </div>

        <!-- ── ASSIGNED ROLES TABLE ─────────────────────────────────────── -->
        <?php if (empty($assigned)): ?>
          <div style="padding:20px;text-align:center;color:#888;font-size:13px;border:1px dashed #ccc;border-radius:8px">
            No roles or placements assigned yet. Search above to get started.
          </div>
        <?php else: ?>
        <table class="assign-table">
          <thead>
            <tr><th>Name</th><th>Role</th><th>Own MC / BIC</th><th>Led MCs</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($assigned as $lcemail => $r):
            $info      = $rosterByEmail[$lcemail] ?? null;
            $role      = canonical_role($r['role']);
            $mcs       = json_decode($r['mc_slugs'] ?? '[]', true) ?: [];
            $ownMc     = $r['own_mc_slug'] ?? '';
            $bicEmail  = $r['bic_email']   ?? '';
            $rowId     = 'edit-' . md5($lcemail);
          ?>
            <tr class="agent-row">
              <td>
                <div style="font-weight:600"><?= h($info['name'] ?? $lcemail) ?></div>
                <div style="font-size:11px;color:#888"><?= h($lcemail) ?></div>
              </td>
              <td>
                <span class="role-badge role-<?= h($role) ?>"><?= h(role_label($role)) ?></span>
              </td>
              <td style="font-size:12px">
                <?php if ($ownMc): ?>
                  <span class="place-chip">MC: <?= h($mc_opts[$ownMc] ?? $ownMc) ?></span><br>
                <?php endif; ?>
                <?php if ($bicEmail): ?>
                  <span class="place-chip">BIC: <?= h($rosterByEmail[$bicEmail]['name'] ?? $bicEmail) ?></span>
                <?php endif; ?>
                <?php if (!$ownMc && !$bicEmail): ?>
                  <span style="color:#ccc;font-size:11px">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($mcs): ?>
                  <div class="mc-chips">
                    <?php foreach ($mcs as $s): ?>
                      <span class="mc-chip"><?= h($mc_opts[$s] ?? $s) ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <span style="color:#ccc;font-size:11px">—</span>
                <?php endif; ?>
              </td>
              <td style="text-align:right;white-space:nowrap;display:flex;gap:6px;justify-content:flex-end">
                <button class="btn-loginas" onclick="loginAs('<?= h(addslashes($lcemail)) ?>')">Login as</button>
                <button class="btn-edit" onclick="openEdit('<?= h($rowId) ?>', this)">Edit</button>
                <form method="post" action="admin_roles.php" style="margin:0" onsubmit="return confirm('Remove all settings for <?= h(addslashes($info['name'] ?? $lcemail)) ?>?')">
                  <input type="hidden" name="csrf"   value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="remove">
                  <input type="hidden" name="email"  value="<?= h($lcemail) ?>">
                  <button class="btn-remove" type="submit">Remove</button>
                </form>
              </td>
            </tr>
            <tr id="<?= h($rowId) ?>" class="edit-row" style="display:none">
              <td colspan="5">
                <div class="edit-panel">
                  <h4>Edit <?= h($info['name'] ?? $lcemail) ?></h4>
                  <form method="post" action="admin_roles.php">
                    <input type="hidden" name="csrf"  value="<?= h($csrf) ?>">
                    <input type="hidden" name="email" value="<?= h($lcemail) ?>">
                    <div class="form-grid">
                      <div>
                        <div class="field-label">Role</div>
                        <select name="role" class="field-select" onchange="onRoleChange(this,'mc-led-<?= h($rowId) ?>','bic-row-<?= h($rowId) ?>')">
                          <?php foreach (ROLE_LABELS as $k => $lbl): ?>
                            <option value="<?= h($k) ?>"<?= $role===$k?' selected':'' ?>><?= h($lbl) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div>
                        <div class="field-label">Their Market Center</div>
                        <select name="own_mc_slug" class="field-select">
                          <option value="">— not set —</option>
                          <?php foreach ($mc_opts as $slug => $name): ?>
                            <option value="<?= h($slug) ?>"<?= $ownMc===$slug?' selected':'' ?>><?= h($name) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div id="bic-row-<?= h($rowId) ?>"<?= in_array($role,['super_admin','staff','mc_leader','bic','recruiter'])?' style="display:none"':'' ?>>
                        <div class="field-label">Assigned BIC</div>
                        <?php if ($bicList): ?>
                          <select name="bic_email" class="field-select">
                            <option value="">— not set —</option>
                            <?php foreach ($bicList as $be): ?>
                              <option value="<?= h($be) ?>"<?= $bicEmail===$be?' selected':'' ?>><?= h($rosterByEmail[$be]['name'] ?? $be) ?> (<?= h($be) ?>)</option>
                            <?php endforeach; ?>
                          </select>
                        <?php else: ?>
                          <input type="text" name="bic_email" class="field-select" value="<?= h($bicEmail) ?>" placeholder="bic@example.com" style="font-size:12px">
                        <?php endif; ?>
                      </div>
                    </div>
                    <div id="mc-led-<?= h($rowId) ?>" class="mc-led-section<?= in_array($role,['bic','mc_leader'])?' visible':'' ?>" style="margin-top:12px">
                      <div class="field-label">Market Centers They Lead</div>
                      <div class="mc-checks">
                        <?php foreach ($mc_opts as $slug => $name): ?>
                          <label class="mc-check">
                            <input type="checkbox" name="mc_slugs[]" value="<?= h($slug) ?>"<?= in_array($slug,$mcs)?' checked':'' ?>>
                            <?= h($name) ?>
                          </label>
                        <?php endforeach; ?>
                      </div>
                    </div>
                    <div style="margin-top:14px">
                      <button class="btn-save" type="submit">Save</button>
                      <button class="btn-cancel" type="button" onclick="closeEdit('<?= h($rowId) ?>')">Cancel</button>
                    </div>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>

      </div>
    </main>
  </div>
</div>
<script>
const ROSTER = <?= json_encode(array_values($rosterByEmail)) ?>;
const LEADER_ROLES = ['bic','mc_leader'];
const STAFF_ROLES  = ['super_admin','staff','mc_leader','bic','recruiter'];

function searchAgents(q) {
  const res = document.getElementById('search-results');
  q = q.trim().toLowerCase();
  if (!q) { res.style.display='none'; res.innerHTML=''; return; }
  const hits = ROSTER.filter(a =>
    a.name.toLowerCase().includes(q) || a.email.includes(q) || a.mc.toLowerCase().includes(q)
  ).slice(0, 12);
  if (!hits.length) { res.style.display='none'; return; }
  res.innerHTML = hits.map(a => `
    <div class="search-result-row" data-a="${encodeURIComponent(JSON.stringify(a))}" onclick="selectAgent(JSON.parse(decodeURIComponent(this.dataset.a)))">
      <div><div class="sr-name">${esc(a.name)}</div><div class="sr-meta">${esc(a.email)} · ${esc(a.mc)}</div></div>
      <button class="sr-assign" type="button">Assign</button>
    </div>`).join('');
  res.style.display='block';
}

function selectAgent(a) {
  document.getElementById('search-results').style.display='none';
  document.getElementById('agent-search').value=a.name;
  document.getElementById('assign-email').value=a.email;
  document.getElementById('assign-name').textContent=a.name;
  document.getElementById('assign-sub').textContent=a.email+(a.mc?' · '+a.mc:'');
  document.querySelectorAll('#assign-form input[type=checkbox]').forEach(c=>c.checked=false);
  const roleEl=document.getElementById('assign-role');
  roleEl.value='agent';
  onRoleChange(roleEl,'assign-mc-led','assign-bic-row');
  document.getElementById('assign-own-mc').value='';
  const bicEl=document.getElementById('assign-bic-email');
  if(bicEl) bicEl.value='';
  document.getElementById('assign-panel').style.display='block';
}

function closeAssign() {
  document.getElementById('assign-panel').style.display='none';
  document.getElementById('agent-search').value='';
}

function onRoleChange(select, mcSectionId, bicRowId) {
  const role=select.value;
  const mcSec=document.getElementById(mcSectionId);
  const bicRow=document.getElementById(bicRowId);
  if(mcSec) mcSec.classList.toggle('visible', LEADER_ROLES.includes(role));
  if(bicRow) bicRow.style.display = STAFF_ROLES.includes(role) ? 'none' : '';
}

function openEdit(rowId, btn) {
  document.querySelectorAll('.edit-row').forEach(r=>r.style.display='none');
  document.querySelectorAll('.btn-edit').forEach(b=>b.textContent='Edit');
  document.getElementById(rowId).style.display='';
  btn.textContent='Cancel';
  btn.onclick=()=>closeEdit(rowId);
}
function closeEdit(rowId) {
  document.getElementById(rowId).style.display='none';
  document.querySelectorAll('.btn-edit').forEach(b=>{b.textContent='Edit';b.onclick=function(){openEdit(rowId,this);};});
}

function esc(s){return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

function loginAs(email) {
  if(!confirm('Log in as '+email+'?\n\nYou will see AgentEdge as this agent. Click "Back to Admin" in the yellow bar to return.'))return;
  fetch('api/masquerade.php',{
    method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'start',email}),
  }).then(r=>r.json()).then(d=>{
    if(d.ok){location.href=d.redirect||'index.php';}
    else alert('Error: '+(d.error||'unknown'));
  }).catch(()=>alert('Network error — please try again.'));
}

document.addEventListener('click',e=>{
  const res=document.getElementById('search-results');
  if(!res.contains(e.target)&&e.target.id!=='agent-search') res.style.display='none';
});
</script>
</body>
</html>
