<?php
// Public event registration — no login required. Anyone with the link (e.g.
// from an announcement email) can register with just a name + email, same
// as clicking Register while signed into the dashboard. Writes into the same
// training_rsvps/events_rsvps tables api/public_rsvp.php uses.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/lib/google_calendar.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

// Google Calendar auto-fills the description with a big invite dump (join
// link, meeting ID, passcode, dial-in numbers, SIP info) whenever an event
// is scheduled as a Zoom meeting — the join link already shows via the
// location line, so drop this boilerplate rather than showing the raw
// dial-in text on the registration page. Same helper as calendar.js's
// training_cal.php/events_cal.php feeds.
function strip_zoom_invite_boilerplate(string $desc): string {
    if (preg_match('/is inviting you to a scheduled Zoom meeting/i', $desc)) return '';
    return $desc;
}

// Gmail/Google Calendar sometimes wrap a pasted link in a google.com/url
// click-tracking redirect instead of storing the plain URL. Unwrap it back
// to the real link, preferring a clean Zoom join URL if one is embedded.
function unwrap_google_redirect(string $text): string {
    return preg_replace_callback('#https?://(?:www\.)?google\.com/url\?q=(\S+)#i', function ($m) {
        $decoded = $m[1];
        for ($i = 0; $i < 3; $i++) {
            $next = urldecode($decoded);
            if ($next === $decoded) break;
            $decoded = $next;
        }
        if (preg_match('#https?://[a-z0-9.-]*zoom\.us/j/\d+(?:\?pwd=[A-Za-z0-9._-]+)?#i', $decoded, $zm)) {
            return $zm[0];
        }
        return preg_match('#^https?://\S+#i', $decoded, $um) ? $um[0] : $m[0];
    }, $text);
}

$scope    = ($_GET['scope'] ?? '') === 'events' ? 'events' : 'training';
$event_id = trim($_GET['event'] ?? '');
$capTable = $scope === 'events' ? 'events_calendar' : 'training_events';

$c        = cfg();
$key_file = $c['gcal_key_file'] ?? (__DIR__ . '/agentedge-calendar-key.json');
$cal_id   = $scope === 'events' ? ($c['gcal_events_calendar_id'] ?? '') : ($c['gcal_calendar_id'] ?? 'training@innovateonline.com');

$event = null;
if ($event_id !== '' && $cal_id !== '') {
    $token = gcal_access_token($key_file);
    if ($token) $event = gcal_get_event($cal_id, $token, $event_id);
}

$title      = $event['summary'] ?? '';
$is_all_day = isset($event['start']['date']);
$start_raw  = $event['start']['date'] ?? ($event['start']['dateTime'] ?? '');
$end_raw    = $event['end']['date']   ?? ($event['end']['dateTime']   ?? '');
$location   = trim($event['location'] ?? '');

// Prefer an admin's custom registration-page copy over the raw Calendar
// description (which is often cluttered with Zoom dial-in boilerplate).
$customDesc = '';
if ($event_id !== '') {
    $stmt = local_db()->prepare("SELECT reg_description FROM {$capTable} WHERE event_id=?");
    $stmt->execute([$event_id]);
    $customDesc = trim((string)$stmt->fetchColumn());
}
$rawDescription = isset($event['description'])
    ? unwrap_google_redirect(strip_zoom_invite_boilerplate(strip_tags($event['description'])))
    : '';
if ($location !== '' && str_ends_with(trim($rawDescription), $location)) {
    $rawDescription = trim(substr($rawDescription, 0, strrpos($rawDescription, $location)));
}
$description = $customDesc !== '' ? $customDesc : $rawDescription;

function fmt_event_when(string $start, string $end, bool $allDay): string {
    if ($start === '') return '';
    if ($allDay) {
        $ts = strtotime($start);
        return $ts ? date('l, F j, Y', $ts) : $start;
    }
    $sTs = strtotime($start);
    if (!$sTs) return $start;
    $out = date('l, F j, Y', $sTs) . ' at ' . date('g:i A', $sTs);
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
    body{background:var(--bg)}
    .reg-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:32px 20px}
    .reg-card{background:#fff;border:1px solid var(--border);border-radius:18px;box-shadow:0 8px 32px rgba(0,0,0,.06);width:min(520px,100%);overflow:hidden}
    .reg-brand{padding:22px 32px 0}
    .reg-brand img{height:28px}
    .reg-hero{padding:20px 32px 24px;border-bottom:1px solid var(--border)}
    .reg-title{font-size:22px;font-weight:800;color:var(--ink);margin:0 0 12px}
    .reg-chip-row{display:flex;flex-direction:column;gap:8px}
    .reg-chip{display:flex;align-items:flex-start;gap:10px;font-size:14px;color:var(--muted)}
    .reg-chip-icon{flex:none;font-size:15px;line-height:1.4}
    .reg-when{font-weight:700;color:var(--green-d)}
    .reg-desc{padding:20px 32px;font-size:13.5px;line-height:1.6;color:var(--muted);white-space:pre-wrap;max-height:220px;overflow-y:auto;border-bottom:1px solid var(--border)}
    .reg-body{padding:24px 32px 28px}
    .reg-body-label{font-size:13px;font-weight:800;color:var(--ink);margin-bottom:14px}
    .reg-form{display:flex;flex-direction:column;gap:12px}
    .reg-form input{padding:12px 14px;border:1px solid var(--border);border-radius:9px;font-size:15px;background:#fafafa}
    .reg-form input:focus{outline:none;border-color:var(--green);background:#fff}
    .reg-form button{padding:13px;border:0;border-radius:9px;background:var(--green);color:#111;font-weight:800;font-size:14.5px;cursor:pointer;transition:background .15s}
    .reg-form button:hover{background:var(--green-d);color:#fff}
    .reg-form button:disabled{opacity:.6;cursor:default}
    .reg-err{padding:10px 14px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:8px;color:#c00;font-size:13px}
    .reg-confirm{text-align:center;display:flex;flex-direction:column;align-items:center;gap:12px}
    .reg-confirm-icon{width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:24px}
    .reg-confirm-icon.ok{background:#eef5e8;color:var(--green-d)}
    .reg-confirm-icon.wait{background:#fff4e0;color:#a06000}
    .reg-confirm-badge{font-size:16px;font-weight:800;color:var(--ink)}
    .reg-confirm-sub{font-size:13px;color:var(--faint)}
    .reg-cancel-btn{padding:10px 16px;border:1px solid var(--border);border-radius:8px;background:#fff;color:#c00;font-weight:700;font-size:13px;cursor:pointer;margin-top:4px}
    .reg-cancel-btn:hover{background:#fff0f0;border-color:#f5c6c6}
    .reg-notfound{text-align:center;color:var(--faint);font-size:14px;padding:48px 32px}
  </style>
</head>
<body>
  <div class="reg-wrap">
    <div class="reg-card">
      <div class="reg-brand"><img src="assets/logo.png" alt="INNOVATE Real Estate"></div>

      <?php if (!$event): ?>
        <div class="reg-notfound">This registration link isn't valid, or the event has been removed.</div>
      <?php else: ?>
        <div class="reg-hero">
          <h1 class="reg-title"><?= h($title ?: 'Event') ?></h1>
          <div class="reg-chip-row">
            <?php if ($when !== ''): ?>
              <div class="reg-chip"><span class="reg-chip-icon">&#128197;</span><span class="reg-when"><?= h($when) ?></span></div>
            <?php endif; ?>
            <?php if ($location !== ''): ?>
              <div class="reg-chip"><span class="reg-chip-icon">&#128205;</span><span><?= h($location) ?></span></div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($description !== ''): ?>
          <div class="reg-desc"><?= h($description) ?></div>
        <?php endif; ?>

        <div class="reg-body">
          <div id="reg-body">
            <div class="reg-body-label">Reserve your spot</div>
            <div id="reg-err" class="reg-err" style="display:none;margin-bottom:12px"></div>
            <form class="reg-form" id="reg-form">
              <input type="text" id="reg-name" placeholder="Full name" required autocomplete="name">
              <input type="email" id="reg-email" placeholder="Email address" required autocomplete="email">
              <button type="submit" id="reg-submit">Register</button>
            </form>
          </div>
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
      <div class="reg-confirm-icon ${waitlisted ? 'wait' : 'ok'}">${waitlisted ? '&#9203;' : '&#10003;'}</div>
      <div class="reg-confirm-badge">${waitlisted ? "You're on the waitlist" : "You're registered!"}</div>
      <div class="reg-confirm-sub">${regEsc(name)} &middot; ${regEsc(email)}</div>
      <button class="reg-cancel-btn" id="reg-cancel-btn">Cancel my registration</button>
    </div>`;
  document.getElementById('reg-cancel-btn').addEventListener('click', () => {
    if (!confirm('Cancel your registration for this event?')) return;
    regPost('cancel', { email }).then(() => regShowForm());
  });
}

function regShowForm() {
  document.getElementById('reg-body').innerHTML = `
    <div class="reg-body-label">Reserve your spot</div>
    <div id="reg-err" class="reg-err" style="display:none;margin-bottom:12px"></div>
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
