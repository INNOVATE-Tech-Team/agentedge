<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/roles.php';
require __DIR__ . '/local_db.php';
require __DIR__ . '/oh_subnav.php';
require __DIR__ . '/nav.php';

$agent = require_login();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

$db      = local_db();
$myEmail = strtolower(trim($agent['email']));
$admin   = is_admin();

$ok  = $_GET['ok']  ?? '';
$err = $_GET['err'] ?? '';

// Load my listings with request counts per status
$lstQ = $db->prepare("
    SELECT l.*,
        (SELECT COUNT(*) FROM oh_requests r JOIN oh_slots s ON s.id=r.slot_id WHERE s.listing_id=l.id AND r.status='approved')  AS cnt_approved,
        (SELECT COUNT(*) FROM oh_requests r JOIN oh_slots s ON s.id=r.slot_id WHERE s.listing_id=l.id AND r.status='pending')   AS cnt_pending,
        (SELECT COUNT(*) FROM oh_requests r JOIN oh_slots s ON s.id=r.slot_id WHERE s.listing_id=l.id AND r.status='declined')  AS cnt_declined,
        (SELECT COUNT(*) FROM oh_requests r JOIN oh_slots s ON s.id=r.slot_id WHERE s.listing_id=l.id AND r.status='cancelled') AS cnt_cancelled
    FROM oh_listings l
    WHERE LOWER(l.listing_agent_email) = ?
    ORDER BY l.created_at DESC
");
$lstQ->execute([$myEmail]);
$listings = $lstQ->fetchAll(PDO::FETCH_ASSOC);

// For each listing, load its pending requests (for the respond section)
$pendingByListing = [];
if ($listings) {
    $ids  = array_column($listings, 'id');
    $inPH = implode(',', array_fill(0, count($ids), '?'));
    $rQ   = $db->prepare("
        SELECT r.*, s.slot_date, s.start_time, s.end_time
        FROM oh_requests r
        JOIN oh_slots s ON s.id = r.slot_id
        WHERE r.listing_id IN ({$inPH}) AND r.status = 'pending'
        ORDER BY s.slot_date, s.start_time
    ");
    $rQ->execute($ids);
    foreach ($rQ->fetchAll(PDO::FETCH_ASSOC) as $req) {
        $pendingByListing[$req['listing_id']][] = $req;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>My Listings — Open House — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="layout">
  <?php render_sidebar('openhouse', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Open House — My Listings</div>
      <a href="openhouse_add.php" class="btn-save" style="text-decoration:none;font-size:13px;padding:8px 16px">+ Add Listing</a>
    </header>
    <main class="wrap">
      <?php render_oh_subnav('mine', $admin); ?>

      <?php if ($ok === 'saved'): ?>
        <div style="padding:10px 14px;background:#eef5e8;border:1px solid #c3dfa8;border-radius:6px;color:#3a6b1a;font-size:13px;margin-bottom:16px">Listing saved successfully.</div>
      <?php endif; ?>
      <?php if ($err): ?>
        <div style="padding:10px 14px;background:#fde8e8;border:1px solid #f5b0b0;border-radius:6px;color:#c00;font-size:13px;margin-bottom:16px">
          <?php if ($err==='validation') echo 'Address and city are required.';
                elseif ($err==='forbidden') echo 'You are not authorized to edit that listing.';
                elseif ($err==='notfound') echo 'Listing not found.';
                else echo h($err); ?>
        </div>
      <?php endif; ?>

      <?php if (empty($listings)): ?>
        <div class="network-empty" style="margin-top:40px">
          You haven't added any listings yet.<br>
          <a href="openhouse_add.php" style="color:#82C112;font-weight:700">Add your first listing →</a>
        </div>
      <?php else: ?>
      <div class="card" style="padding:0;overflow:hidden">
        <table class="tx">
          <thead><tr>
            <th>Address</th>
            <th>Type</th>
            <th>List Price</th>
            <th>Slots</th>
            <th>Requests</th>
            <th>Visible</th>
            <th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($listings as $lst): ?>
          <tr id="row-<?= $lst['id'] ?>">
            <td>
              <div style="font-weight:700;font-size:13px"><?= h($lst['address']) ?></div>
              <div style="font-size:11px;color:#888"><?= h($lst['city']) ?>, <?= h($lst['state']) ?><?= $lst['zip'] ? ' '.$lst['zip'] : '' ?></div>
              <?php if ($lst['mls_number']): ?>
                <div style="font-size:10px;color:#aaa">MLS# <?= h($lst['mls_number']) ?></div>
              <?php endif; ?>
            </td>
            <td style="font-size:13px"><?= h($lst['property_type']) ?></td>
            <td style="font-size:13px"><?= $lst['list_price'] ? '$'.number_format($lst['list_price']) : '<span class="muted">—</span>' ?></td>
            <td>
              <?php
              $slotCntQ = $db->prepare("SELECT COUNT(*) FROM oh_slots WHERE listing_id=?");
              $slotCntQ->execute([$lst['id']]);
              $slotCnt = (int)$slotCntQ->fetchColumn();
              echo '<span style="font-size:13px">'.$slotCnt.' slot'.($slotCnt!==1?'s':'').'</span>';
              ?>
            </td>
            <td>
              <div style="display:flex;flex-wrap:wrap;gap:4px">
                <?php if ($lst['cnt_approved'] > 0):  ?><span class="badge-approved"><?= $lst['cnt_approved'] ?> Approved</span><?php endif; ?>
                <?php if ($lst['cnt_pending'] > 0):   ?><span class="badge-pending"><?= $lst['cnt_pending'] ?> Pending</span><?php endif; ?>
                <?php if ($lst['cnt_declined'] > 0):  ?><span class="badge-declined"><?= $lst['cnt_declined'] ?> Declined</span><?php endif; ?>
                <?php if ($lst['cnt_cancelled'] > 0): ?><span class="badge-cancelled"><?= $lst['cnt_cancelled'] ?> Cancelled</span><?php endif; ?>
                <?php if (!$lst['cnt_approved'] && !$lst['cnt_pending'] && !$lst['cnt_declined'] && !$lst['cnt_cancelled']): ?>
                  <span class="muted" style="font-size:12px">None</span>
                <?php endif; ?>
              </div>
              <?php if (!empty($pendingByListing[$lst['id']])): ?>
                <button onclick="togglePending(<?= $lst['id'] ?>)"
                        style="margin-top:6px;font-size:11px;padding:3px 8px;border:1px solid #ccc;background:white;border-radius:4px;cursor:pointer">
                  Review <?= count($pendingByListing[$lst['id']]) ?> pending
                </button>
                <div id="pending-<?= $lst['id'] ?>" style="display:none;margin-top:8px">
                  <?php foreach ($pendingByListing[$lst['id']] as $req):
                    $fd = date('M j, Y', strtotime($req['slot_date']));
                    $fs = date('g:i A', strtotime($req['start_time']));
                    $fe = date('g:i A', strtotime($req['end_time']));
                  ?>
                  <div style="padding:8px;background:#f9f9f9;border:1px solid #eee;border-radius:6px;margin-bottom:6px;font-size:12px" id="preq-<?= $req['id'] ?>">
                    <div style="font-weight:700"><?= h($req['agent_name'] ?: $req['agent_email']) ?></div>
                    <div style="color:#888"><?= h($fd) ?> &middot; <?= h($fs) ?>–<?= h($fe) ?></div>
                    <div style="display:flex;gap:6px;margin-top:6px;align-items:center">
                      <button class="btn-save" style="font-size:11px;padding:4px 10px"
                              onclick="respondReq(<?= $req['id'] ?>, 'approve')">Approve</button>
                      <button onclick="respondReq(<?= $req['id'] ?>, 'decline')"
                              style="padding:4px 10px;border:1px solid #ddd;background:white;border-radius:4px;font-size:11px;cursor:pointer;color:#c00">Decline</button>
                      <input id="reason-<?= $req['id'] ?>" placeholder="Reason (optional)" style="flex:1;padding:4px 8px;font-size:11px;border:1px solid #ccc;border-radius:4px">
                    </div>
                    <div id="rmsg-<?= $req['id'] ?>" style="font-size:11px;margin-top:4px"></div>
                  </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <button id="vis-btn-<?= $lst['id'] ?>"
                      onclick="toggleVisible(<?= $lst['id'] ?>)"
                      style="padding:4px 10px;border:1px solid #ccc;background:white;border-radius:4px;font-size:12px;cursor:pointer;color:<?= $lst['visible'] ? '#3a6b1a' : '#888' ?>">
                <?= $lst['visible'] ? 'Visible' : 'Hidden' ?>
              </button>
            </td>
            <td>
              <div style="display:flex;gap:6px">
                <a href="openhouse_add.php?id=<?= $lst['id'] ?>"
                   style="padding:4px 10px;border:1px solid #ccc;background:white;border-radius:4px;font-size:12px;text-decoration:none;color:#333">Edit</a>
                <button onclick="deleteListing(<?= $lst['id'] ?>)"
                        style="padding:4px 10px;border:1px solid #fcc;background:white;border-radius:4px;font-size:12px;cursor:pointer;color:#c00">Delete</button>
              </div>
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
function togglePending(id) {
  const el = document.getElementById('pending-' + id);
  el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

function respondReq(reqId, decision) {
  const reason = document.getElementById('reason-' + reqId)?.value || '';
  const msgEl  = document.getElementById('rmsg-' + reqId);
  const fd = new FormData();
  fd.append('action',     'respond');
  fd.append('request_id', reqId);
  fd.append('decision',   decision);
  fd.append('reason',     reason);
  fetch('api/oh_action.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        const row = document.getElementById('preq-' + reqId);
        if (row) row.remove();
        location.reload();
      } else {
        msgEl.textContent = d.error || 'Error';
        msgEl.style.color = '#c00';
      }
    })
    .catch(() => { msgEl.textContent = 'Network error.'; msgEl.style.color = '#c00'; });
}

function toggleVisible(listingId) {
  const fd = new FormData();
  fd.append('action',     'toggle_visible');
  fd.append('listing_id', listingId);
  fetch('api/oh_action.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        const btn = document.getElementById('vis-btn-' + listingId);
        if (d.visible) {
          btn.textContent = 'Visible'; btn.style.color = '#3a6b1a';
        } else {
          btn.textContent = 'Hidden'; btn.style.color = '#888';
        }
      } else {
        alert(d.error || 'Error toggling visibility.');
      }
    })
    .catch(() => alert('Network error.'));
}

function deleteListing(listingId) {
  if (!confirm('Delete this listing? This cannot be undone.')) return;
  const fd = new FormData();
  fd.append('action',     'delete_listing');
  fd.append('listing_id', listingId);
  fetch('api/oh_action.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        const row = document.getElementById('row-' + listingId);
        if (row) row.remove();
      } else {
        alert(d.error || 'Error deleting listing.');
      }
    })
    .catch(() => alert('Network error.'));
}
</script>
</body>
</html>
