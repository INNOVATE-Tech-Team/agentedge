<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/oh_subnav.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

$db        = local_db();
$myEmail   = strtolower(trim($agent['email']));
$admin     = is_admin();

// Filter state
$filterState = trim($_GET['state'] ?? '');
$filterType  = trim($_GET['type']  ?? '');

// Load available listings (visible=1, not mine)
$whereExtra = '';
$params     = [];
if ($filterState !== '') { $whereExtra .= " AND l.state=?"; $params[] = $filterState; }
if ($filterType  !== '') { $whereExtra .= " AND l.property_type=?"; $params[] = $filterType; }

// Hide listings that have no current/active time slot, unless the listing is
// flagged no_schedule (vacant, intentionally left "available anytime").
$listQ = $db->prepare("
    SELECT l.*
    FROM oh_listings l
    WHERE l.visible=1
      AND LOWER(l.listing_agent_email) != ?
      AND (l.no_schedule=1 OR EXISTS (
          SELECT 1 FROM oh_slots s WHERE s.listing_id=l.id AND s.slot_date >= date('now')
      ))
      {$whereExtra}
    ORDER BY l.created_at DESC
");
array_unshift($params, $myEmail);
$listQ->execute($params);
$listings = $listQ->fetchAll(PDO::FETCH_ASSOC);

// For each listing, load its slots (with approved-count per slot)
$listingIds = array_column($listings, 'id');
$slotsByListing = [];
if ($listingIds) {
    $inPH = implode(',', array_fill(0, count($listingIds), '?'));
    $sQ   = $db->prepare("
        SELECT s.*,
               (SELECT COUNT(*) FROM oh_requests r WHERE r.slot_id=s.id AND r.status='approved') AS approved_count,
               (SELECT COUNT(*) FROM oh_requests r WHERE r.slot_id=s.id AND r.status='pending')  AS pending_count
        FROM oh_slots s
        WHERE s.listing_id IN ({$inPH})
          AND s.slot_date >= date('now')
        ORDER BY s.slot_date, s.start_time
    ");
    $sQ->execute($listingIds);
    foreach ($sQ->fetchAll(PDO::FETCH_ASSOC) as $slot) {
        $slotsByListing[$slot['listing_id']][] = $slot;
    }
}

// My pending/approved request slot IDs — so we can show "Requested" state
$myReqQ = $db->prepare("SELECT slot_id FROM oh_requests WHERE agent_email=? AND status IN ('pending','approved')");
$myReqQ->execute([$myEmail]);
$myRequestedSlots = array_flip(array_column($myReqQ->fetchAll(PDO::FETCH_ASSOC), 'slot_id'));

// Distinct states + types for filter dropdowns
$statesR = $db->query("SELECT DISTINCT state FROM oh_listings WHERE visible=1 ORDER BY state")->fetchAll(PDO::FETCH_COLUMN);
$typesR  = $db->query("SELECT DISTINCT property_type FROM oh_listings WHERE visible=1 ORDER BY property_type")->fetchAll(PDO::FETCH_COLUMN);

// max_per_slot pref for display
$maxPerSlot = (int)($db->query("SELECT value FROM oh_prefs WHERE key='max_per_slot'")->fetchColumn() ?: 1);
if ($maxPerSlot < 1) $maxPerSlot = 1;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Open House Pool — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="layout">
  <?php render_sidebar('openhouse', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Open House Pool</div>
    </header>
    <main class="wrap">
      <?php render_oh_subnav('pool', $admin); ?>

      <!-- Filters -->
      <div class="oh-filters">
        <form method="get" style="display:contents">
          <select name="state" onchange="this.form.submit()">
            <option value="">All States</option>
            <?php foreach ($statesR as $st): ?>
              <option value="<?= h($st) ?>"<?= $filterState===$st?' selected':'' ?>><?= h($st) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="type" onchange="this.form.submit()">
            <option value="">All Types</option>
            <?php foreach ($typesR as $pt): ?>
              <option value="<?= h($pt) ?>"<?= $filterType===$pt?' selected':'' ?>><?= h($pt) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($filterState || $filterType): ?>
            <a href="openhouse.php" style="font-size:12px;color:#666">Clear filters</a>
          <?php endif; ?>
        </form>
        <span style="margin-left:auto;font-size:12px;color:#888"><?= count($listings) ?> listing<?= count($listings)!==1?'s':'' ?></span>
      </div>

      <?php if (empty($listings)): ?>
        <div class="network-empty" style="margin-top:40px">No open house listings available right now.</div>
      <?php else: ?>
      <div class="oh-grid">
        <?php foreach ($listings as $lst):
            $slots = $slotsByListing[$lst['id']] ?? [];
        ?>
        <div class="oh-card-wrap">
          <div class="oh-card" id="card-<?= $lst['id'] ?>">
            <?php if ($lst['image_url']): ?>
              <img class="oh-card-img" src="<?= h($lst['image_url']) ?>" alt="Property photo" loading="lazy">
            <?php else: ?>
              <div class="oh-card-img-placeholder">&#127968;</div>
            <?php endif; ?>
            <?php if ($lst['vacate']): ?>
              <span class="oh-vacate-badge">Vacant</span>
            <?php endif; ?>
            <div class="oh-card-body">
              <div class="oh-card-addr"><?= h($lst['address']) ?>, <?= h($lst['city']) ?>, <?= h($lst['state']) ?><?= $lst['zip'] ? ' '.$lst['zip'] : '' ?></div>
              <div style="font-size:12px;color:#888"><?= h($lst['property_type']) ?><?= $lst['list_price'] ? ' &middot; $'.number_format($lst['list_price']) : '' ?></div>
              <div class="oh-card-agent">Listed by <?= h($lst['listing_agent_name'] ?: $lst['listing_agent_email']) ?></div>

              <?php if (empty($slots)): ?>
                <div style="font-size:12px;color:#7c3aed;font-weight:600">Available anytime — contact listing agent to schedule</div>
              <?php else: ?>
                <div class="oh-card-slots">
                  <?php foreach ($slots as $slot):
                    $full      = $slot['approved_count'] >= $maxPerSlot;
                    $requested = isset($myRequestedSlots[$slot['id']]);
                    $fmtDate   = date('M j, Y', strtotime($slot['slot_date']));
                    $fmtStart  = date('g:i A', strtotime($slot['start_time']));
                    $fmtEnd    = date('g:i A', strtotime($slot['end_time']));
                  ?>
                  <div class="oh-slot-chip">
                    <span><?= h($fmtDate) ?> &middot; <?= h($fmtStart) ?>–<?= h($fmtEnd) ?></span>
                    <?php if ($requested): ?>
                      <span class="badge-pending">Requested</span>
                    <?php elseif ($full): ?>
                      <span style="font-size:10px;color:#bbb">Full</span>
                    <?php endif; ?>
                  </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <?php
              // Only show Request button if there's at least one slot not already full and not already requested
              $hasAvailSlot = false;
              foreach ($slots as $slot) {
                  if (!isset($myRequestedSlots[$slot['id']]) && $slot['approved_count'] < $maxPerSlot) {
                      $hasAvailSlot = true;
                      break;
                  }
              }
              ?>
              <?php if ($hasAvailSlot): ?>
                <button class="btn-save" style="margin-top:auto;font-size:12px;padding:7px 14px"
                        onclick="toggleReqForm(<?= $lst['id'] ?>)">
                  Request Open House
                </button>

                <!-- Inline request form -->
                <div id="req-form-<?= $lst['id'] ?>" style="display:none;margin-top:10px;padding:12px;background:#f9f9f9;border:1px solid #e6e7e8;border-radius:6px">
                  <div style="font-size:12px;font-weight:700;margin-bottom:8px">Select a time slot:</div>
                  <div style="display:flex;flex-direction:column;gap:6px" id="slots-<?= $lst['id'] ?>">
                    <?php foreach ($slots as $slot):
                      if (isset($myRequestedSlots[$slot['id']])) continue;
                      if ($slot['approved_count'] >= $maxPerSlot) continue;
                      $fmtDate  = date('M j, Y', strtotime($slot['slot_date']));
                      $fmtStart = date('g:i A', strtotime($slot['start_time']));
                      $fmtEnd   = date('g:i A', strtotime($slot['end_time']));
                    ?>
                    <label style="display:flex;align-items:center;gap:8px;font-size:12px;cursor:pointer">
                      <input type="radio" name="slot_pick_<?= $lst['id'] ?>" value="<?= $slot['id'] ?>">
                      <?= h($fmtDate) ?> &middot; <?= h($fmtStart) ?>–<?= h($fmtEnd) ?>
                    </label>
                    <?php endforeach; ?>
                  </div>
                  <div style="display:flex;gap:8px;margin-top:10px">
                    <button class="btn-save" style="font-size:12px;padding:6px 14px"
                            onclick="submitRequest(<?= $lst['id'] ?>)">Submit Request</button>
                    <button onclick="toggleReqForm(<?= $lst['id'] ?>)"
                            style="padding:6px 12px;border:1px solid #ccc;background:white;border-radius:6px;font-size:12px;cursor:pointer">Cancel</button>
                  </div>
                  <div id="req-msg-<?= $lst['id'] ?>" style="margin-top:6px;font-size:12px"></div>
                </div>
              <?php elseif (!empty($slots)): ?>
                <div style="margin-top:auto;font-size:12px;color:#888;font-style:italic">All slots requested or full</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </main>
  </div>
</div>
<script>
function toggleReqForm(id) {
  const f = document.getElementById('req-form-' + id);
  f.style.display = f.style.display === 'none' ? 'block' : 'none';
}

function submitRequest(listingId) {
  const picked = document.querySelector('input[name="slot_pick_' + listingId + '"]:checked');
  const msgEl  = document.getElementById('req-msg-' + listingId);
  if (!picked) { msgEl.textContent = 'Please select a time slot.'; msgEl.style.color = '#c00'; return; }

  const fd = new FormData();
  fd.append('action',  'request_slot');
  fd.append('slot_id', picked.value);

  fetch('api/oh_action.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        msgEl.textContent = 'Request submitted!';
        msgEl.style.color = '#3a6b1a';
        // After a moment, refresh the card state
        setTimeout(() => location.reload(), 1200);
      } else {
        msgEl.textContent = d.error || 'Error. Please try again.';
        msgEl.style.color = '#c00';
      }
    })
    .catch(() => { msgEl.textContent = 'Network error.'; msgEl.style.color = '#c00'; });
}
</script>
</body>
</html>
