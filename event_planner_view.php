<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

$db    = local_db();
$email = strtolower(trim($agent['email']));
$id    = (int)($_GET['id'] ?? 0);

$s = $db->prepare("SELECT * FROM ep_events WHERE id=?");
$s->execute([$id]);
$ev = $s->fetch(PDO::FETCH_ASSOC);
if (!$ev) { header('Location: event_planner.php'); exit; }

function ep_view_can_manage(array $ev, string $email): bool {
    if (is_admin()) return true;
    if (strtolower($ev['created_by']) === $email) return true;
    if ((is_mc_leader() || is_bic()) && $ev['mc_slug'] !== '' && in_array($ev['mc_slug'], my_mc_slugs(), true)) return true;
    return false;
}
function ep_view_visible(array $ev): bool {
    if ($ev['mc_slug'] === '') return true;
    $slugs = array_values(array_unique(array_filter(array_merge(my_mc_slugs(), [my_own_mc_slug()]))));
    return in_array($ev['mc_slug'], $slugs, true);
}

$canManage = ep_view_can_manage($ev, $email);
if (!$canManage && !($ev['status'] === 'published' && ep_view_visible($ev))) {
    header('Location: event_planner.php'); exit;
}

$mcName = '';
if ($ev['mc_slug'] !== '') {
    $m = $db->prepare("SELECT name FROM market_centers WHERE slug=?");
    $m->execute([$ev['mc_slug']]);
    $mcName = $m->fetchColumn() ?: $ev['mc_slug'];
}

$publicUrl = 'event_public.php?t=' . urlencode($ev['public_token']);
$imageUrl  = $ev['image_key'] ? ('api/ep_event_image.php?key=' . urlencode($ev['image_key'])) : null;

$myReg = null;
if (!$canManage) {
    $r = $db->prepare("SELECT * FROM ep_registrations WHERE event_id=? AND email=? AND status='registered'");
    $r->execute([$id, $email]);
    $myReg = $r->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($ev['title']) ?> — Event Planner — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .ep-status { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; padding:3px 9px; border-radius:3px; }
    .ep-status-draft     { background:#f3f4f6; color:#6b7280; }
    .ep-status-published { background:#f0fdf4; color:#15803d; }
    .ep-status-cancelled { background:#fef2f2; color:#b91c1c; }
    .ep-detail-meta { display:flex; flex-direction:column; gap:6px; font-size:13px; color:#444; margin:14px 0; }
    .ep-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:16px; }
    .btn-primary { padding:9px 20px; background:#82C112; color:#000; border:none; border-radius:6px; font-weight:800; font-size:13px; cursor:pointer; }
    .btn-primary:hover { background:#5b8e0d; color:#fff; }
    .btn-sm { padding:7px 14px; font-size:12px; font-weight:700; border-radius:6px; border:none; cursor:pointer; background:#f0f0f0; color:#333; }
    .btn-danger { background:#fee2e2; color:#c00; }
    .link-box { display:flex; gap:8px; align-items:center; margin-top:8px; }
    .link-box input { flex:1; padding:8px 10px; border:1px solid #ccc; border-radius:6px; font-size:12px; color:#555; }
    .attend-table { width:100%; border-collapse:collapse; font-size:13px; margin-top:10px; }
    .attend-table th { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#aaa; border-bottom:1px solid #eee; padding:8px 10px; text-align:left; }
    .attend-table td { padding:9px 10px; border-bottom:1px solid #f5f5f5; }
    .empty-note { color:#bbb; font-size:13px; padding:16px; text-align:center; }
    .section-title { font-size:13px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#444; margin:24px 0 4px; }
    .ep-hero { height:280px; margin:-20px -24px 20px; background-size:cover; background-position:center; border-radius:8px 8px 0 0; position:relative; }
    .ep-hero-edit { position:absolute; bottom:12px; right:12px; display:flex; gap:8px; }
    .ep-hero-edit .btn-sm { background:rgba(255,255,255,.92); }
    .ep-form { background:#f9fdf5; border:1px solid #d4edab; border-radius:10px; padding:16px 18px; }
    .field-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:12px; }
    .field-row-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; margin-bottom:12px; }
    .field-full { margin-bottom:12px; }
    .field label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#888; margin-bottom:4px; }
    .field input, .field textarea { width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:6px; font-size:13px; box-sizing:border-box; }
    .field textarea { min-height:72px; resize:vertical; }
    .agenda-item { border-left:3px solid #82C112; padding:8px 12px; margin-bottom:8px; background:#fafffa; border-radius:0 6px 6px 0; }
    .agenda-item-head { display:flex; justify-content:space-between; align-items:baseline; gap:8px; }
    .agenda-time { font-size:11px; font-weight:800; color:#5b8e0d; white-space:nowrap; }
    .agenda-title { font-size:13px; font-weight:700; color:#222; }
    .agenda-meta { font-size:11px; color:#888; margin-top:2px; }
    .invite-table { width:100%; border-collapse:collapse; font-size:12px; margin-top:10px; }
    .invite-table th { font-size:10px; font-weight:700; text-transform:uppercase; color:#aaa; border-bottom:1px solid #eee; padding:6px 8px; text-align:left; }
    .invite-table td { padding:6px 8px; border-bottom:1px solid #f5f5f5; }
    .invite-status { font-size:10px; font-weight:800; text-transform:uppercase; padding:2px 7px; border-radius:3px; }
    .invite-status-queued { background:#f3f4f6; color:#6b7280; }
    .invite-status-sent   { background:#f0fdf4; color:#15803d; }
    .invite-status-failed { background:#fef2f2; color:#b91c1c; }
    .ep-grid-recs { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:10px; margin-top:8px; }
    .rec-card { border:1px solid #e5e7eb; border-radius:8px; padding:12px; position:relative; }
    .rec-name { font-size:13px; font-weight:700; color:#222; margin-bottom:3px; }
    .rec-desc { font-size:11px; color:#666; line-height:1.5; }
    .rec-badge { display:inline-block; padding:2px 7px; border-radius:3px; font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px; }
    .rec-badge-food       { background:#fffbeb; color:#92400e; }
    .rec-badge-attraction { background:#eef5e8; color:#5b8e0d; }
    .rec-badge-nightlife  { background:#f5f3ff; color:#5b21b6; }
    .rec-badge-shopping   { background:#eff6ff; color:#1d4ed8; }
    .rec-badge-other      { background:#f3f4f6; color:#374151; }
    .rec-actions { position:absolute; top:8px; right:8px; display:flex; gap:4px; }
    .agenda-grid { display:grid; gap:8px; margin-bottom:8px; }
    .agenda-col-header { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:#888; text-align:center; margin-bottom:2px; }
    .agenda-cell { border-left:3px solid #82C112; padding:8px 10px; background:#fafffa; border-radius:0 6px 6px 0; min-height:40px; }
    .agenda-cell.empty { border-left-color:#e5e7eb; background:#fafafa; color:#ccc; font-size:11px; display:flex; align-items:center; justify-content:center; }
    .agenda-slot-time { font-size:11px; font-weight:800; color:#5b8e0d; margin-bottom:4px; }
  </style>
</head>
<body>
  <div class="layout">
    <?php render_sidebar('event_planner', $agent); ?>
    <div class="content">
      <header class="content-top">
        <div class="content-title"><?= h($ev['title']) ?></div>
        <a href="event_planner.php" style="font-size:12px;color:#82C112;font-weight:700;text-decoration:none">← All Events</a>
      </header>

      <main class="wrap">
        <section class="card" style="padding:20px 24px">
          <?php if ($imageUrl): ?>
            <div class="ep-hero" style="background-image:url('<?= h($imageUrl) ?>')">
              <?php if ($canManage): ?>
              <div class="ep-hero-edit">
                <button class="btn-sm" onclick="document.getElementById('ep-image-input').click()">Change Photo</button>
                <button class="btn-sm btn-danger" onclick="removeImage()">Remove</button>
              </div>
              <?php endif; ?>
            </div>
          <?php elseif ($canManage): ?>
            <button class="btn-sm" style="margin-bottom:14px" onclick="document.getElementById('ep-image-input').click()">+ Add Cover Photo</button>
          <?php endif; ?>
          <?php if ($canManage): ?>
            <input type="file" id="ep-image-input" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none" onchange="uploadImage(this.files[0])">
          <?php endif; ?>
          <span class="ep-status ep-status-<?= h($ev['status']) ?>"><?= h($ev['status']) ?></span>
          <div class="ep-detail-meta">
            <div><strong>When:</strong> <?= h($ev['start_date']) ?><?= $ev['end_date'] && $ev['end_date'] !== $ev['start_date'] ? ' – ' . h($ev['end_date']) : '' ?><?= $ev['start_time'] ? ' · ' . h($ev['start_time']) : '' ?></div>
            <div><strong>Location:</strong> <?= h($ev['location'] ?: '—') ?></div>
            <div><strong>Hosted by:</strong> <?= $ev['mc_slug'] === '' ? 'INNOVATE (company-wide)' : h($mcName) ?></div>
            <div id="reg-count"><strong>Capacity:</strong> <?= $ev['capacity'] ? h($ev['capacity']) : 'Unlimited' ?></div>
          </div>
          <?php if ($ev['description']): ?>
            <p style="font-size:13px;color:#555;line-height:1.6"><?= nl2br(h($ev['description'])) ?></p>
          <?php endif; ?>

          <?php if ($ev['room_block_hotel']): ?>
          <div class="section-title">Where to Stay</div>
          <div class="agenda-item" style="border-left-color:#3b82f6;background:#f5f9ff">
            <div class="agenda-title"><?= h($ev['room_block_hotel']) ?></div>
            <div class="agenda-meta">
              <?= h($ev['room_block_rate']) ?>
              <?= $ev['room_block_code'] ? ' · Code: ' . h($ev['room_block_code']) : '' ?>
              <?= $ev['room_block_cutoff'] ? ' · Book by ' . h($ev['room_block_cutoff']) : '' ?>
            </div>
            <?php if ($ev['room_block_url']): ?>
              <a href="<?= h($ev['room_block_url']) ?>" target="_blank" rel="noopener" style="font-size:12px;font-weight:700;color:#1d4ed8">Book Now ↗</a>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <div class="section-title" id="rec-title" style="display:none">Things to Do Nearby</div>
          <div id="rec-list" class="ep-grid-recs"></div>
          <?php if ($canManage): ?>
            <button class="btn-sm" style="margin-top:8px" onclick="toggleRecForm()">+ Add Recommendation</button>
            <div class="ep-form" id="rec-form" style="display:none; margin-top:10px">
              <input type="hidden" id="rec-id" value="">
              <div class="field-row">
                <div class="field"><label>Name *</label><input type="text" id="rec-name" placeholder="e.g. Sea Captain's House"></div>
                <div class="field"><label>Category</label>
                  <select id="rec-category">
                    <option value="food">Food &amp; Dining</option>
                    <option value="attraction">Attraction</option>
                    <option value="nightlife">Nightlife</option>
                    <option value="shopping">Shopping</option>
                    <option value="other">Other</option>
                  </select>
                </div>
              </div>
              <div class="field-full field"><label>Link</label><input type="url" id="rec-url" placeholder="https://…"></div>
              <div class="field-full field"><label>Description</label><textarea id="rec-desc"></textarea></div>
              <div class="ep-actions">
                <button class="btn-primary" onclick="saveRec()">Save</button>
                <button class="btn-sm" onclick="toggleRecForm()">Cancel</button>
              </div>
            </div>
          <?php endif; ?>

          <div class="section-title" id="agenda-title" style="display:none">Agenda</div>
          <div id="agenda-list"></div>
          <?php if ($canManage): ?>
            <button class="btn-sm" style="margin-top:8px" onclick="toggleSessionForm()">+ Add Session</button>
            <div class="ep-form" id="session-form" style="display:none; margin-top:10px">
              <input type="hidden" id="sess-id" value="">
              <div class="field-full field"><label>Session Title *</label><input type="text" id="sess-title"></div>
              <div class="field-row-3">
                <div class="field"><label>Date</label><input type="date" id="sess-date" value="<?= h($ev['start_date']) ?>"></div>
                <div class="field"><label>Start Time</label><input type="time" id="sess-start"></div>
                <div class="field"><label>End Time</label><input type="time" id="sess-end"></div>
              </div>
              <div class="field-row">
                <div class="field"><label>Room</label><input type="text" id="sess-room"></div>
                <div class="field"><label>Speaker</label><input type="text" id="sess-speaker"></div>
              </div>
              <div class="field-full field"><label>Track (optional)</label><input type="text" id="sess-track" placeholder="Leave blank for a full-schedule item like a keynote or meal. Fill in for a breakout, e.g. &quot;Track A: Sales&quot;."></div>
              <div class="field-full field"><label>Description</label><textarea id="sess-desc"></textarea></div>
              <div class="ep-actions">
                <button class="btn-primary" onclick="saveSession()">Save Session</button>
                <button class="btn-sm" onclick="toggleSessionForm()">Cancel</button>
              </div>
            </div>
          <?php endif; ?>

          <div class="ep-actions" id="agent-actions"></div>

          <?php if ($canManage): ?>
          <div class="ep-actions">
            <?php if ($ev['status'] === 'draft'): ?>
              <button class="btn-primary" onclick="doAction('publish')">Publish Event</button>
            <?php elseif ($ev['status'] === 'published'): ?>
              <button class="btn-sm btn-danger" onclick="doAction('cancel')">Cancel Event</button>
            <?php endif; ?>
            <button class="btn-sm" onclick="toggleEdit()">Edit Details</button>
            <button class="btn-sm" onclick="cloneEvent()">Clone Event</button>
          </div>

          <div class="ep-form" id="edit-form" style="display:none; margin-top:14px">
            <div class="field-row">
              <div class="field"><label>Title *</label><input type="text" id="ed-title" value="<?= h($ev['title']) ?>"></div>
              <div class="field"><label>Location</label><input type="text" id="ed-location" value="<?= h($ev['location']) ?>"></div>
            </div>
            <div class="field-row-3">
              <div class="field"><label>Start Date *</label><input type="date" id="ed-start" value="<?= h($ev['start_date']) ?>"></div>
              <div class="field"><label>End Date</label><input type="date" id="ed-end" value="<?= h($ev['end_date']) ?>"></div>
              <div class="field"><label>Start Time</label><input type="time" id="ed-time" value="<?= h($ev['start_time']) ?>"></div>
            </div>
            <div class="field-row">
              <div class="field"><label>Capacity</label><input type="number" min="1" id="ed-capacity" value="<?= h($ev['capacity'] ?? '') ?>" placeholder="Unlimited"></div>
            </div>
            <div class="field-full field"><label>Description</label><textarea id="ed-desc"><?= h($ev['description']) ?></textarea></div>

            <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#5b8e0d;margin:16px 0 8px">Room Block (optional)</div>
            <div class="field-row">
              <div class="field"><label>Hotel</label><input type="text" id="ed-rb-hotel" value="<?= h($ev['room_block_hotel']) ?>" placeholder="e.g. Marriott Grande Dunes"></div>
              <div class="field"><label>Group Rate</label><input type="text" id="ed-rb-rate" value="<?= h($ev['room_block_rate']) ?>" placeholder="e.g. $189/night"></div>
            </div>
            <div class="field-row-3">
              <div class="field"><label>Booking Code</label><input type="text" id="ed-rb-code" value="<?= h($ev['room_block_code']) ?>"></div>
              <div class="field"><label>Booking Link</label><input type="url" id="ed-rb-url" value="<?= h($ev['room_block_url']) ?>" placeholder="https://…"></div>
              <div class="field"><label>Book-by Date</label><input type="date" id="ed-rb-cutoff" value="<?= h($ev['room_block_cutoff']) ?>"></div>
            </div>

            <div class="ep-actions">
              <button class="btn-primary" onclick="saveEdit()">Save Changes</button>
              <button class="btn-sm" onclick="toggleEdit()">Cancel</button>
            </div>
          </div>
          <?php if ($ev['status'] !== 'draft'): ?>
          <div class="section-title">Public Registration Link</div>
          <p style="font-size:12px;color:#888;margin:0">Share this with anyone — no AgentEdge login required.</p>
          <div class="link-box">
            <input type="text" readonly id="public-link" value="<?= h($publicUrl) ?>">
            <button class="btn-sm" onclick="copyLink()">Copy</button>
          </div>

          <div class="section-title">Invite by Email</div>
          <p style="font-size:12px;color:#888;margin:0 0 8px">Paste one email per line (or comma-separated). Up to 200 at a time.</p>
          <textarea id="invite-emails" style="width:100%;min-height:80px;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:12px;box-sizing:border-box" placeholder="jane@example.com&#10;john@example.com"></textarea>
          <div class="ep-actions">
            <button class="btn-primary" onclick="sendInvites()" id="invite-btn">Send Invites</button>
          </div>
          <table class="invite-table" id="invite-table" style="display:none">
            <thead><tr><th>Email</th><th>Status</th><th>Invited</th></tr></thead>
            <tbody id="invite-tbody"></tbody>
          </table>
          <?php endif; ?>

          <div class="section-title">Attendees</div>
          <table class="attend-table" id="attend-table">
            <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Guests</th><th>Source</th></tr></thead>
            <tbody id="attend-tbody"><tr><td colspan="5" class="empty-note">Loading…</td></tr></tbody>
          </table>
          <?php endif; ?>
        </section>
      </main>
    </div>
  </div>

  <script>
  (function () {
    var EVENT_ID   = <?= (int)$id ?>;
    var CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;
    var MY_REG     = <?= $myReg ? json_encode($myReg) : 'null' ?>;
    var STATUS     = <?= json_encode($ev['status']) ?>;

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]);}); }

    window.copyLink = function () {
      var el = document.getElementById('public-link');
      el.select();
      navigator.clipboard && navigator.clipboard.writeText(el.value);
    };

    window.uploadImage = function (file) {
      if (!file) return;
      var fd = new FormData();
      fd.append('action', 'update_image');
      fd.append('id', EVENT_ID);
      fd.append('image', file);
      fetch('api/ep_events.php', { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.ok) location.reload(); else alert(d.error || 'Error uploading image'); });
    };

    window.removeImage = function () {
      if (!confirm('Remove the cover photo?')) return;
      fetch('api/ep_events.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_image', id: EVENT_ID, remove_image: 1 }),
      }).then(function () { location.reload(); });
    };

    window.toggleEdit = function () {
      var f = document.getElementById('edit-form');
      f.style.display = f.style.display === 'none' ? '' : 'none';
    };

    window.saveEdit = function () {
      var title = document.getElementById('ed-title').value.trim();
      var start = document.getElementById('ed-start').value;
      if (!title) { alert('Title is required.'); return; }
      if (!start) { alert('Start date is required.'); return; }
      fetch('api/ep_events.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'update', id: EVENT_ID, title: title,
          location: document.getElementById('ed-location').value.trim(),
          start_date: start,
          end_date: document.getElementById('ed-end').value,
          start_time: document.getElementById('ed-time').value,
          capacity: document.getElementById('ed-capacity').value,
          description: document.getElementById('ed-desc').value.trim(),
          room_block_hotel: document.getElementById('ed-rb-hotel').value.trim(),
          room_block_rate: document.getElementById('ed-rb-rate').value.trim(),
          room_block_code: document.getElementById('ed-rb-code').value.trim(),
          room_block_url: document.getElementById('ed-rb-url').value.trim(),
          room_block_cutoff: document.getElementById('ed-rb-cutoff').value,
        }),
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.ok) location.reload(); else alert(d.error || 'Error saving changes');
      });
    };

    window.cloneEvent = function () {
      if (!confirm('Clone this event into a new draft?')) return;
      fetch('api/ep_events.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'clone', id: EVENT_ID }),
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.ok) window.location.href = 'event_planner_view.php?id=' + d.id;
        else alert(d.error || 'Error cloning event');
      });
    };

    window.doAction = function (action) {
      fetch('api/ep_events.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: action, id: EVENT_ID }),
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.ok) location.reload(); else alert(d.error || 'Error');
      });
    };

    var REC_LABELS = { food: 'Food & Dining', attraction: 'Attraction', nightlife: 'Nightlife', shopping: 'Shopping', other: 'Other' };
    var recs = [];

    function renderRecs() {
      var titleEl = document.getElementById('rec-title');
      var listEl = document.getElementById('rec-list');
      if (!recs.length) { titleEl.style.display = 'none'; listEl.innerHTML = ''; return; }
      titleEl.style.display = '';
      listEl.innerHTML = recs.map(function (r) {
        var editBtns = CAN_MANAGE
          ? '<div class="rec-actions"><button class="btn-sm" style="padding:2px 6px;font-size:10px" onclick="editRec(' + r.id + ')">Edit</button>'
            + '<button class="btn-sm btn-danger" style="padding:2px 6px;font-size:10px" onclick="deleteRec(' + r.id + ')">×</button></div>'
          : '';
        return '<div class="rec-card">' + editBtns
          + '<span class="rec-badge rec-badge-' + esc(r.category) + '">' + esc(REC_LABELS[r.category] || r.category) + '</span>'
          + '<div class="rec-name">' + esc(r.name) + '</div>'
          + (r.description ? '<div class="rec-desc">' + esc(r.description) + '</div>' : '')
          + (r.url ? '<div style="margin-top:6px"><a href="' + esc(r.url) + '" target="_blank" rel="noopener" style="font-size:11px;font-weight:700;color:#5b8e0d">Visit ↗</a></div>' : '')
          + '</div>';
      }).join('');
    }

    function loadRecs() {
      fetch('api/ep_recommendations.php?event_id=' + EVENT_ID, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { recs = d.recommendations || []; renderRecs(); });
    }

    window.toggleRecForm = function () {
      var f = document.getElementById('rec-form');
      f.style.display = f.style.display === 'none' ? '' : 'none';
      if (f.style.display !== 'none') {
        document.getElementById('rec-id').value = '';
        ['rec-name','rec-url','rec-desc'].forEach(function (id) { document.getElementById(id).value = ''; });
        document.getElementById('rec-category').value = 'food';
      }
    };

    window.editRec = function (id) {
      var r = recs.find(function (x) { return x.id === id; });
      if (!r) return;
      document.getElementById('rec-id').value = r.id;
      document.getElementById('rec-name').value = r.name;
      document.getElementById('rec-category').value = r.category;
      document.getElementById('rec-url').value = r.url;
      document.getElementById('rec-desc').value = r.description;
      document.getElementById('rec-form').style.display = '';
    };

    window.saveRec = function () {
      var id = document.getElementById('rec-id').value;
      var name = document.getElementById('rec-name').value.trim();
      if (!name) { alert('Name is required.'); return; }
      var payload = {
        action: id ? 'update' : 'create',
        event_id: EVENT_ID, name: name,
        category: document.getElementById('rec-category').value,
        url: document.getElementById('rec-url').value.trim(),
        description: document.getElementById('rec-desc').value.trim(),
      };
      if (id) payload.id = +id;
      fetch('api/ep_recommendations.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.ok) { toggleRecForm(); loadRecs(); } else alert(d.error || 'Error saving recommendation');
      });
    };

    window.deleteRec = function (id) {
      if (!confirm('Delete this recommendation?')) return;
      fetch('api/ep_recommendations.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id: id }),
      }).then(function () { loadRecs(); });
    };

    var sessions = [];

    function fmtSessTime(t) {
      if (!t) return '';
      var p = t.split(':'); var h = +p[0]; var ap = h >= 12 ? 'PM' : 'AM'; var h12 = h % 12 || 12;
      return h12 + ':' + p[1] + ' ' + ap;
    }

    // Renders a flat sessions array as a schedule: track-less items render full-width
    // (plenary), and any time slot containing a tracked session renders as a
    // side-by-side grid with one fixed column per distinct track used anywhere
    // in the event, so a given track always lands in the same column.
    function buildAgendaHtml(sessionsList, editButtonsFn) {
      if (!sessionsList.length) return '';
      var tracks = [];
      sessionsList.forEach(function (s) { if (s.track && tracks.indexOf(s.track) === -1) tracks.push(s.track); });

      var slotOrder = [], slots = {};
      sessionsList.forEach(function (s) {
        var key = s.session_date + '|' + s.start_time + '|' + s.end_time;
        if (!slots[key]) { slots[key] = []; slotOrder.push(key); }
        slots[key].push(s);
      });

      var html = '';
      slotOrder.forEach(function (key) {
        var group = slots[key];
        var timeLabel = (fmtSessTime(group[0].start_time) + (group[0].end_time ? ' – ' + fmtSessTime(group[0].end_time) : '')) || group[0].session_date;
        var tracked = group.filter(function (s) { return s.track; });
        var plain   = group.filter(function (s) { return !s.track; });

        if (tracked.length) {
          var byTrack = {};
          tracked.forEach(function (s) { byTrack[s.track] = s; });
          html += '<div class="agenda-slot-time">' + esc(timeLabel) + '</div>'
            + '<div class="agenda-grid" style="grid-template-columns:repeat(' + tracks.length + ',1fr)">';
          tracks.forEach(function (tr) { html += '<div class="agenda-col-header">' + esc(tr) + '</div>'; });
          tracks.forEach(function (tr) {
            var s = byTrack[tr];
            if (!s) { html += '<div class="agenda-cell empty">—</div>'; return; }
            var meta = [s.room, s.speaker].filter(Boolean).join(' · ');
            html += '<div class="agenda-cell">' + (editButtonsFn ? '<div style="float:right">' + editButtonsFn(s) + '</div>' : '')
              + '<div class="agenda-title" style="font-size:12px">' + esc(s.title) + '</div>'
              + (meta ? '<div class="agenda-meta">' + esc(meta) + '</div>' : '') + '</div>';
          });
          html += '</div>';
        }
        plain.forEach(function (s) {
          var meta = [s.room, s.speaker].filter(Boolean).join(' · ');
          html += '<div class="agenda-item">'
            + '<div class="agenda-item-head"><span class="agenda-time">' + esc(timeLabel) + '</span>' + (editButtonsFn ? editButtonsFn(s) : '') + '</div>'
            + '<div class="agenda-title">' + esc(s.title) + '</div>'
            + (meta ? '<div class="agenda-meta">' + esc(meta) + '</div>' : '')
            + (s.description ? '<div class="agenda-meta">' + esc(s.description) + '</div>' : '')
            + '</div>';
        });
      });
      return html;
    }

    function renderSessions() {
      var listEl = document.getElementById('agenda-list');
      var titleEl = document.getElementById('agenda-title');
      if (!sessions.length) { titleEl.style.display = 'none'; listEl.innerHTML = ''; return; }
      titleEl.style.display = '';
      listEl.innerHTML = buildAgendaHtml(sessions, CAN_MANAGE ? function (s) {
        return '<span style="float:right"><button class="btn-sm" style="padding:2px 8px;font-size:10px" onclick="editSession(' + s.id + ')">Edit</button> '
          + '<button class="btn-sm btn-danger" style="padding:2px 8px;font-size:10px" onclick="deleteSession(' + s.id + ')">Delete</button></span>';
      } : null);
    }

    function loadSessions() {
      fetch('api/ep_sessions.php?event_id=' + EVENT_ID, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { sessions = d.sessions || []; renderSessions(); });
    }

    window.toggleSessionForm = function () {
      var f = document.getElementById('session-form');
      f.style.display = f.style.display === 'none' ? '' : 'none';
      if (f.style.display !== 'none') {
        document.getElementById('sess-id').value = '';
        ['sess-title','sess-start','sess-end','sess-room','sess-speaker','sess-track','sess-desc'].forEach(function (id) {
          document.getElementById(id).value = '';
        });
      }
    };

    window.editSession = function (id) {
      var s = sessions.find(function (x) { return x.id === id; });
      if (!s) return;
      document.getElementById('sess-id').value = s.id;
      document.getElementById('sess-title').value = s.title;
      document.getElementById('sess-date').value = s.session_date;
      document.getElementById('sess-start').value = s.start_time;
      document.getElementById('sess-end').value = s.end_time;
      document.getElementById('sess-room').value = s.room;
      document.getElementById('sess-speaker').value = s.speaker;
      document.getElementById('sess-track').value = s.track;
      document.getElementById('sess-desc').value = s.description;
      document.getElementById('session-form').style.display = '';
    };

    window.saveSession = function () {
      var id = document.getElementById('sess-id').value;
      var title = document.getElementById('sess-title').value.trim();
      if (!title) { alert('Session title is required.'); return; }
      var payload = {
        action: id ? 'update' : 'create',
        event_id: EVENT_ID,
        title: title,
        session_date: document.getElementById('sess-date').value,
        start_time: document.getElementById('sess-start').value,
        end_time: document.getElementById('sess-end').value,
        room: document.getElementById('sess-room').value.trim(),
        speaker: document.getElementById('sess-speaker').value.trim(),
        track: document.getElementById('sess-track').value.trim(),
        description: document.getElementById('sess-desc').value.trim(),
      };
      if (id) payload.id = +id;
      fetch('api/ep_sessions.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.ok) { toggleSessionForm(); loadSessions(); } else alert(d.error || 'Error saving session');
      });
    };

    window.deleteSession = function (id) {
      if (!confirm('Delete this session?')) return;
      fetch('api/ep_sessions.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id: id }),
      }).then(function () { loadSessions(); });
    };

    window.sendInvites = function () {
      var ta = document.getElementById('invite-emails');
      var raw = ta.value.trim();
      if (!raw) { alert('Paste at least one email address.'); return; }
      var btn = document.getElementById('invite-btn');
      btn.disabled = true; btn.textContent = 'Sending…';
      fetch('api/ep_invites.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event_id: EVENT_ID, emails: raw }),
      }).then(function (r) { return r.json(); }).then(function (d) {
        btn.disabled = false; btn.textContent = 'Send Invites';
        if (d.ok) {
          ta.value = '';
          var notes = [];
          if (d.skipped) notes.push(d.skipped + ' already invited');
          if (d.invalid) notes.push(d.invalid + ' not a valid email');
          alert('Queued ' + d.queued + ' invite' + (d.queued === 1 ? '' : 's') + (notes.length ? ' (' + notes.join(', ') + ')' : '') + '.');
          loadInvites();
        } else {
          alert(d.error || 'Error sending invites');
        }
      });
    };

    function loadInvites() {
      if (!CAN_MANAGE || STATUS !== 'published') return;
      fetch('api/ep_invites.php?event_id=' + EVENT_ID, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var rows = d.invites || [];
          var table = document.getElementById('invite-table');
          var tbody = document.getElementById('invite-tbody');
          if (!rows.length) { table.style.display = 'none'; return; }
          table.style.display = '';
          tbody.innerHTML = rows.map(function (r) {
            return '<tr><td>' + esc(r.email) + '</td><td><span class="invite-status invite-status-' + esc(r.status) + '">' + esc(r.status) + '</span></td><td>' + esc(r.invited_at) + '</td></tr>';
          }).join('');
        });
    }

    function renderAgentActions() {
      var box = document.getElementById('agent-actions');
      if (CAN_MANAGE || STATUS !== 'published') { box.innerHTML = ''; return; }
      if (MY_REG) {
        box.innerHTML = '<span style="font-size:13px;color:#5b8e0d;font-weight:700">✓ You\'re registered' +
          (MY_REG.guest_count ? ' (+' + MY_REG.guest_count + ' guest' + (MY_REG.guest_count>1?'s':'') + ')' : '') + '</span>' +
          '<button class="btn-sm" onclick="cancelMine()">Cancel RSVP</button>';
      } else {
        box.innerHTML = '<button class="btn-primary" onclick="registerMine()">RSVP</button>';
      }
    }

    window.registerMine = function () {
      var guests = prompt('How many additional guests are you bringing? (0 if just you)', '0');
      if (guests === null) return;
      fetch('api/ep_registrations.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'register', event_id: EVENT_ID, guest_count: parseInt(guests, 10) || 0 }),
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.ok) location.reload(); else alert(d.error || 'Error registering');
      });
    };

    window.cancelMine = function () {
      if (!confirm('Cancel your RSVP?')) return;
      fetch('api/ep_registrations.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'cancel', event_id: EVENT_ID }),
      }).then(function () { location.reload(); });
    };

    function loadAttendees() {
      if (!CAN_MANAGE) return;
      fetch('api/ep_registrations.php?event_id=' + EVENT_ID, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var rows = (d.registrations || []).filter(function (r) { return r.status === 'registered'; });
          var tbody = document.getElementById('attend-tbody');
          if (!rows.length) { tbody.innerHTML = '<tr><td colspan="5" class="empty-note">No registrations yet.</td></tr>'; return; }
          tbody.innerHTML = rows.map(function (r) {
            return '<tr><td>' + esc(r.name) + '</td><td>' + esc(r.email) + '</td><td>' + esc(r.phone) + '</td><td>' + esc(r.guest_count) + '</td><td>' + esc(r.source) + '</td></tr>';
          }).join('');
          var total = rows.reduce(function (a, r) { return a + 1 + (+r.guest_count || 0); }, 0);
          var countEl = document.getElementById('reg-count');
          if (countEl) countEl.innerHTML += ' <span style="color:#888">(' + total + ' registered)</span>';
        });
    }

    renderAgentActions();
    loadAttendees();
    loadSessions();
    loadInvites();
    loadRecs();
  })();
  </script>
</body>
</html>
