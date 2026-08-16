<?php
// Cross-system email sync health: does each active agent's tblstaff email
// also appear in Darwin (darwin_cap_progress.agent_email) and DotLoop
// (dotloop_loop_participants.email)? All three need to agree for the
// AgentEdge <-> Darwin <-> DotLoop integrations to actually find an agent's
// data. Excludes known non-agent tblstaff rows (teams, vendors, placeholder
// test accounts) via sync_health_is_noise() below, since those were never
// going to match and aren't real gaps.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/dotloop.php';

$agent = require_login();
if (!is_admin()) { http_response_code(403); exit('Forbidden'); }

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

/**
 * True for tblstaff rows that are known not to be an individual agent (a
 * team display name, a vendor/partner contact, or a placeholder test
 * account) — these will never have a matching Darwin/DotLoop email, and
 * that's expected, not a sync gap worth chasing.
 */
function sync_health_is_noise(string $name, string $email): bool {
    $email = strtolower(trim($email));
    $name  = strtolower(trim($name));

    $noiseEmailSuffixes = ['@carolinapropertyinsurance.com', '@jeffcasterline.com', 'yopmail.com'];
    foreach ($noiseEmailSuffixes as $suffix) {
        if (str_ends_with($email, $suffix)) return true;
    }
    if (in_array($email, ['email@email.com', 'demo@demo.com'], true)) return true;
    if (str_starts_with($email, 'unkown') || str_starts_with($email, 'unknown') || str_starts_with($email, 'inactive')) return true;

    $teamNameMarkers = ['team', 'group', 'properties', 'llc', ' & ', ' and '];
    foreach ($teamNameMarkers as $marker) {
        if (str_contains($name, $marker)) return true;
    }
    return false;
}

$db = local_db();

$dotloopEmails = array_flip(array_map(
    fn($e) => strtolower(trim($e)),
    $db->query("SELECT DISTINCT email FROM dotloop_loop_participants")->fetchAll(PDO::FETCH_COLUMN)
));
$darwinEmails = array_flip(array_map(
    fn($e) => strtolower(trim($e)),
    $db->query("SELECT DISTINCT agent_email FROM darwin_cap_progress WHERE agent_email != ''")->fetchAll(PDO::FETCH_COLUMN)
));
// Agent-set overrides (agent_profile.php / backoffice_agents.php "Alternate
// Email" fields) — an agent counts as matched here if either their tblstaff
// email or their alt/dotloop_alt_email shows up on the other side, so a
// mismatch already fixed via those fields doesn't keep reading as a gap.
$altEmails = [];
foreach ($db->query("SELECT email, alt_email, dotloop_alt_email FROM agent_extra WHERE alt_email != '' OR dotloop_alt_email != ''")->fetchAll(PDO::FETCH_ASSOC) as $ae) {
    $altEmails[strtolower(trim($ae['email']))] = [
        'alt_email'         => strtolower(trim($ae['alt_email'] ?? '')),
        'dotloop_alt_email' => strtolower(trim($ae['dotloop_alt_email'] ?? '')),
    ];
}

$staff = db_query_safe("SELECT email, firstname, lastname FROM tblstaff WHERE active=1", []);

$buckets = ['darwin_only' => [], 'dotloop_only' => [], 'both' => [], 'neither' => []];
foreach ($staff as $s) {
    $email = trim($s['email']);
    $name  = trim($s['firstname'] . ' ' . $s['lastname']);
    if (sync_health_is_noise($name, $email)) continue;

    $e          = strtolower($email);
    $altDarwin  = $altEmails[$e]['alt_email'] ?? '';
    $altDotloop = $altEmails[$e]['dotloop_alt_email'] ?? '';
    $inDarwin   = isset($darwinEmails[$e]) || ($altDarwin !== '' && isset($darwinEmails[$altDarwin]));
    $inDotloop  = isset($dotloopEmails[$e]) || ($altDotloop !== '' && isset($dotloopEmails[$altDotloop]));
    $row        = ['name' => $name, 'email' => $email];

    if ($inDarwin && $inDotloop) $buckets['both'][] = $row;
    elseif ($inDarwin) $buckets['darwin_only'][] = $row;
    elseif ($inDotloop) $buckets['dotloop_only'][] = $row;
    else $buckets['neither'][] = $row;
}

$tab = $_GET['tab'] ?? 'darwin_only';
$tabs = [
    'darwin_only'  => 'Missing from DotLoop',
    'dotloop_only' => 'Missing from Darwin',
    'neither'      => 'In Neither',
    'both'         => 'Fully Synced',
];
if (!isset($tabs[$tab])) $tab = 'darwin_only';
$rows = $buckets[$tab];
// These two tabs are exactly the ones where DotLoop has no participant row
// under the agent's tblstaff email — offer the name-search tool there so an
// admin can find their real DotLoop email and save it as dotloop_alt_email
// without leaving this page.
$showDotloopFix = in_array($tab, ['darwin_only', 'neither'], true);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Sync Health — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .sh-hero{background:linear-gradient(135deg,#1a1a1a 0%,#2d3a1e 100%);border-radius:12px;padding:24px 28px;color:white;margin-bottom:20px}
    .sh-hero-title{font-size:20px;font-weight:900;margin:0 0 4px}
    .sh-hero-sub{font-size:12px;color:rgba(255,255,255,.65);margin:0}
    .sh-tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
    .sh-tab{padding:8px 16px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;color:#555;background:#fff;border:1px solid #e0e0e0}
    .sh-tab:hover{border-color:#82C112}
    .sh-tab.active{background:#82C112;border-color:#82C112;color:#000}
    .sh-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #e5e5e5;border-radius:12px;overflow:hidden}
    .sh-table th{text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#999;padding:10px 14px;border-bottom:1px solid #eee;background:#fafafa}
    .sh-table td{padding:10px 14px;border-bottom:1px solid #f5f5f5}
    .sh-table tr:last-child td{border-bottom:none}
    .sh-empty{color:#aaa;font-size:13px;padding:40px 0;text-align:center}
    .sh-note{font-size:11px;color:#999;margin-top:10px}
    .sh-bulk-bar{display:none;align-items:center;gap:10px;margin-bottom:12px;padding:10px 14px;background:#fff3f3;border:1px solid #f3c6c6;border-radius:8px;font-size:12px}
    .sh-bulk-bar.active{display:flex}
    .sh-btn-delete{padding:4px 10px;border-radius:5px;font-size:11px;font-weight:700;background:#fff;border:1px solid #c0392b;color:#c0392b;cursor:pointer}
    .sh-btn-delete:hover{background:#c0392b;color:#fff}
    .sh-btn-bulk-delete{padding:6px 14px;border-radius:6px;font-size:12px;font-weight:700;background:#c0392b;border:none;color:#fff;cursor:pointer}
    .sh-btn-bulk-delete:disabled{opacity:.5;cursor:not-allowed}
    .sh-table td.sh-check{width:30px}
    .sh-btn-find{padding:4px 10px;border-radius:5px;font-size:11px;font-weight:700;background:#fff;border:1px solid #82C112;color:#4a7a0a;cursor:pointer}
    .sh-btn-find:hover{background:#82C112;color:#000}
    .sh-btn-find:disabled{opacity:.5;cursor:not-allowed}
    .sh-match-results{margin-top:6px;font-size:11px}
    .sh-match-row{display:flex;align-items:center;gap:8px;padding:4px 0}
    .sh-btn-use{padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;background:#82C112;border:none;color:#000;cursor:pointer}
    .sh-btn-use:disabled{opacity:.5;cursor:not-allowed}
    .sh-no-match{color:#aaa}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_sync_health', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Sync Health</div>
    </header>
    <main class="wrap">

      <div class="sh-hero">
        <div class="sh-hero-title">AgentEdge / Darwin / DotLoop Email Sync</div>
        <p class="sh-hero-sub">Every active agent's tblstaff email needs to match Darwin's agent_email and DotLoop's participant email for those integrations to find their data. Known non-agent tblstaff rows (teams, vendors, test accounts) are excluded.</p>
      </div>

      <div class="sh-tabs">
        <?php foreach ($tabs as $key => $label): ?>
        <a class="sh-tab<?= $tab === $key ? ' active' : '' ?>" href="?tab=<?= h($key) ?>">
          <?= h($label) ?> (<?= count($buckets[$key]) ?>)
        </a>
        <?php endforeach; ?>
      </div>

      <?php if (!$rows): ?>
      <div class="sh-empty">No agents in this category.</div>
      <?php else: ?>

      <?php if (is_super_admin()): ?>
      <div class="sh-bulk-bar" id="bulkBar">
        <span id="bulkCount">0 selected</span>
        <button type="button" class="sh-btn-bulk-delete" id="bulkDeleteBtn" onclick="bulkDelete()">Delete Selected</button>
      </div>
      <?php endif; ?>

      <table class="sh-table">
        <thead>
          <tr>
            <?php if (is_super_admin()): ?><th class="sh-check"><input type="checkbox" id="selectAll" onclick="toggleAll(this)"></th><?php endif; ?>
            <th>Agent</th>
            <th>Email</th>
            <?php if ($showDotloopFix): ?><th>DotLoop Match</th><?php endif; ?>
            <?php if (is_super_admin()): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr id="row-<?= h(md5($r['email'])) ?>">
            <?php if (is_super_admin()): ?>
            <td class="sh-check"><input type="checkbox" class="sh-row-check" value="<?= h($r['email']) ?>" onclick="updateBulkBar()"></td>
            <?php endif; ?>
            <td><?= h($r['name']) ?></td>
            <td><?= h($r['email']) ?></td>
            <?php if ($showDotloopFix): ?>
            <td>
              <button type="button" class="sh-btn-find" data-email="<?= h($r['email']) ?>" data-name="<?= h($r['name']) ?>" onclick="findDotloopMatch(this)">Find DotLoop match</button>
              <div class="sh-match-results"></div>
            </td>
            <?php endif; ?>
            <?php if (is_super_admin()): ?>
            <td><button type="button" class="sh-btn-delete" onclick="deleteOne('<?= h(addslashes($r['email'])) ?>', this)">Delete</button></td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <p class="sh-note">"In Neither" mostly reflects agents with no closed production and no synced DotLoop activity yet — expected for new or inactive agents, not necessarily a sync problem.</p>
      <?php if (is_super_admin()): ?>
      <p class="sh-note">Delete permanently removes the tblstaff row from Perfex — this cannot be undone.</p>
      <?php endif; ?>

    </main>
  </div>
</div>
<script>
function toggleAll(box) {
  document.querySelectorAll('.sh-row-check').forEach(function(c) { c.checked = box.checked; });
  updateBulkBar();
}
function updateBulkBar() {
  var checked = document.querySelectorAll('.sh-row-check:checked');
  var bar = document.getElementById('bulkBar');
  var count = document.getElementById('bulkCount');
  if (!bar) return;
  if (checked.length > 0) {
    bar.classList.add('active');
    count.textContent = checked.length + ' selected';
  } else {
    bar.classList.remove('active');
  }
}
function removeRowByEmail(email) {
  document.querySelectorAll('input.sh-row-check').forEach(function(c) {
    if (c.value === email) {
      var row = c.closest('tr');
      if (row) row.remove();
    }
  });
}
function deleteOne(email, btn) {
  if (!confirm('Permanently delete this tblstaff row for ' + email + '? This cannot be undone.')) return;
  btn.disabled = true;
  btn.textContent = 'Deleting…';
  fetch('api/sync_health_action.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({emails: [email]}),
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    if (d.ok) {
      removeRowByEmail(email);
      updateBulkBar();
    } else {
      alert('Delete failed: ' + (d.error || 'unknown error'));
      btn.disabled = false;
      btn.textContent = 'Delete';
    }
  })
  .catch(function() {
    alert('Request failed.');
    btn.disabled = false;
    btn.textContent = 'Delete';
  });
}
function escapeHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function findDotloopMatch(btn) {
  var staffEmail = btn.dataset.email;
  var name = btn.dataset.name;
  var box = btn.nextElementSibling;
  btn.disabled = true;
  var original = btn.textContent;
  btn.textContent = 'Searching…';
  fetch('api/dotloop_find_participant.php?name=' + encodeURIComponent(name))
    .then(function(r) { return r.json(); })
    .then(function(d) {
      btn.disabled = false;
      btn.textContent = original;
      var matches = d.matches || [];
      if (!matches.length) {
        box.innerHTML = '<span class="sh-no-match">No DotLoop participant found for this name.</span>';
        return;
      }
      box.innerHTML = matches.map(function(m) {
        return '<div class="sh-match-row"><span>' + escapeHtml(m.email) + ' (' + m.loop_count
          + ' loop' + (m.loop_count == 1 ? '' : 's') + ')</span>'
          + '<button type="button" class="sh-btn-use" onclick="useDotloopMatch(' + JSON.stringify(staffEmail) + ',' + JSON.stringify(m.email) + ', this)">Use this</button></div>';
      }).join('');
    })
    .catch(function() {
      btn.disabled = false;
      btn.textContent = original;
      box.innerHTML = '<span class="sh-no-match">Search failed.</span>';
    });
}
function useDotloopMatch(staffEmail, candidateEmail, btn) {
  if (!confirm('Set "' + candidateEmail + '" as the DotLoop alternate email for ' + staffEmail + '?')) return;
  btn.disabled = true;
  // Fetch existing agent_extra fields first so this save only touches
  // dotloop_alt_email — api/agent_extra.php's POST overwrites every field
  // it's given, so blindly posting just {email, dotloop_alt_email} would
  // wipe out this agent's birthday/hire_date/license_renewal/alt_email.
  fetch('api/agent_extra.php?email=' + encodeURIComponent(staffEmail))
    .then(function(r) { return r.json(); })
    .then(function(existing) {
      return fetch('api/agent_extra.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          email: staffEmail,
          birthday: existing.birthday || '',
          hire_date: existing.hire_date || '',
          license_renewal: existing.license_renewal || '',
          alt_email: existing.alt_email || '',
          dotloop_alt_email: candidateEmail
        })
      });
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.ok) {
        alert('Saved. Refresh this page to see it move to "Fully Synced".');
      } else {
        alert('Save failed: ' + (d.error || 'unknown error'));
        btn.disabled = false;
      }
    })
    .catch(function() {
      alert('Request failed.');
      btn.disabled = false;
    });
}
function bulkDelete() {
  var checked = Array.from(document.querySelectorAll('.sh-row-check:checked'));
  var emails = checked.map(function(c) { return c.value; });
  if (!emails.length) return;
  if (!confirm('Permanently delete ' + emails.length + ' tblstaff row(s)? This cannot be undone.')) return;
  var btn = document.getElementById('bulkDeleteBtn');
  btn.disabled = true;
  btn.textContent = 'Deleting…';
  fetch('api/sync_health_action.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({emails: emails}),
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    if (d.ok) {
      emails.forEach(removeRowByEmail);
      updateBulkBar();
    } else {
      alert('Bulk delete failed: ' + (d.error || 'unknown error'));
    }
    btn.disabled = false;
    btn.textContent = 'Delete Selected';
  })
  .catch(function() {
    alert('Request failed.');
    btn.disabled = false;
    btn.textContent = 'Delete Selected';
  });
}
</script>
</body>
</html>
