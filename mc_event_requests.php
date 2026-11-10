<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/lib/mc_event_access.php';

$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

$db = local_db();
mc_access_ensure_tables($db);
$names = $db->query("SELECT slug, name, state_code FROM market_centers")->fetchAll(PDO::FETCH_ASSOC);
$mcName = [];
foreach ($names as $n) $mcName[$n['slug']] = $n['name'] . ($n['state_code'] ? ' (' . $n['state_code'] . ')' : '');
$rows = $db->query("SELECT * FROM mc_event_requests ORDER BY (status='pending') DESC, id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Event Access Requests — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .rq{width:100%;border-collapse:collapse;font-size:13px}
    .rq th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;border-bottom:1px solid #eee;padding:8px 10px;text-align:left}
    .rq td{padding:9px 10px;border-bottom:1px solid #f5f5f5;vertical-align:top}
    .btn-sm{padding:4px 10px;font-size:11px;font-weight:700;border-radius:4px;border:1px solid #ccc;background:#fff;cursor:pointer}
    .btn-sm.ok{background:#82C112;border-color:#82C112;color:#000}
    .btn-sm.danger{color:#c00;border-color:#fcc}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('mc_events', $agent); ?>
  <div class="content">
    <header class="content-top"><div class="content-title">Event Access Requests</div></header>
    <main class="wrap">
      <div class="card" style="padding:20px 24px">
        <?php if (!$rows): ?>
          <div style="color:#bbb;text-align:center;padding:24px">No requests yet.</div>
        <?php else: ?>
        <table class="rq">
          <thead><tr><th>Requested by</th><th>Access requested</th><th>Date</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><strong><?= h($r['name']) ?></strong><div style="font-size:11px;color:#888"><?= h($r['email']) ?></div></td>
              <td><?php
                if ($r['scope'] === 'company') echo '<strong>Entire company</strong>';
                else echo h(implode(', ', array_map(fn($s) => $mcName[$s] ?? $s, array_filter(explode(',', $r['mc_slugs'])))));
              ?></td>
              <td style="white-space:nowrap"><?= h(substr($r['created_at'], 0, 10)) ?></td>
              <td><?= h(ucfirst($r['status'])) ?><?= $r['decided_by'] ? '<div style="font-size:11px;color:#888">' . h($r['decided_by']) . '</div>' : '' ?></td>
              <td style="white-space:nowrap">
                <?php if ($r['status'] === 'pending'): ?>
                  <button class="btn-sm ok" onclick="decide(<?= (int)$r['id'] ?>,'approve')">Approve</button>
                  <button class="btn-sm danger" onclick="decide(<?= (int)$r['id'] ?>,'deny')">Deny</button>
                <?php endif; ?>
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
function decide(id, decision) {
  if (!confirm((decision === 'approve' ? 'Approve' : 'Deny') + ' this request?')) return;
  fetch('api/mc_event_requests.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'decide', id, decision}) })
    .then(r => r.json()).then(d => { if (d.ok) location.reload(); else alert(d.error || 'Failed'); })
    .catch(() => alert('Network error'));
}
</script>
</body>
</html>
