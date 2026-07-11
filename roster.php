<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
$agent = require_login();

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// MC options — from market_centers master list (admin-managed in backoffice).
// Falls back to innovate_roster distinct values for any orphaned MCs not yet in master list.
$mc_opts = [];
try {
    $mcRows = local_db()->query(
        "SELECT slug, name, state_code FROM market_centers WHERE enabled=1 ORDER BY state_code, sort_ord, name"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($mcRows as $mr) {
        $slug  = trim($mr['slug']);
        $mc    = trim($mr['name']);
        $st    = trim($mr['state_code']);
        $label = $st ? "$st - $mc" : $mc;
        if ($slug && !isset($mc_opts[$slug])) $mc_opts[$slug] = $label;
    }
    // Also include any MC values in innovate_roster not covered by the master list
    $rosterMCs = local_db()->query(
        "SELECT DISTINCT state_code, market_center FROM innovate_roster WHERE active=1 AND market_center != '' ORDER BY market_center"
    )->fetchAll(PDO::FETCH_ASSOC);
    $masterNames = array_map('strtolower', array_column($mcRows, 'name'));
    foreach ($rosterMCs as $mr) {
        $mc = trim($mr['market_center']);
        $st = trim($mr['state_code']);
        if (!in_array(strtolower($mc), $masterNames, true)) {
            $label = $st ? "$st - $mc" : $mc;
            $slug  = slugify_mc($label);
            if ($slug && !isset($mc_opts[$slug])) $mc_opts[$slug] = $label;
        }
    }
} catch (Exception $e) {}
ksort($mc_opts);

// Current assigned roles — keyed by lowercase email. all_roles includes extra_roles_json.
$roleRows  = local_db()->query("SELECT email, role, mc_slugs, extra_roles_json FROM agent_roles")->fetchAll(PDO::FETCH_ASSOC);
$roleByEmail = [];
foreach ($roleRows as $r) {
    $extra    = json_decode($r['extra_roles_json'] ?? '[]', true) ?: [];
    $allRoles = [$r['role']];
    foreach ($extra as $er) {
        $er = $er['role'] ?? '';
        if ($er && $er !== 'agent' && !in_array($er, $allRoles, true)) $allRoles[] = $er;
    }
    $r['all_roles'] = $allRoles;
    $roleByEmail[strtolower($r['email'])] = $r;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Agent Roster — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .btn-assign{padding:4px 10px;border:1px solid #c3dfa8;background:#eef5e8;color:#3a6b1a;font-size:11px;font-weight:700;border-radius:4px;cursor:pointer;text-transform:uppercase;letter-spacing:.04em}
    .btn-assign:hover{background:#d8edc3}
    .role-badge-sm{display:inline-block;padding:1px 6px;border-radius:3px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
    .role-mc_leader{background:#eef5e8;color:#5b8e0d}
    .role-bic{background:#fff4e0;color:#a07221}
    .role-staff{background:#e8f0ff;color:#2255cc}
    .role-super_admin{background:#000;color:#82C112}
    /* Role modal */
    #role-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:1000}
    #role-modal-overlay.open{display:flex}
    #role-modal{background:#fff;border-radius:10px;width:min(480px,95vw);max-height:85vh;overflow-y:auto;padding:24px;position:relative}
    #role-modal h3{margin:0 0 4px;font-size:16px;font-weight:700}
    #role-modal .sub{font-size:12px;color:#666;margin-bottom:18px}
    .rm-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#666;margin-bottom:5px}
    .rm-select{padding:8px 10px;font-size:13px;border:1px solid #ccc;border-radius:4px;background:white;width:100%;margin-bottom:14px}
    .rm-mc-wrap{margin-bottom:14px;display:none}
    .rm-mc-wrap.visible{display:block}
    .rm-mc-checks{display:flex;flex-wrap:wrap;gap:8px 14px}
    .rm-mc-check{display:flex;align-items:center;gap:5px;font-size:12px;cursor:pointer}
    .rm-mc-check input{accent-color:#82C112}
    .rm-btns{display:flex;gap:8px;margin-top:4px}
    .rm-save{padding:9px 20px;border:none;background:#82C112;color:#000;font-size:13px;font-weight:700;border-radius:4px;cursor:pointer}
    .rm-cancel{padding:9px 14px;border:1px solid #ccc;background:white;color:#555;font-size:13px;border-radius:4px;cursor:pointer}
    .rm-close{position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;line-height:1;color:#888}
  </style>
  <script>
    const IS_ADMIN       = <?= json_encode(is_leader()) ?>;
    const IS_SUPER_ADMIN = <?= json_encode(is_super_admin()) ?>;
    const IS_RECRUITER   = <?= json_encode(is_recruiter()) ?>;
    const CSRF           = <?= json_encode($csrf) ?>;
    const MC_OPTS       = <?= json_encode($mc_opts) ?>;
    const ROLE_BY_EMAIL = <?= json_encode($roleByEmail) ?>;
    const ROLE_LABELS   = {
      super_admin: 'Super Admin', staff: 'Staff', recruiter: 'Recruiter',
      bic: 'Broker in Charge', mc_leader: 'MC Leader', agent: 'Agent'
    };
  </script>
</head>
<body>
  <div class="layout">
    <?php render_sidebar('roster', $agent); ?>
    <div class="content">
      <header class="content-top">
        <div class="content-title">Agent Roster</div>
        <div style="display:flex;align-items:center;gap:10px">
          <input id="roster-search" class="search" type="search" placeholder="Search by name, email, location…">
          <?php if (is_admin()): ?>
          <a href="admin_import.php" style="flex-shrink:0;padding:8px 14px;background:#82C112;color:#000;font-size:12px;font-weight:800;border-radius:6px;text-decoration:none;white-space:nowrap">+ Import CSV</a>
          <?php endif; ?>
        </div>
      </header>
      <main class="wrap">
        <section class="card">
          <div class="roster-count" id="roster-count">Loading agents…</div>
          <table class="tx sortable" id="roster-table" hidden>
            <thead><tr>
              <th data-sort="name">Agent</th>
              <th data-sort="marketCenter">Market Center</th>
              <th data-sort="email">Contact</th>
              <th class="no-sort">Social</th>
              <?php if (is_leader() || is_recruiter()): ?><th class="no-sort">Actions</th><?php endif; ?>
            </tr></thead>
            <tbody id="roster-body"></tbody>
          </table>
          <div id="roster-empty" class="network-empty" hidden>No agents found.</div>
        </section>
      </main>
    </div>
  </div>

  <!-- Role Assignment Modal -->
  <div id="role-modal-overlay">
    <div id="role-modal">
      <button class="rm-close" onclick="closeRoleModal()">×</button>
      <h3 id="rm-name"></h3>
      <div class="sub" id="rm-sub"></div>
      <form id="rm-form" method="post" action="admin_roles.php">
        <input type="hidden" name="csrf"  value="<?= h($csrf) ?>">
        <input type="hidden" name="email" id="rm-email" value="">
        <div class="rm-label">Role</div>
        <select name="role" id="rm-role" class="rm-select" onchange="rmToggleMc(this.value)">
          <option value="super_admin">Super Admin</option>
          <option value="staff">Staff</option>
          <option value="recruiter">Recruiter</option>
          <option value="bic">Broker in Charge</option>
          <option value="mc_leader">Market Center Leader</option>
          <option value="agent">Agent (no special role)</option>
        </select>
        <div id="rm-mc-wrap" class="rm-mc-wrap">
          <div class="rm-label">Market Centers</div>
          <div class="rm-mc-checks" id="rm-mc-checks">
            <?php foreach ($mc_opts as $slug => $name): ?>
              <label class="rm-mc-check">
                <input type="checkbox" name="mc_slugs[]" value="<?= h($slug) ?>">
                <?= h($name) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="rm-btns">
          <button type="submit" class="rm-save">Save Role</button>
          <button type="button" class="rm-cancel" onclick="closeRoleModal()">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <script src="assets/roster.js"></script>
  <script>
    function openRoleModal(email, name, mc) {
      const lce = email.toLowerCase();
      document.getElementById('rm-name').textContent = name;
      document.getElementById('rm-sub').textContent  = email + (mc ? ' · ' + mc : '');
      document.getElementById('rm-email').value = lce;

      // Set current role if assigned
      const cur = ROLE_BY_EMAIL[lce];
      const role = cur ? cur.role : 'agent';
      document.getElementById('rm-role').value = role;
      rmToggleMc(role);

      // Set checked MCs
      const mcs = cur ? (JSON.parse(cur.mc_slugs || '[]') || []) : [];
      document.querySelectorAll('#rm-mc-checks input[type=checkbox]').forEach(cb => {
        cb.checked = mcs.includes(cb.value);
      });

      document.getElementById('role-modal-overlay').classList.add('open');
    }

    function closeRoleModal() {
      document.getElementById('role-modal-overlay').classList.remove('open');
    }

    function rmToggleMc(role) {
      const w = document.getElementById('rm-mc-wrap');
      w.classList.toggle('visible', role === 'bic' || role === 'mc_leader');
    }

    document.getElementById('role-modal-overlay').addEventListener('click', e => {
      if (e.target === document.getElementById('role-modal-overlay')) closeRoleModal();
    });
  </script>
</body>
</html>
