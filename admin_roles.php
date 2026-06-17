<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/roles.php';
require __DIR__ . '/local_db.php';
require __DIR__ . '/nav.php';

$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// ── Fetch roster + known MCs from CRM ──────────────────────────────────────
$c      = cfg();
$base   = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
$token  = $c['crm_token'] ?? '';
$url    = $base . '/public/retention-roster' . ($token ? '?token=' . urlencode($token) : '');
$ctx    = stream_context_create(['http' => ['timeout' => 12, 'header' => "Accept: application/json\r\n"]]);
$raw    = @file_get_contents($url, false, $ctx);
$roster = ($raw !== false) ? (json_decode($raw, true) ?? []) : [];

$agents  = [];
$mc_opts = []; // slug => name
foreach ($roster as $a) {
    $mc = $a['marketCenter'] ?? '';
    if ($mc === '' && !empty($a['marketCenters'])) {
        $mc = $a['marketCenters'][0]['name'] ?? '';
    }
    $email = trim($a['email'] ?? '');
    if (!$email) continue;
    $slug = slugify_mc($mc);
    if ($mc && $slug && !isset($mc_opts[$slug])) $mc_opts[$slug] = $mc;
    $agents[] = ['email' => $email, 'name' => $a['fullName'] ?? $email, 'mc' => $mc, 'mc_slug' => $slug];
}
usort($agents, fn($a, $b) => strcmp($a['name'], $b['name']));
ksort($mc_opts);

// Load all existing role rows from local DB.
$roleRows = local_db()->query("SELECT email, role, mc_slugs FROM agent_roles")->fetchAll(PDO::FETCH_ASSOC);
$roleMap  = [];
foreach ($roleRows as $r) $roleMap[$r['email']] = $r;

// ── Handle POST ─────────────────────────────────────────────────────────────
$saved = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) die('Invalid CSRF token.');

    $email   = strtolower(trim($_POST['email'] ?? ''));
    $role    = preg_replace('/[^a-z_]/', '', $_POST['role'] ?? 'agent');
    if (!isset(ROLE_LABELS[$role])) $role = 'agent';

    // MC slugs only make sense for bic / mc_leader.
    $mcs = [];
    if (in_array($role, ['bic', 'mc_leader'], true) && !empty($_POST['mc_slugs'])) {
        foreach ((array)$_POST['mc_slugs'] as $s) {
            $s = preg_replace('/[^a-z0-9\-]/', '', $s);
            if ($s) $mcs[] = $s;
        }
    }

    if ($email) {
        try {
        $db   = local_db();
        $json = json_encode(array_values(array_unique($mcs)));
        $stmt = $db->prepare(
            "INSERT INTO agent_roles (email, role, mc_slugs, updated_by, updated_at)
             VALUES (?, ?, ?, ?, datetime('now'))
             ON CONFLICT(email) DO UPDATE SET
               role=excluded.role, mc_slugs=excluded.mc_slugs,
               updated_by=excluded.updated_by, updated_at=excluded.updated_at"
        );
        $stmt->execute([$email, $role, $json, $agent['email']]);
        } catch (\Throwable $e) {
            $saved = 'ERROR: ' . $e->getMessage();
            goto render;
        }
        // Refresh map.
        $roleMap[$email] = ['email' => $email, 'role' => $role, 'mc_slugs' => $json];
        $saved = $email;
        // Bust session cache if editing own account.
        if (strtolower($agent['email']) === $email) unset($_SESSION['perms']);
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
render:
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Role Assignments — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .search-bar{display:flex;gap:8px;margin-bottom:20px}
    .search-bar input{flex:1;padding:8px 12px;font-size:13px;border:1px solid #ccc;border-radius:6px}
    .agent-table{width:100%;border-collapse:collapse;font-size:13px}
    .agent-table th{text-align:left;padding:9px 12px;background:#f5f5f5;border-bottom:2px solid #e0e0e0;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#555;white-space:nowrap}
    .agent-table td{padding:9px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
    .agent-table tr:last-child td{border-bottom:none}
    .agent-table tr.edit-row td{padding:0;background:#f9fdf5;border-bottom:2px solid #82C112}
    .role-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
    .role-agent{background:#f0f0f0;color:#666}
    .role-mc_leader{background:#eef5e8;color:#5b8e0d}
    .role-bic{background:#fff4e0;color:#a07221}
    .role-staff{background:#e8f0ff;color:#2255cc}
    .role-super_admin{background:#000;color:#82C112}
    .mc-chips{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px}
    .mc-chip{font-size:10px;padding:2px 6px;border-radius:3px;background:#eef5e8;color:#5b8e0d;font-weight:600}
    .edit-panel{padding:16px 20px}
    .edit-panel h4{margin:0 0 12px;font-size:13px;font-weight:700}
    .edit-grid{display:grid;grid-template-columns:200px 1fr;gap:16px;align-items:start}
    .field-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#666;margin-bottom:4px}
    .role-select{padding:7px 10px;font-size:13px;border:1px solid #ccc;border-radius:4px;background:white;width:100%}
    .mc-checks{display:flex;flex-wrap:wrap;gap:8px 16px}
    .mc-check{display:flex;align-items:center;gap:5px;font-size:12px;cursor:pointer}
    .mc-check input{accent-color:#82C112}
    .btn-save{padding:8px 18px;border:none;background:#82C112;color:#000;font-size:13px;font-weight:700;border-radius:4px;cursor:pointer}
    .btn-cancel{padding:8px 14px;border:1px solid #ccc;background:white;color:#555;font-size:13px;border-radius:4px;cursor:pointer;margin-left:6px}
    .flash-ok{padding:10px 14px;background:#eef5e8;border:1px solid #c3dfa8;border-radius:6px;color:#3a6b1a;font-size:13px;margin-bottom:16px}
    .mc-section{display:none}
    .mc-section.visible{display:block}
    tr.hidden-row{display:none}
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

      <?php if ($saved): ?>
        <?php if (str_starts_with($saved, 'ERROR:')): ?>
          <div style="padding:10px 14px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:6px;color:#c00;font-size:13px;margin-bottom:16px"><?= h($saved) ?></div>
        <?php else: ?>
          <div class="flash-ok">Saved role for <strong><?= h($saved) ?></strong>.</div>
        <?php endif; ?>
      <?php endif; ?>

      <div class="card" style="padding:20px 24px">
        <p style="font-size:13px;color:#555;margin:0 0 16px">
          Assign roles and market center access here. Changes take effect on the agent's next login.
          Agents not listed default to <strong>Agent</strong>.
        </p>

        <div class="search-bar">
          <input type="text" id="search" placeholder="Search by name, email, or market center…" oninput="filterTable(this.value)">
        </div>

        <table class="agent-table" id="agent-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Market Center</th>
              <th>Role</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($agents as $a):
            $email   = $a['email'];
            $row     = $roleMap[$email] ?? null;
            $role    = canonical_role($row['role'] ?? 'agent');
            $mcs     = $row ? (json_decode($row['mc_slugs'], true) ?? []) : [];
            $rowId   = 'edit-' . md5($email);
          ?>
            <tr class="agent-row" data-search="<?= h(strtolower($a['name'] . ' ' . $email . ' ' . $a['mc'])) ?>">
              <td style="font-weight:600"><?= h($a['name']) ?></td>
              <td style="color:#555;font-size:12px"><?= h($email) ?></td>
              <td style="color:#555;font-size:12px"><?= h($a['mc']) ?></td>
              <td>
                <span class="role-badge role-<?= h($role) ?>"><?= h(role_label($role)) ?></span>
                <?php if ($mcs): ?>
                  <div class="mc-chips">
                    <?php foreach ($mcs as $s): ?>
                      <span class="mc-chip"><?= h($mc_opts[$s] ?? $s) ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td style="text-align:right">
                <button class="btn-cancel" style="font-size:11px;padding:4px 10px"
                  onclick="toggleEdit('<?= h($rowId) ?>', this)">Edit</button>
              </td>
            </tr>
            <tr id="<?= h($rowId) ?>" class="edit-row" style="display:none">
              <td colspan="5">
                <div class="edit-panel">
                  <h4>Edit <?= h($a['name']) ?></h4>
                  <form method="post" action="admin_roles.php">
                    <input type="hidden" name="csrf"  value="<?= h($csrf) ?>">
                    <input type="hidden" name="email" value="<?= h($email) ?>">
                    <div class="edit-grid">
                      <div>
                        <div class="field-label">Role</div>
                        <select name="role" class="role-select" onchange="toggleMcSection(this)">
                          <?php foreach (ROLE_LABELS as $k => $lbl): ?>
                            <option value="<?= h($k) ?>"<?= $role === $k ? ' selected' : '' ?>><?= h($lbl) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="mc-section<?= in_array($role, ['bic','mc_leader']) ? ' visible' : '' ?>">
                        <div class="field-label">Market Centers</div>
                        <div class="mc-checks">
                          <?php foreach ($mc_opts as $slug => $name): ?>
                            <label class="mc-check">
                              <input type="checkbox" name="mc_slugs[]" value="<?= h($slug) ?>"
                                <?= in_array($slug, $mcs) ? 'checked' : '' ?>>
                              <?= h($name) ?>
                            </label>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    </div>
                    <div style="margin-top:14px">
                      <button class="btn-save" type="submit">Save</button>
                      <button class="btn-cancel" type="button"
                        onclick="toggleEdit('<?= h($rowId) ?>', document.querySelector('[onclick*=\'<?= h($rowId) ?>\']'))">Cancel</button>
                    </div>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>

        <?php if (empty($agents)): ?>
          <div style="padding:20px;text-align:center;color:#888;font-size:13px">
            Could not load the agent roster from the CRM. Check <code>crm_token</code> in config.php.
          </div>
        <?php endif; ?>
      </div>

    </main>
  </div>
</div>
<script>
function toggleEdit(rowId, btn) {
  const row = document.getElementById(rowId);
  const open = row.style.display !== 'none';
  // Close all open edit rows first.
  document.querySelectorAll('.edit-row').forEach(r => r.style.display = 'none');
  document.querySelectorAll('.agent-row button').forEach(b => b.textContent = 'Edit');
  if (!open) {
    row.style.display = '';
    if (btn) btn.textContent = 'Cancel';
  }
}

function toggleMcSection(select) {
  const section = select.closest('.edit-grid').querySelector('.mc-section');
  section.classList.toggle('visible', ['bic','mc_leader'].includes(select.value));
}

function filterTable(q) {
  q = q.toLowerCase();
  document.querySelectorAll('.agent-row').forEach(row => {
    const match = !q || row.dataset.search.includes(q);
    row.style.display = match ? '' : 'none';
    // Also hide associated edit row when filtering.
    const editId = row.querySelector('button[onclick]')?.getAttribute('onclick').match(/'(edit-[^']+)'/)?.[1];
    if (editId) {
      const er = document.getElementById(editId);
      if (er) er.style.display = 'none';
    }
  });
}
</script>
</body>
</html>
