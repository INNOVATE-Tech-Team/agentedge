<?php
// Back Office → Technology → Polls (super admin only): list polls, open/close
// them, jump to results. Poll content itself is data (see lib/polls_schema.php
// and docs/polls-howto.md); a create/edit form is a later phase.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/polls.php';

$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }
$pdo = local_db();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

$rows = $pdo->query(
    "SELECT p.*, 
            (SELECT COUNT(*) FROM poll_responses r WHERE r.poll_id = p.id AND r.is_staff = 0) AS agent_votes,
            (SELECT COUNT(*) FROM poll_responses r WHERE r.poll_id = p.id AND r.is_staff = 1) AS staff_votes
       FROM polls p ORDER BY p.created_at DESC, p.id DESC"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Polls — AgentEdge Back Office</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .pa-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden}
    .pa-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);padding:10px 14px;text-align:left;border-bottom:1px solid var(--border)}
    .pa-table td{padding:12px 14px;border-top:1px solid var(--border);vertical-align:middle}
    .pa-status{font-size:11px;font-weight:800;text-transform:uppercase;padding:3px 9px;border-radius:99px}
    .pa-status.open{background:#eef5e8;color:var(--green-d)} .pa-status.draft{background:#fff3cd;color:#7a5c00} .pa-status.closed{background:#eee;color:#666}
    .pa-btn{padding:6px 12px;border:1px solid var(--border);background:#fff;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;color:var(--ink);display:inline-block}
    .pa-btn.go{background:var(--green);border-color:var(--green);color:#111}
    .pa-flash{padding:10px 14px;border-radius:6px;margin-bottom:14px;font-size:13px;background:#fff0f0;border:1px solid #f5c6c6;color:#c00}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_polls', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">Back Office · Technology</div>
        <div class="content-title">Polls</div>
      </div>
      <div class="content-hello">Agents only see a poll once it is Open. Draft and Closed polls are hidden from them.</div>
    </header>
    <main class="wrap">
      <div id="flash"></div>
      <table class="pa-table">
        <thead><tr><th>Poll</th><th>Status</th><th>Window (UTC)</th><th>Agent votes</th><th>Staff votes</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $p): ?>
          <tr data-id="<?= (int)$p['id'] ?>">
            <td><b><?= h($p['title']) ?></b><div style="color:var(--faint);font-size:12px"><?= h($p['slug']) ?></div></td>
            <td><span class="pa-status <?= h($p['status']) ?>"><?= h($p['status']) ?></span></td>
            <td style="font-size:12px;color:var(--faint)"><?= h(($p['opens_at'] ?: '—') . ' → ' . ($p['closes_at'] ?: '—')) ?></td>
            <td><?= (int)$p['agent_votes'] ?></td>
            <td><?= (int)$p['staff_votes'] ?></td>
            <td style="white-space:nowrap;text-align:right">
              <?php if ($p['status'] !== 'open'): ?><button class="pa-btn go" data-status="open">Open</button><?php endif; ?>
              <?php if ($p['status'] === 'open'): ?><button class="pa-btn" data-status="closed">Close</button><?php endif; ?>
              <?php if ($p['status'] === 'open'): ?><button class="pa-btn" data-status="draft" title="Hide from agents again">Back to draft</button><?php endif; ?>
              <a class="pa-btn" href="poll.php?slug=<?= urlencode($p['slug']) ?>">Preview</a>
              <a class="pa-btn" href="admin_poll_results.php?id=<?= (int)$p['id'] ?>">Results</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" style="text-align:center;color:var(--faint);padding:32px">No polls yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </main>
  </div>
</div>
<script>
document.querySelectorAll('[data-status]').forEach(function (b) {
  b.addEventListener('click', function () {
    var tr = b.closest('tr'), status = b.dataset.status;
    if (status === 'draft' && !confirm('Hide this poll from agents again?')) return;
    fetch('api/admin_polls_action.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'set_status', csrf: window.AE_CSRF || '', id: +tr.dataset.id, status: status }) })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.ok) location.reload(); else document.getElementById('flash').innerHTML = '<div class="pa-flash">' + (d.error || 'Failed') + '</div>'; });
  });
});
</script>
</body>
</html>
