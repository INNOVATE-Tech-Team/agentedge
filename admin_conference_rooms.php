<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();
require_admin_page();

$db    = local_db();
$rooms = $db->query(
    "SELECT r.*, m.name AS mc_name, m.state_code
     FROM conference_rooms r JOIN market_centers m ON m.slug = r.mc_slug
     ORDER BY m.state_code, m.name, r.name"
)->fetchAll(PDO::FETCH_ASSOC);
$mcs = $db->query("SELECT slug, name, state_code FROM market_centers ORDER BY state_code, name")->fetchAll(PDO::FETCH_ASSOC);
$mcNameBySlug = [];
foreach ($mcs as $mc) { $mcNameBySlug[$mc['slug']] = $mc['state_code'] . ' - ' . $mc['name']; }

// Explicit office allow-list per room (see room_allowed_offices) -- a room
// with rows here is bookable from every office listed, not just its home
// mc_slug (e.g. "NMB Agent on Duty" spans six offices across two states).
$allowedByRoom = [];
foreach ($db->query("SELECT room_id, mc_slug FROM room_allowed_offices")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $allowedByRoom[(int)$row['room_id']][] = $row['mc_slug'];
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Conference Rooms — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .cr-add{background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px 24px;margin-bottom:24px}
    .cr-add h3{margin:0 0 14px;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
    .cr-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
    .cr-field{display:flex;flex-direction:column;gap:4px}
    .cr-field.grow{flex:1;min-width:200px}
    .cr-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)}
    .cr-input,.cr-select{padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:100%;box-sizing:border-box}
    .btn-add{padding:9px 20px;background:var(--green);color:#111;font-weight:800;font-size:13px;border:0;border-radius:6px;cursor:pointer;white-space:nowrap}
    .btn-add:hover{background:var(--green-d,#5b8e0d);color:#fff}
    .cr-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden}
    .cr-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);padding:10px 14px;text-align:left;border-bottom:1px solid var(--border)}
    .cr-table td{padding:9px 14px;border-top:1px solid var(--border);vertical-align:middle}
    .cr-table tr:last-child td{border-bottom:none}
    .btn-sm{padding:5px 12px;font-size:12px;font-weight:700;border-radius:5px;border:1px solid var(--border);background:#fff;cursor:pointer}
    .btn-sm:hover{background:#f5f5f5}
    .pill-disabled{color:#b00;font-weight:700;font-size:11px}
  </style>
</head>
<body>
  <div class="app-shell">
    <?php render_sidebar('conference_rooms', $agent); ?>
    <main class="main-content">
      <h1>Conference Rooms</h1>

      <div class="cr-add">
        <h3>Add Room</h3>
        <div class="cr-row">
          <div class="cr-field grow">
            <label class="cr-label">Market Center</label>
            <select id="cr-mc" class="cr-select">
              <?php foreach ($mcs as $mc): ?>
                <option value="<?= h($mc['slug']) ?>"><?= h($mc['state_code'] . ' - ' . $mc['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="cr-field grow">
            <label class="cr-label">Room Name</label>
            <input id="cr-name" class="cr-input" type="text" placeholder="Conference Room">
          </div>
          <button class="btn-add" onclick="crAdd()">Add Room</button>
        </div>
      </div>

      <table class="cr-table">
        <thead>
          <tr><th>Market Center</th><th>Room</th><th>Status</th><th>Allowed Offices</th><th>Notify Email</th><th></th></tr>
        </thead>
        <tbody id="cr-tbody">
          <?php foreach ($rooms as $r):
            $allowed = $allowedByRoom[(int)$r['id']] ?? [];
          ?>
          <tr data-id="<?= (int)$r['id'] ?>">
            <td><?= h($r['state_code'] . ' - ' . $r['mc_name']) ?></td>
            <td class="cr-name-cell"><?= h($r['name']) ?></td>
            <td><?= $r['enabled'] ? 'Enabled' : '<span class="pill-disabled">Disabled</span>' ?></td>
            <td class="cr-offices-cell">
              <?= $allowed ? h(implode(', ', array_map(fn($s) => $mcNameBySlug[$s] ?? $s, $allowed))) : '<span style="color:var(--faint)">Home MC only</span>' ?>
            </td>
            <td class="cr-notify-cell">
              <?= $r['notify_email'] !== '' ? h($r['notify_email']) : '<span style="color:var(--faint)">Not set</span>' ?>
            </td>
            <td>
              <button class="btn-sm" onclick="crRename(<?= (int)$r['id'] ?>)">Rename</button>
              <button class="btn-sm" onclick="crToggle(<?= (int)$r['id'] ?>)"><?= $r['enabled'] ? 'Disable' : 'Enable' ?></button>
              <button class="btn-sm" onclick="crManageOffices(<?= (int)$r['id'] ?>)">Manage Offices</button>
              <button class="btn-sm" onclick="crSetNotify(<?= (int)$r['id'] ?>, <?= h(json_encode($r['notify_email'])) ?>)">Notify Email</button>
              <button class="btn-sm" onclick="crDelete(<?= (int)$r['id'] ?>)">Delete</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div id="cr-offices-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;align-items:center;justify-content:center">
        <div style="background:#fff;border-radius:10px;padding:20px 24px;max-width:420px;width:90%;max-height:80vh;overflow-y:auto">
          <h3 style="margin:0 0 6px;font-size:14px;font-weight:800">Allowed Offices</h3>
          <p style="margin:0 0 14px;font-size:12px;color:var(--faint)">Leave all unchecked to use this room's home market center only. Check offices to make this room bookable from each of them, regardless of home market center (e.g. a duty assignment spanning several offices).</p>
          <div id="cr-offices-list" style="display:flex;flex-direction:column;gap:6px;font-size:13px"></div>
          <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end">
            <button class="btn-sm" onclick="crCloseOfficesModal()">Cancel</button>
            <button class="btn-add" onclick="crSaveOffices()">Save</button>
          </div>
        </div>
      </div>
    </main>
  </div>

<script>
const CR_MCS = <?= json_encode(array_map(fn($mc) => ['slug' => $mc['slug'], 'label' => $mc['state_code'] . ' - ' . $mc['name']], $mcs)) ?>;
const CR_ALLOWED_BY_ROOM = <?= json_encode(array_map('array_values', $allowedByRoom), JSON_FORCE_OBJECT) ?>;
let crOfficesRoomId = null;

function crManageOffices(id) {
  crOfficesRoomId = id;
  const current = new Set(CR_ALLOWED_BY_ROOM[id] || []);
  const list = document.getElementById('cr-offices-list');
  list.innerHTML = CR_MCS.map(mc => `
    <label><input type="checkbox" value="${mc.slug}" ${current.has(mc.slug) ? 'checked' : ''}> ${mc.label}</label>
  `).join('');
  document.getElementById('cr-offices-modal').style.display = 'flex';
}

function crCloseOfficesModal() {
  document.getElementById('cr-offices-modal').style.display = 'none';
  crOfficesRoomId = null;
}

async function crSaveOffices() {
  const mc_slugs = Array.from(document.querySelectorAll('#cr-offices-list input:checked')).map(el => el.value);
  const r = await crCall('set_allowed_offices', {id: crOfficesRoomId, mc_slugs});
  if (!r.ok) { alert(r.error || 'Failed to save'); return; }
  location.reload();
}

async function crCall(action, payload) {
  const res = await fetch('api/conference_room_action.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(Object.assign({action}, payload)),
  });
  return res.json();
}

async function crAdd() {
  const mc_slug = document.getElementById('cr-mc').value;
  const name = document.getElementById('cr-name').value.trim();
  if (!name) { alert('Room name is required'); return; }
  const r = await crCall('add', {mc_slug, name});
  if (!r.ok) { alert(r.error || 'Failed to add room'); return; }
  location.reload();
}

async function crRename(id) {
  const name = prompt('New room name:');
  if (!name) return;
  const r = await crCall('rename', {id, name});
  if (!r.ok) { alert(r.error || 'Failed to rename'); return; }
  location.reload();
}

async function crToggle(id) {
  const r = await crCall('toggle', {id});
  if (!r.ok) { alert(r.error || 'Failed to update'); return; }
  location.reload();
}

async function crSetNotify(id, current) {
  const email = prompt('Notify this address on every new booking (leave blank to clear):', current || '');
  if (email === null) return;
  const r = await crCall('set_notify_email', {id, notify_email: email.trim()});
  if (!r.ok) { alert(r.error || 'Failed to save'); return; }
  location.reload();
}

async function crDelete(id) {
  if (!confirm('Delete this room?')) return;
  const r = await crCall('delete', {id});
  if (!r.ok) { alert(r.error || 'Failed to delete'); return; }
  location.reload();
}
</script>
</body>
</html>
