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

function oh_time_options(string $sel = ''): string {
    $out = '<option value="">— select —</option>';
    for ($h = 6; $h <= 21; $h++) {
        foreach ([0, 30] as $m) {
            if ($h === 21 && $m === 30) continue;
            $v    = sprintf('%02d:%02d', $h, $m);
            $lbl  = date('g:i A', strtotime($v));
            $match = ($sel === $v || $sel === $v . ':00');
            $out .= '<option value="' . $v . '"' . ($match ? ' selected' : '') . '>' . $lbl . '</option>';
        }
    }
    return $out;
}
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
    /* Quick-pick grid */
    #quick-pick-section{margin-bottom:4px}
    .qp-scroll{display:flex;gap:12px;overflow-x:auto;padding-bottom:8px;scrollbar-width:thin}
    .qp-card{flex:0 0 180px;border:2px solid #e4e4e4;border-radius:10px;cursor:pointer;overflow:hidden;background:#fff;transition:border-color .15s,box-shadow .15s}
    .qp-card:hover{border-color:#6abf3a;box-shadow:0 2px 8px rgba(0,0,0,.1)}
    .qp-card.selected{border-color:#6abf3a;box-shadow:0 0 0 3px rgba(106,191,58,.2)}
    .qp-photo{width:100%;height:110px;object-fit:cover;display:block;background:#f0f0f0}
    .qp-no-photo{width:100%;height:110px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;color:#bbb;font-size:28px}
    .qp-info{padding:8px 10px 10px}
    .qp-addr{font-size:12px;font-weight:700;color:#222;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .qp-price{font-size:11px;color:#666;margin-top:2px}
    .qp-mls{font-size:10px;color:#aaa;margin-top:1px}
    /* Thumbnail preview */
    #photo-preview{margin-top:10px;display:none}
    #photo-preview img{max-width:220px;max-height:160px;border-radius:8px;border:1px solid #e0e0e0;object-fit:cover;display:block}
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

          <!-- Quick Pick from Agent's Active Listings -->
          <div id="quick-pick-section" style="display:none">
            <div class="oh-form-section" style="border-top:none;margin-top:0;padding-top:0">My Active Listings</div>
            <div id="quick-pick-grid" class="qp-scroll">
              <div style="color:#aaa;font-size:13px;padding:8px 0">Loading your listings…</div>
            </div>
          </div>

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
                     value="<?= h($listing['image_url'] ?? '') ?>" id="f-image"
                     oninput="updatePhotoPreview(this.value)">
              <div id="photo-preview">
                <img id="photo-img" src="<?= h($listing['image_url'] ?? '') ?>" alt="Property photo">
              </div>
            </div>
          </div>

          <!-- Options -->
          <div class="oh-form-section">Options</div>
          <div style="display:flex;gap:24px;flex-wrap:wrap">
            <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
              <input type="checkbox" name="vacate" value="1" id="f-vacate" onchange="onVacateChange()"<?= !empty($listing['vacate']) ? ' checked' : '' ?>>
              Vacant (seller will not be present during open house)
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
              <input type="checkbox" name="visible" value="1" id="f-visible"<?= !isset($listing) || !empty($listing['visible']) ? ' checked' : '' ?>>
              Visible in the available pool
            </label>
          </div>
          <div id="no-schedule-row" style="margin-top:12px;display:none">
            <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
              <input type="checkbox" name="no_schedule" value="1" id="f-no-schedule" onchange="onNoScheduleChange()"<?= !empty($listing['no_schedule']) ? ' checked' : '' ?>>
              No specific schedule — available anytime (skip time slots below)
            </label>
          </div>

          <!-- Time Slots -->
          <div class="oh-form-section" id="slots-section-label">Time Slots</div>
          <div id="slots-container">
            <?php if (!empty($slots)): ?>
              <?php foreach ($slots as $i => $slot): ?>
              <div class="slot-row" id="slot-<?= $i ?>">
                <input type="date" name="dates[]" class="slot-date" value="<?= h($slot['slot_date']) ?>" required>
                <select name="start_times[]" class="slot-time" required><?= oh_time_options($slot['start_time']) ?></select>
                <span class="slot-sep">to</span>
                <select name="end_times[]" class="slot-time" required><?= oh_time_options($slot['end_time']) ?></select>
                <button type="button" class="btn-remove-slot" onclick="removeSlot(this)">Remove</button>
              </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="slot-row" id="slot-0">
                <input type="date" name="dates[]" class="slot-date" required>
                <select name="start_times[]" class="slot-time" required><?= oh_time_options() ?></select>
                <span class="slot-sep">to</span>
                <select name="end_times[]" class="slot-time" required><?= oh_time_options() ?></select>
                <button type="button" class="btn-remove-slot" onclick="removeSlot(this)">Remove</button>
              </div>
            <?php endif; ?>
          </div>
          <button type="button" id="btn-add-slot" onclick="addSlot()"
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
let _agentListings = [];
<?php
$_tOpts = '<option value="">\xe2\x80\x94 select \xe2\x80\x94</option>';
for ($h = 6; $h <= 21; $h++) {
    foreach ([0, 30] as $m) {
        if ($h === 21 && $m === 30) continue;
        $v = sprintf('%02d:%02d', $h, $m);
        $l = date('g:i A', strtotime($v));
        $_tOpts .= '<option value="' . $v . '">' . $l . '</option>';
    }
}
?>
const timeOpts = <?= json_encode($_tOpts) ?>;

// ── Vacant / no-schedule toggling ────────────────────────────────────────────

function onVacateChange() {
  const vacate = document.getElementById('f-vacate').checked;
  const row     = document.getElementById('no-schedule-row');
  row.style.display = vacate ? '' : 'none';
  if (!vacate) {
    document.getElementById('f-no-schedule').checked = false;
    onNoScheduleChange();
  }
}

function onNoScheduleChange() {
  const noSchedule = document.getElementById('f-no-schedule').checked;
  const label      = document.getElementById('slots-section-label');
  const container  = document.getElementById('slots-container');
  const addBtn     = document.getElementById('btn-add-slot');
  label.style.display     = noSchedule ? 'none' : '';
  container.style.display = noSchedule ? 'none' : '';
  addBtn.style.display    = noSchedule ? 'none' : '';
  container.querySelectorAll('.slot-date, .slot-time').forEach(el => {
    el.required = !noSchedule;
  });
}

// ── Slot management ──────────────────────────────────────────────────────────

function addSlot() {
  const container = document.getElementById('slots-container');
  const div = document.createElement('div');
  div.className = 'slot-row';
  div.id = 'slot-' + slotCount;
  div.innerHTML = `
    <input type="date" name="dates[]" class="slot-date" required>
    <select name="start_times[]" class="slot-time" required>${timeOpts}</select>
    <span class="slot-sep">to</span>
    <select name="end_times[]" class="slot-time" required>${timeOpts}</select>
    <button type="button" class="btn-remove-slot" onclick="removeSlot(this)">Remove</button>
  `;
  container.appendChild(div);
  slotCount++;
}

function removeSlot(btn) {
  const row = btn.closest('.slot-row');
  if (document.querySelectorAll('.slot-row').length <= 1) {
    row.querySelectorAll('input').forEach(i => i.value = '');
    return;
  }
  row.remove();
}

// ── Fill form fields (shared by MLS lookup + quick-pick cards) ───────────────

function fillFields(d) {
  document.getElementById('f-address').value = d.address       || '';
  document.getElementById('f-city').value    = d.city          || '';
  document.getElementById('f-state').value   = d.state         || 'SC';
  document.getElementById('f-zip').value     = d.zip           || '';
  document.getElementById('f-price').value   = d.list_price    || '';
  document.getElementById('f-image').value   = d.image_url     || '';
  if (d.mls_number) document.getElementById('mls-input').value = d.mls_number;
  const sel = document.getElementById('f-proptype');
  for (let i = 0; i < sel.options.length; i++) {
    if (sel.options[i].value.toLowerCase() === (d.property_type || '').toLowerCase()) {
      sel.selectedIndex = i; break;
    }
  }
  updatePhotoPreview(d.image_url || '');
  document.getElementById('f-address').scrollIntoView({behavior: 'smooth', block: 'nearest'});
}

// ── Photo thumbnail preview ──────────────────────────────────────────────────

function updatePhotoPreview(url) {
  const wrap = document.getElementById('photo-preview');
  const img  = document.getElementById('photo-img');
  if (url && url.startsWith('http')) {
    img.src = url;
    wrap.style.display = '';
  } else {
    wrap.style.display = 'none';
  }
}

<?php if (!empty($listing['image_url'])): ?>
updatePhotoPreview(<?= json_encode($listing['image_url']) ?>);
<?php endif; ?>

// ── MLS manual lookup ────────────────────────────────────────────────────────

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
        fillFields(d);
        msg.textContent = 'Property found — fields pre-filled.';
        msg.style.color = '#3a6b1a';
      } else {
        msg.textContent = d.error === 'not configured' ? 'MLS lookup is not configured.' : (d.error || 'Property not found.');
        msg.style.color = '#c00';
      }
    })
    .catch(() => { msg.textContent = 'Lookup failed. Check your connection.'; msg.style.color = '#c00'; });
}

// ── Agent active listings quick-pick ─────────────────────────────────────────

function fmtPrice(n) {
  if (!n) return '';
  return '$' + Number(n).toLocaleString('en-US');
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function buildCard(l, idx) {
  const photo = l.image_url
    ? `<img class="qp-photo" src="${escHtml(l.image_url)}" alt="" loading="lazy" onerror="this.parentNode.innerHTML='<div class=qp-no-photo>🏠</div>'">`
    : `<div class="qp-no-photo">🏠</div>`;
  const addr  = l.address ? l.address.replace(/,.*$/, '') : '(no address)';
  return `<div class="qp-card" data-idx="${idx}">
    ${photo}
    <div class="qp-info">
      <div class="qp-addr" title="${escHtml(l.address)}">${escHtml(addr)}</div>
      <div class="qp-price">${escHtml(fmtPrice(l.list_price))}</div>
      <div class="qp-mls">MLS# ${escHtml(l.mls_number)}</div>
    </div>
  </div>`;
}

async function loadAgentListings() {
  const section = document.getElementById('quick-pick-section');
  const grid    = document.getElementById('quick-pick-grid');
  try {
    const r = await fetch('api/oh_agent_listings.php');
    const d = await r.json();
    if (!d.ok || !d.configured || !d.listings || !d.listings.length) {
      section.style.display = 'none';
      return;
    }
    _agentListings = d.listings;
    grid.innerHTML = d.listings.map((l, i) => buildCard(l, i)).join('');
    section.style.display = '';

    grid.addEventListener('click', e => {
      const card = e.target.closest('.qp-card');
      if (!card) return;
      document.querySelectorAll('.qp-card').forEach(c => c.classList.remove('selected'));
      card.classList.add('selected');
      fillFields(_agentListings[+card.dataset.idx]);
    });
  } catch(e) {
    section.style.display = 'none';
  }
}

loadAgentListings();
onVacateChange();
onNoScheduleChange();
</script>
</body>
</html>
