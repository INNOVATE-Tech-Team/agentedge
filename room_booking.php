<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/room_booking.php';

$agent = require_login();
$db    = local_db();

$isAdmin      = is_admin();
$isLeaderUser = is_mc_leader() || is_bic();
$viewMcSlug   = my_own_mc_slug();
if ($isAdmin) {
    if (isset($_GET['mc'])) {
        $viewMcSlug = trim($_GET['mc']);
    } elseif ($viewMcSlug === '') {
        $viewMcSlug = $db->query("SELECT slug FROM market_centers ORDER BY name LIMIT 1")->fetchColumn() ?: '';
    }
} elseif ($isLeaderUser && isset($_GET['mc']) && in_array(trim($_GET['mc']), my_mc_slugs(), true)) {
    $viewMcSlug = trim($_GET['mc']);
}

// Admins can switch to any market center; mc_leader/bic can switch only
// among the MCs they lead (my_mc_slugs()) -- previously only admins could
// switch at all, so a leader of 5 offices was stuck viewing just their own.
$allMcs = [];
if ($isAdmin) {
    $allMcs = $db->query("SELECT slug, name, state_code FROM market_centers ORDER BY state_code, name")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($isLeaderUser && my_mc_slugs()) {
    $ledSlugs = my_mc_slugs();
    $ph = implode(',', array_fill(0, count($ledSlugs), '?'));
    $s = $db->prepare("SELECT slug, name, state_code FROM market_centers WHERE slug IN ($ph) ORDER BY state_code, name");
    $s->execute($ledSlugs);
    $allMcs = $s->fetchAll(PDO::FETCH_ASSOC);
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

if ($viewMcSlug === '') {
    // Not an admin and no home market center on file -- nothing to book.
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title>Book a Conference Room — AgentEdge</title>
      <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
      <link rel="stylesheet" href="assets/app.css">
    </head>
    <body>
      <div class="app-shell">
        <?php render_sidebar('room_booking', $agent); ?>
        <main class="main-content">
          <h1>Book a Conference Room</h1>
          <p>Your account isn't assigned to a market center yet. Contact an admin to get set up.</p>
        </main>
      </div>
    </body>
    </html>
    <?php
    exit;
}

$rooms = room_booking_rooms_for_mc($db, $viewMcSlug);
$preselectRoomId = isset($_GET['room']) ? (int)$_GET['room'] : null;

$canSeeAll = $isAdmin || ((is_mc_leader() || is_bic()) && in_array($viewMcSlug, my_mc_slugs(), true));

$nowEt = new DateTime('now', new DateTimeZone(ROOM_BOOKING_TIMEZONE));
$today = $nowEt->format('Y-m-d');
$nowHm = $nowEt->format('H:i');

if ($canSeeAll) {
    $bs = $db->prepare(
        "SELECT b.*, r.name AS room_name FROM room_bookings b
         JOIN conference_rooms r ON r.id = b.room_id
         WHERE r.mc_slug = ? AND b.status='booked'
           AND (b.booking_date > ? OR (b.booking_date = ? AND b.end_time > ?))
         ORDER BY b.booking_date, b.start_time"
    );
    $bs->execute([$viewMcSlug, $today, $today, $nowHm]);
} else {
    $bs = $db->prepare(
        "SELECT b.*, r.name AS room_name FROM room_bookings b
         JOIN conference_rooms r ON r.id = b.room_id
         WHERE r.mc_slug = ? AND b.agent_email = ? AND b.status='booked'
           AND (b.booking_date > ? OR (b.booking_date = ? AND b.end_time > ?))
         ORDER BY b.booking_date, b.start_time"
    );
    $bs->execute([$viewMcSlug, $agent['email'], $today, $today, $nowHm]);
}
$upcoming = $bs->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Book a Conference Room — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .rb-layout{display:flex;gap:24px;flex-wrap:wrap}
    .rb-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px 24px}
    .rb-picker{flex:1;min-width:320px}
    .rb-slots{flex:1;min-width:280px}
    .rb-field{display:flex;flex-direction:column;gap:4px;margin-bottom:14px}
    .rb-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)}
    .rb-select,.rb-input{padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:100%;box-sizing:border-box}
    .rb-cal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
    .rb-cal-head button{background:none;border:0;font-size:16px;cursor:pointer;color:var(--faint)}
    .rb-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;text-align:center}
    .rb-cal-grid .dow{font-size:11px;font-weight:700;color:var(--faint);padding:4px 0}
    .rb-cal-grid .day{padding:8px 0;border-radius:50%;cursor:pointer;font-size:13px}
    .rb-cal-grid .day:hover{background:#f0f7e8}
    .rb-cal-grid .day.selected{background:var(--green);color:#111;font-weight:700}
    .rb-cal-grid .day.past{color:#ccc;cursor:default;pointer-events:none}
    .rb-cal-grid .day.empty{cursor:default}
    .rb-slot-list{display:flex;flex-direction:column;gap:6px;max-height:420px;overflow-y:auto}
    .rb-slot{padding:9px 12px;border:1px solid var(--border);border-radius:6px;font-size:13px;cursor:pointer;display:flex;justify-content:space-between}
    .rb-slot:hover{border-color:var(--green)}
    .rb-slot.selected{background:var(--green);color:#111;border-color:var(--green);font-weight:700}
    .rb-slot.booked{color:#aaa;background:#f7f7f7;cursor:default;text-decoration:line-through}
    .rb-book-form{margin-top:16px;border-top:1px solid var(--border);padding-top:16px}
    .btn-add{padding:9px 20px;background:var(--green);color:#111;font-weight:800;font-size:13px;border:0;border-radius:6px;cursor:pointer}
    .btn-add:hover{background:var(--green-d,#5b8e0d);color:#fff}
    .bk-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-top:24px}
    .bk-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);padding:10px 14px;text-align:left;border-bottom:1px solid var(--border)}
    .bk-table td{padding:9px 14px;border-top:1px solid var(--border);vertical-align:middle}
    .btn-sm{padding:5px 12px;font-size:12px;font-weight:700;border-radius:5px;border:1px solid var(--border);background:#fff;cursor:pointer}
    .btn-sm:hover{background:#f5f5f5}
  </style>
</head>
<body>
  <div class="app-shell">
    <?php render_sidebar('room_booking', $agent); ?>
    <main class="main-content">
      <h1>Book a Conference Room</h1>

      <?php if ($isAdmin || $isLeaderUser): ?>
      <div class="rb-field" style="max-width:340px">
        <label class="rb-label">Market Center</label>
        <select class="rb-select" onchange="location.href='room_booking.php?mc='+encodeURIComponent(this.value)">
          <?php foreach ($allMcs as $mc): ?>
            <option value="<?= h($mc['slug']) ?>" <?= $mc['slug'] === $viewMcSlug ? 'selected' : '' ?>>
              <?= h($mc['state_code'] . ' - ' . $mc['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if (!$rooms): ?>
        <p>No conference room has been set up for this market center yet. Contact an admin.</p>
      <?php else: ?>

      <div class="rb-layout">
        <div class="rb-card rb-picker">
          <?php if (count($rooms) > 1): ?>
          <div class="rb-field">
            <label class="rb-label">Room</label>
            <select id="rb-room" class="rb-select" onchange="rbLoadAvailability()">
              <?php foreach ($rooms as $r): ?>
                <option value="<?= (int)$r['id'] ?>" <?= $preselectRoomId === (int)$r['id'] ? 'selected' : '' ?>><?= h($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php else: ?>
            <input type="hidden" id="rb-room" value="<?= (int)$rooms[0]['id'] ?>">
            <p><strong><?= h($rooms[0]['name']) ?></strong></p>
          <?php endif; ?>

          <div class="rb-cal-head">
            <button onclick="rbShiftMonth(-1)">&lsaquo;</button>
            <strong id="rb-cal-title"></strong>
            <button onclick="rbShiftMonth(1)">&rsaquo;</button>
          </div>
          <div class="rb-cal-grid" id="rb-cal-grid"></div>
        </div>

        <div class="rb-card rb-slots">
          <div class="rb-label" id="rb-slots-title">Select a date</div>
          <div class="rb-slot-list" id="rb-slot-list"></div>

          <div class="rb-book-form" id="rb-book-form" style="display:none">
            <div class="rb-field">
              <label class="rb-label">Duration</label>
              <select id="rb-duration" class="rb-select"></select>
            </div>
            <div class="rb-field">
              <label class="rb-label">Purpose (optional)</label>
              <input id="rb-purpose" class="rb-input" type="text" placeholder="e.g. Buyer consultation">
            </div>
            <button class="btn-add" onclick="rbBook()">Book Room</button>
          </div>
        </div>
      </div>

      <table class="bk-table">
        <thead>
          <tr>
            <th>Date</th><th>Time</th><th>Room</th>
            <?php if ($canSeeAll): ?><th>Agent</th><?php endif; ?>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($upcoming as $b): ?>
          <tr>
            <td><?= h((new DateTime($b['booking_date']))->format('M j, Y')) ?></td>
            <td><?= h(DateTime::createFromFormat('H:i', $b['start_time'])->format('g:i A')) ?> - <?= h(DateTime::createFromFormat('H:i', $b['end_time'])->format('g:i A')) ?></td>
            <td><?= h($b['room_name']) ?></td>
            <?php if ($canSeeAll): ?><td><?= h($b['agent_name'] ?: $b['agent_email']) ?></td><?php endif; ?>
            <td><button class="btn-sm" onclick="rbCancel(<?= (int)$b['id'] ?>)">Cancel</button></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$upcoming): ?>
          <tr><td colspan="<?= $canSeeAll ? 5 : 4 ?>">No upcoming bookings.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <?php endif; ?>
    </main>
  </div>

<script>
const RB_TODAY = <?= json_encode($today) ?>;
let rbViewYear, rbViewMonth, rbSelectedDate = null, rbSelectedSlot = null;

function rbPad(n) { return n < 10 ? '0'+n : ''+n; }

function rbInitCalendar() {
  const t = new Date(RB_TODAY + 'T00:00:00');
  rbViewYear = t.getFullYear();
  rbViewMonth = t.getMonth();
  rbRenderCalendar();
}

function rbShiftMonth(delta) {
  rbViewMonth += delta;
  if (rbViewMonth < 0) { rbViewMonth = 11; rbViewYear--; }
  if (rbViewMonth > 11) { rbViewMonth = 0; rbViewYear++; }
  rbRenderCalendar();
}

function rbRenderCalendar() {
  const first = new Date(rbViewYear, rbViewMonth, 1);
  const startDow = first.getDay();
  const daysInMonth = new Date(rbViewYear, rbViewMonth + 1, 0).getDate();
  const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  document.getElementById('rb-cal-title').textContent = monthNames[rbViewMonth] + ' ' + rbViewYear;

  let html = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(d => `<div class="dow">${d}</div>`).join('');
  for (let i = 0; i < startDow; i++) html += '<div class="day empty"></div>';
  for (let day = 1; day <= daysInMonth; day++) {
    const dateStr = rbViewYear + '-' + rbPad(rbViewMonth+1) + '-' + rbPad(day);
    const isPast = dateStr < RB_TODAY;
    const isSelected = dateStr === rbSelectedDate;
    html += `<div class="day ${isPast?'past':''} ${isSelected?'selected':''}" onclick="rbSelectDate('${dateStr}')">${day}</div>`;
  }
  document.getElementById('rb-cal-grid').innerHTML = html;
}

function rbSelectDate(dateStr) {
  rbSelectedDate = dateStr;
  rbSelectedSlot = null;
  rbRenderCalendar();
  rbLoadAvailability();
}

async function rbLoadAvailability() {
  if (!rbSelectedDate) return;
  const roomId = document.getElementById('rb-room').value;
  document.getElementById('rb-slots-title').textContent = 'Loading...';
  const res = await fetch('api/room_availability.php?room_id=' + encodeURIComponent(roomId) + '&date=' + encodeURIComponent(rbSelectedDate));
  const data = await res.json();
  if (!data.ok) { document.getElementById('rb-slots-title').textContent = data.error || 'Failed to load'; return; }

  document.getElementById('rb-slots-title').textContent = 'Available times for ' + rbSelectedDate;
  const list = document.getElementById('rb-slot-list');
  list.innerHTML = '';
  data.slots.forEach(slot => {
    const div = document.createElement('div');
    div.className = 'rb-slot' + (slot.available ? '' : ' booked');
    div.textContent = slot.start + (slot.available ? '' : ' (Reserved)');
    if (slot.available) {
      div.onclick = () => rbSelectSlot(slot, data.slots);
    }
    list.appendChild(div);
  });
  document.getElementById('rb-book-form').style.display = 'none';
}

function rbSelectSlot(slot, allSlots) {
  rbSelectedSlot = slot;
  document.querySelectorAll('#rb-slot-list .rb-slot').forEach(el => el.classList.remove('selected'));
  event.target.classList.add('selected');

  // Build duration options (15-120 min, 15-min steps) that don't run past
  // close or into the next booked slot -- server re-validates regardless.
  const durSelect = document.getElementById('rb-duration');
  durSelect.innerHTML = '';
  const [sh, sm] = slot.start.split(':').map(Number);
  const startMin = sh * 60 + sm;
  for (let dur = 15; dur <= 120; dur += 15) {
    const endMin = startMin + dur;
    const endStr = rbPad(Math.floor(endMin/60)) + ':' + rbPad(endMin % 60);
    if (endStr > '17:00') break;
    const blocked = allSlots.some(s => !s.available && s.start < endStr && s.end > slot.start);
    if (blocked) break;
    const opt = document.createElement('option');
    opt.value = endStr;
    opt.textContent = dur + ' min';
    durSelect.appendChild(opt);
  }
  document.getElementById('rb-book-form').style.display = 'block';
}

async function rbBook() {
  const roomId = document.getElementById('rb-room').value;
  const endTime = document.getElementById('rb-duration').value;
  const purpose = document.getElementById('rb-purpose').value.trim();
  if (!rbSelectedSlot || !endTime) { alert('Pick a start time and duration.'); return; }

  const res = await fetch('api/room_booking_action.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      action: 'create', room_id: roomId, booking_date: rbSelectedDate,
      start_time: rbSelectedSlot.start, end_time: endTime, purpose,
    }),
  });
  const data = await res.json();
  if (!data.ok) { alert(data.error || 'Failed to book'); return; }
  location.reload();
}

async function rbCancel(bookingId) {
  if (!confirm('Cancel this booking?')) return;
  const res = await fetch('api/room_booking_action.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action: 'cancel', booking_id: bookingId}),
  });
  const data = await res.json();
  if (!data.ok) { alert(data.error || 'Failed to cancel'); return; }
  location.reload();
}

rbInitCalendar();
</script>
</body>
</html>
