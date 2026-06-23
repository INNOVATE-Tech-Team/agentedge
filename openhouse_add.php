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

// Load existing listing if editing
$editId  = (int)($_GET['id'] ?? 0);
$listing = null;
$slots   = [];

if ($editId > 0) {
    $q = $db->prepare("SELECT * FROM oh_listings WHERE id=?");
    $q->execute([$editId]);
    $listing = $q->fetch(PDO::FETCH_ASSOC);
    if (!$listing) {
        header('Location: openhouse_mine.php?err=notfound');
        exit;
    }
    if (strtolower($listing['listing_agent_email']) !== $myEmail && !$admin) {
        header('Location: openhouse_mine.php?err=forbidden');
        exit;
    }
    $sQ = $db->prepare("SELECT * FROM oh_slots WHERE listing_id=? ORDER BY slot_date, start_time");
    $sQ->execute([$editId]);
    $slots = $sQ->fetchAll(PDO::FETCH_ASSOC);
}

$propTypes = ['Residential','Condo','Townhouse','Land','Commercial'];
$pageTitle = $editId ? 'Edit Listing' : 'Add Listing';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($pageTitle) ?> — Open House — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .oh-form-section{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin:20px 0 8px;padding-top:16px;border-top:1px solid #eee}
    .oh-form-section:first-child{border-top:none;margin-top:0;padding-top:0}
    .slot-row{display:flex;gap:8px;align-items:center;padding:8px 10px;background:#f9f9f9;border:1px solid #eee;border-radius:6px;margin-bottom:8px}
    .slot-row input{padding:7px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;background:white}
    .slot-row .slot-date{flex:1.5}
    .slot-row .slot-time{flex:1}
    .slot-sep{font-size:12px;color:#888}
    .btn-remove-slot{padding:5px 10px;border:1px solid #ecc;background:white;border-radius:4px;font-size:12px;cursor:pointer;color:#c00;flex:none}
    .lookup-row{display:flex;gap:8px;align-items:center;margin-bottom:12px}
    .lookup-row input{flex:1;padding:9px 12px;border:1px solid #ccc;border-radius:6px;font-size:14px}
    .btn-lookup{padding:9px 16px;border:none;background:#222;color:white;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap}
    .btn-lookup:hover{background:#444}
    #lookup-msg{font-size:12px;margin-top:4px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('openhouse', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title"><?= h($pageTitle) ?></div>
      <a href="openhouse_mine.php" style="font-size:13px;color:#666;text-decoration:none">&larr; Back to My Listings</a>
    </header>
    <main class="wrap">
      <?php render_oh_subnav('mine', $admin); ?>

      <div class="card">
        <form method="post" action="openhouse_save.php" id="oh-form">
          <input type="hidden" name="id" value="<?= $editId ?>">

          <!-- MLS Lookup -->
          <div class="oh-form-section">MLS Lookup (optional)</div>
          <div class="lookup-row">
            <input type="text" id="mls-input" name="mls_number" placeholder="MLS #"
                   value="<?= h($listing['mls_number'] ?? '') ?>">
            <button type="button" class="btn-lookup" onclick="doMlsLookup()">Lookup</button>
          </div>
          <div id="lookup-msg"></div>

          <!-- Property Details -->
          <div class="oh-form-section">Property Details</div>
          <div class="form-grid">
            <div class="field full">
              <label>Street Address *</label>
              <input type="text" name="address" required placeholder="123 Main St"
                     value="<?= h($listing['address'] ?? '') ?>" id="f-address">
            </div>
            <div class="field">
              <label>City *</label>
              <input type="text" name="city" required placeholder="Myrtle Beach"
                     value="<?= h($listing['city'] ?? '') ?>" id="f-city">
            </div>
            <div class="field">
              <label>State</label>
              <input type="text" name="state" placeholder="SC" maxlength="2"
                     value="<?= h($listing['state'] ?? 'SC') ?>" id="f-state">
            </div>
            <div class="field">
              <label>ZIP Code</label>
              <input type="text" name="zip" placeholder="29577"
                     value="<?= h($listing['zip'] ?? '') ?>" id="f-zip">
            </div>
            <div class="field">
              <label>Property Type</label>
              <select name="property_type" id="f-proptype">
                <?php foreach ($propTypes as $pt): ?>
                  <option value="<?= h($pt) ?>"<?= ($listing['property_type'] ?? 'Residential')===$pt?' selected':'' ?>><?= h($pt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>List Price</label>
              <input type="number" name="list_price" placeholder="350000" min="0" step="1"
                     value="<?= h($listing['list_price'] ?? '') ?>" id="f-price">
            </div>
            <div class="field full">
              <label>Property Photo URL (optional)</label>
              <input type="url" name="image_url" placeholder="https://..."
                     value="<?= h($listing['image_url'] ?? '') ?>" id="f-image">
            </div>
          </div>

          <!-- Options -->
          <div class="oh-form-section">Options</div>
          <div style="display:flex;gap:24px;flex-wrap:wrap">
            <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
              <input type="checkbox" name="vacate" value="1" id="f-vacate"<?= !empty($listing['vacate']) ? ' checked' : '' ?>>
              Vacant (seller will not be present during open house)
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
              <input type="checkbox" name="visible" value="1" id="f-visible"<?= !isset($listing) || !empty($listing['visible']) ? ' checked' : '' ?>>
              Visible in the available pool
            </label>
          </div>

          <!-- Time Slots -->
          <div class="oh-form-section">Time Slots</div>
          <div id="slots-container">
            <?php if (!empty($slots)): ?>
              <?php foreach ($slots as $i => $slot): ?>
              <div class="slot-row" id="slot-<?= $i ?>">
                <input type="date" name="dates[]" class="slot-date" value="<?= h($slot['slot_date']) ?>" required>
                <input type="time" name="start_times[]" class="slot-time" value="<?= h($slot['start_time']) ?>" required>
                <span class="slot-sep">to</span>
                <input type="time" name="end_times[]" class="slot-time" value="<?= h($slot['end_time']) ?>" required>
                <button type="button" class="btn-remove-slot" onclick="removeSlot(this)">Remove</button>
              </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="slot-row" id="slot-0">
                <input type="date" name="dates[]" class="slot-date" required>
                <input type="time" name="start_times[]" class="slot-time" required>
                <span class="slot-sep">to</span>
                <input type="time" name="end_times[]" class="slot-time" required>
                <button type="button" class="btn-remove-slot" onclick="removeSlot(this)">Remove</button>
              </div>
            <?php endif; ?>
          </div>
          <button type="button" onclick="addSlot()"
                  style="margin-top:8px;padding:7px 14px;border:1px dashed #bbb;background:white;border-radius:6px;font-size:13px;cursor:pointer;color:#555">
            + Add Another Time Slot
          </button>

          <!-- Save -->
          <div class="form-actions" style="margin-top:24px;padding-top:16px;border-top:1px solid #eee">
            <button type="submit" class="btn-save">Save Listing</button>
            <a href="openhouse_mine.php" style="font-size:13px;color:#888;text-decoration:none">Cancel</a>
          </div>
        </form>
      </div>
    </main>
  </div>
</div>
<script>
let slotCount = <?= max(count($slots), 1) ?>;

function addSlot() {
  const container = document.getElementById('slots-container');
  const div = document.createElement('div');
  div.className = 'slot-row';
  div.id = 'slot-' + slotCount;
  div.innerHTML = `
    <input type="date" name="dates[]" class="slot-date" required>
    <input type="time" name="start_times[]" class="slot-time" required>
    <span class="slot-sep">to</span>
    <input type="time" name="end_times[]" class="slot-time" required>
    <button type="button" class="btn-remove-slot" onclick="removeSlot(this)">Remove</button>
  `;
  container.appendChild(div);
  slotCount++;
}

function removeSlot(btn) {
  const row = btn.closest('.slot-row');
  const allRows = document.querySelectorAll('.slot-row');
  if (allRows.length <= 1) {
    // Clear the fields instead of removing the last row
    row.querySelectorAll('input').forEach(i => i.value = '');
    return;
  }
  row.remove();
}

function doMlsLookup() {
  const mls = document.getElementById('mls-input').value.trim();
  const msg = document.getElementById('lookup-msg');
  if (!mls) { msg.textContent = 'Enter an MLS number first.'; msg.style.color = '#c00'; return; }
  msg.textContent = 'Looking up…';
  msg.style.color = '#888';

  fetch('api/oh_mls_lookup.php?mls=' + encodeURIComponent(mls))
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        document.getElementById('f-address').value  = d.address       || '';
        document.getElementById('f-city').value     = d.city          || '';
        document.getElementById('f-state').value    = d.state         || 'SC';
        document.getElementById('f-zip').value      = d.zip           || '';
        document.getElementById('f-price').value    = d.list_price    || '';
        document.getElementById('f-image').value    = d.image_url     || '';
        // Set property type
        const sel = document.getElementById('f-proptype');
        for (let i = 0; i < sel.options.length; i++) {
          if (sel.options[i].value.toLowerCase() === (d.property_type||'').toLowerCase()) {
            sel.selectedIndex = i; break;
          }
        }
        msg.textContent = 'Property found — fields pre-filled.';
        msg.style.color = '#3a6b1a';
      } else {
        msg.textContent = d.error === 'not configured' ? 'MLS lookup is not configured.' : (d.error || 'Property not found.');
        msg.style.color = '#c00';
      }
    })
    .catch(() => { msg.textContent = 'Lookup failed. Check your connection.'; msg.style.color = '#c00'; });
}
</script>
</body>
</html>
