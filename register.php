<?php
// Public event registration — no login required. Anyone with the link (e.g.
// from an announcement email) can register with just a name + email, same
// as clicking Register while signed into the dashboard. Writes into the same
// training_rsvps/events_rsvps tables api/public_rsvp.php uses.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/google_calendar.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

$scope    = ($_GET['scope'] ?? '') === 'events' ? 'events' : 'training';
$event_id = trim($_GET['event'] ?? '');

$c        = cfg();
$key_file = $c['gcal_key_file'] ?? (__DIR__ . '/agentedge-calendar-key.json');
$cal_id   = $scope === 'events' ? ($c['gcal_events_calendar_id'] ?? '') : ($c['gcal_calendar_id'] ?? 'training@innovateonline.com');

$event = null;
if ($event_id !== '' && $cal_id !== '') {
    $token = gcal_access_token($key_file);
    if ($token) $event = gcal_get_event($cal_id, $token, $event_id);
}

$title       = $event['summary'] ?? '';
$is_all_day  = isset($event['start']['date']);
$start_raw   = $event['start']['date'] ?? ($event['start']['dateTime'] ?? '');
$end_raw     = $event['end']['date']   ?? ($event['end']['dateTime']   ?? '');
$location    = trim($event['location'] ?? '');
$description = trim(strip_tags($event['description'] ?? ''));

function fmt_event_when(string $start, string $end, bool $allDay): string {
    if ($start === '') return '';
    if ($allDay) {
        $ts = strtotime($start);
        return $ts ? date('l, F j, Y', $ts) : $start;
    }
    $sTs = strtotime($start);
    if (!$sTs) return $start;
    $out = date('l, F j, Y \a\t g:i A', $sTs);
    $eTs = strtotime($end);
    if ($eTs) $out .= ' – ' . date('g:i A', $eTs);
    return $out;
}
$when = fmt_event_when($start_raw, $end_raw, $is_all_day);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $title !== '' ? h($title) . ' — ' : '' ?>Register — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .reg-card{background:#fff;border:1px solid var(--border);border-radius:16px;padding:28px;width:min(480px,100%);display:flex;flex-direction:column;gap:14px}
    .reg-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:var(--bg)}
    .reg-title{font-size:20px;font-weight:800;color:var(--ink);margin:0}
    .reg-when{font-size:14px;font-weight:700;color:var(--green-d)}
    .reg-meta{font-size:13px;color:var(--faint);display:flex;gap:6px;align-items:flex-start}
    .reg-desc{font-size:13px;color:var(--muted);white-space:pre-wrap;border-top:1px solid var(--border);padding-top:12px;margin-top:2px}
    .reg-form{display:flex;flex-direction:column;gap:10px;margin-top:6px}
    .reg-form input{padding:11px 12px;border:1px solid var(--border);border-radius:8px;font-size:15px;background:#fafafa}
    .reg-form button{padding:11px;border:0;border-radius:8px;background:var(--green);color:#111;font-weight:800;font-size:14px;cursor:pointer}
    .reg-form button:hover{background:var(--green-d);color:#fff}
    .reg-form button:disabled{opacity:.6;cursor:default}
    .reg-err{padding:10px 14px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:6px;color:#c00;font-size:13px}
    .reg-confirm{text-align:center;padding:10px 0;display:flex;flex-direction:column;gap:10px}
    .reg-confirm-badge{font-size:15px;font-weight:800}
    .reg-confirm-badge.ok{color:var(--green-d)}
    .reg-confirm-badge.wait{color:#a06000}
    .reg-cancel-btn{padding:9px;border:1px solid var(--border);border-radius:8px;background:#fff;color:#c00;font-weight:700;font-size:13px;cursor:pointer}
    .reg-cancel-btn:hover{background:#fff0f0;border-color:#f5c6c6}
    .reg-notfound{text-align:center;color:var(--faint);font-size:14px;padding:20px 0}
  </style>
</head>
<body>
  <div class="reg-wrap">
    <div class="reg-card">
      <a href="index.php" class="login-logo"><img src="assets/logo.png" alt="INNOVATE Real Estate"></a>

      <?php if (!$event): ?>
        <div class="reg-notfound">This registration link isn't valid, or the event has been removed.</div>
      <?php else: ?>
        <h1 class="reg-title"><?= h($title ?: 'Event') ?></h1>
        <?php if ($when !== ''): ?><div class="reg-when">&#128197; <?= h($when) ?></div><?php endif; ?>
        <?php if ($location !== ''): ?><div class="reg-meta">&#128205; <span><?= h($location) ?></span></div><?php endif; ?>
        <?php if ($description !== ''): ?><div class="reg-desc"><?= h($description) ?></div><?php endif; ?>

        <div id="reg-body">
          <div id="reg-err" class="reg-err" style="display:none"></div>
          <form class="reg-form" id="reg-form">
            <input type="text" id="reg-name" placeholder="Full name" required autocomplete="name">
            <input type="email" id="reg-email" placeholder="Email address" required autocomplete="email">
            <button type="submit" id="reg-submit">Register</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>

<?php if ($event): ?>
<script>
const REG_EVENT_ID    = <?= json_encode($event_id) ?>;
const REG_SCOPE       = <?= json_encode($scope) ?>;
const REG_EVENT_TITLE = <?= json_encode($title) ?>;
const REG_EVENT_DATE  = <?= json_encode(substr($start_raw, 0, 10)) ?>;

function regEsc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function regPost(action, extra) {
  return fetch('api/public_rsvp.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(Object.assign({ action, event_id: REG_EVENT_ID, scope: REG_SCOPE }, extra)),
  }).then(r => r.json());
}

function regShowConfirm(email, name, waitlisted) {
  const body = document.getElementById('reg-body');
  body.innerHTML = `
    <div class="reg-confirm">
      <div class="reg-confirm-badge ${waitlisted ? 'wait' : 'ok'}">
        ${waitlisted ? "You're on the waitlist" : "You're registered!"}
      </div>
      <div style="font-size:13px;color:var(--faint)">${regEsc(name)} &middot; ${regEsc(email)}</div>
      <button class="reg-cancel-btn" id="reg-cancel-btn">Cancel my registration</button>
    </div>`;
  document.getElementById('reg-cancel-btn').addEventListener('click', () => {
    if (!confirm('Cancel your registration for this event?')) return;
    regPost('cancel', { email }).then(() => regShowForm());
  });
}

function regShowForm() {
  document.getElementById('reg-body').innerHTML = `
    <div id="reg-err" class="reg-err" style="display:none"></div>
    <form class="reg-form" id="reg-form">
      <input type="text" id="reg-name" placeholder="Full name" required autocomplete="name">
      <input type="email" id="reg-email" placeholder="Email address" required autocomplete="email">
      <button type="submit" id="reg-submit">Register</button>
    </form>`;
  regWireForm();
}

function regWireForm() {
  document.getElementById('reg-form').addEventListener('submit', e => {
    e.preventDefault();
    const name  = document.getElementById('reg-name').value.trim();
    const email = document.getElementById('reg-email').value.trim();
    const errBox = document.getElementById('reg-err');
    const btn = document.getElementById('reg-submit');
    errBox.style.display = 'none';
    btn.disabled = true; btn.textContent = 'Registering…';
    regPost('register', { name, email, event_title: REG_EVENT_TITLE, event_date: REG_EVENT_DATE })
      .then(d => {
        if (!d.ok) {
          errBox.textContent = d.error || 'Something went wrong. Please try again.';
          errBox.style.display = 'block';
          btn.disabled = false; btn.textContent = 'Register';
          return;
        }
        regShowConfirm(email, name, d.waitlisted);
      })
      .catch(() => {
        errBox.textContent = 'Network error — please try again.';
        errBox.style.display = 'block';
        btn.disabled = false; btn.textContent = 'Register';
      });
  });
}
regWireForm();
</script>
<?php endif; ?>
</body>
</html>
