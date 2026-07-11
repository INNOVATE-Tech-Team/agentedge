<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/oh_subnav.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

$db      = local_db();
$myEmail = strtolower(trim($agent['email']));
$admin   = is_admin();

// Load all requests made by this agent
$reqQ = $db->prepare("
    SELECT r.*,
           l.address, l.city, l.state, l.zip, l.listing_agent_name, l.listing_agent_email,
           s.slot_date, s.start_time, s.end_time
    FROM oh_requests r
    JOIN oh_listings l ON l.id = r.listing_id
    JOIN oh_slots    s ON s.id = r.slot_id
    WHERE r.agent_email = ?
    ORDER BY r.created_at DESC
");
$reqQ->execute([$myEmail]);
$requests = $reqQ->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = [
    'pending'   => ['label'=>'Pending',   'cls'=>'badge-pending'],
    'approved'  => ['label'=>'Approved',  'cls'=>'badge-approved'],
    'declined'  => ['label'=>'Declined',  'cls'=>'badge-declined'],
    'cancelled' => ['label'=>'Cancelled', 'cls'=>'badge-cancelled'],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>My Requests — Open House — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="layout">
  <?php render_sidebar('openhouse', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Open House — My Requests</div>
    </header>
    <main class="wrap">
      <?php render_oh_subnav('requests', $admin); ?>

      <?php if (empty($requests)): ?>
        <div class="network-empty" style="margin-top:40px">
          You haven't requested any open houses yet.<br>
          <a href="openhouse.php" style="color:#82C112;font-weight:700">Browse the available pool →</a>
        </div>
      <?php else: ?>
      <div class="card" style="padding:0;overflow:hidden">
        <table class="tx">
          <thead><tr>
            <th>Property</th>
            <th>Time Slot</th>
            <th>Listing Agent</th>
            <th>Status</th>
            <th>Reason</th>
            <th>Requested</th>
            <th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($requests as $req):
            $st     = $statusLabels[$req['status']] ?? ['label'=>ucfirst($req['status']),'cls'=>'badge-pending'];
            $fd     = date('M j, Y', strtotime($req['slot_date']));
            $fs     = date('g:i A', strtotime($req['start_time']));
            $fe     = date('g:i A', strtotime($req['end_time']));
            $createdOn = date('M j, Y g:i A', strtotime($req['created_at']));
          ?>
          <tr id="reqrow-<?= $req['id'] ?>">
            <td>
              <div style="font-weight:700;font-size:13px"><?= h($req['address']) ?></div>
              <div style="font-size:11px;color:#888"><?= h($req['city']) ?>, <?= h($req['state']) ?></div>
            </td>
            <td style="font-size:13px;white-space:nowrap">
              <?= h($fd) ?><br>
              <span style="color:#888;font-size:11px"><?= h($fs) ?>–<?= h($fe) ?></span>
            </td>
            <td style="font-size:13px"><?= h($req['listing_agent_name'] ?: $req['listing_agent_email']) ?></td>
            <td><span class="<?= h($st['cls']) ?>"><?= h($st['label']) ?></span></td>
            <td style="font-size:12px;color:#666;max-width:180px"><?= $req['reason'] ? h($req['reason']) : '<span class="muted">—</span>' ?></td>
            <td style="font-size:12px;color:#888;white-space:nowrap"><?= h($createdOn) ?></td>
            <td>
              <?php if ($req['status'] === 'pending'): ?>
                <button onclick="cancelReq(<?= $req['id'] ?>)"
                        style="padding:4px 10px;border:1px solid #fcc;background:white;border-radius:4px;font-size:12px;cursor:pointer;color:#c00">
                  Cancel
                </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </main>
  </div>
</div>
<script>
function cancelReq(reqId) {
  if (!confirm('Cancel this open house request?')) return;
  const fd = new FormData();
  fd.append('action',     'cancel_request');
  fd.append('request_id', reqId);
  fetch('api/oh_action.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        location.reload();
      } else {
        alert(d.error || 'Could not cancel request.');
      }
    })
    .catch(() => alert('Network error.'));
}
</script>
</body>
</html>
