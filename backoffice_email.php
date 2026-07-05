<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
if (!can_send_company_email()) { header('Location: index.php'); exit; }

$myMcSlugs = my_mc_slugs();
$isMcOnly  = is_mc_leader() && !is_bic() && !is_admin();
$isBicOnly = is_bic() && !is_admin();

// Admin can target any enabled Market Center; mc_leader/bic only their own.
$mcOptsAll = local_db()
    ->query("SELECT slug, name, state_code FROM market_centers WHERE enabled=1 ORDER BY state_code, sort_ord, name")
    ->fetchAll(PDO::FETCH_ASSOC);

// Full slug → name map (including disabled MCs) so history rows for old sends still label correctly.
$mcNameMap = [];
foreach (local_db()->query("SELECT slug, name FROM market_centers")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $mcNameMap[$r['slug']] = $r['name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Company Email — AgentEdge</title>
<link rel="stylesheet" href="assets/app.css">
<style>
.bo-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
.email-form{background:#f9fdf5;border:1px solid #d4edab;border-radius:10px;padding:20px 24px;margin-bottom:24px}
.email-form h3{margin:0 0 16px;font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#5b8e0d}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:12px}
.field-full{margin-bottom:12px}
.field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px}
.field input,.field select,.field textarea{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:6px;
      font-size:13px;box-sizing:border-box;font-family:inherit}
.field textarea{min-height:160px;resize:vertical;line-height:1.5}
.field input:focus,.field select:focus,.field textarea:focus{outline:2px solid #82C112;border-color:#82C112}
.reach-note{font-size:12px;color:var(--faint);margin:-4px 0 14px}
.form-actions{display:flex;align-items:center;gap:14px}
.btn-primary{padding:9px 20px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
.btn-primary:hover{background:#5b8e0d;color:#fff}
.btn-primary:disabled{opacity:.5;cursor:default}
.send-status{font-size:12px;font-weight:700}
.send-status.ok{color:#2e7d32}
.send-status.err{color:#c0392b}

.email-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden}
.email-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--faint);
      padding:8px 16px;text-align:left;white-space:nowrap;border-bottom:1px solid var(--border)}
.email-table td{padding:9px 16px;border-top:1px solid var(--border);vertical-align:middle}
.email-table tr:first-child td{border-top:none}
.aud-chip{font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;white-space:nowrap}
.aud-chip.all{background:#eef5e8;color:#5b8e0d}
.aud-chip.admin{background:#fff4e0;color:#a07221}
.aud-chip.mc{background:#e8f0fe;color:#1a56c4}
.empty-note{color:var(--faint);font-style:italic;text-align:center;padding:20px}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('bo_company_email', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="bo-eyebrow">Back Office</div>
      <div class="content-title">Company Email</div>
    </div>
  </div>
  <div class="wrap">

    <div class="email-form">
      <h3>Compose</h3>

      <div class="field-row">
        <div class="field">
          <label>Audience</label>
          <?php if ($isBicOnly || $isMcOnly): ?>
            <?php if (count($myMcSlugs) > 1): ?>
              <select id="em-mc-slug">
                <?php foreach ($myMcSlugs as $slug): ?>
                <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($mcNameMap[$slug] ?? $slug) ?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input type="text" value="<?= htmlspecialchars($mcNameMap[$myMcSlugs[0] ?? ''] ?? 'My Market Center') ?>" disabled style="background:#f5f5f5;color:#888">
              <input type="hidden" id="em-mc-slug" value="<?= htmlspecialchars($myMcSlugs[0] ?? '') ?>">
            <?php endif; ?>
            <input type="hidden" id="em-audience" value="mc">
          <?php else: ?>
            <select id="em-audience" onchange="onAudienceChange()">
              <option value="all">Entire Company</option>
              <option value="admin">Admin &amp; Staff Only</option>
              <option value="mc">Specific Market Center</option>
            </select>
          <?php endif; ?>
        </div>
        <div class="field" id="mc-target-row" style="display:none">
          <label>Market Center</label>
          <select id="em-mc-slug-admin">
            <?php foreach ($mcOptsAll as $opt): ?>
            <option value="<?= htmlspecialchars($opt['slug']) ?>">
              <?= htmlspecialchars(($opt['state_code'] ? $opt['state_code'] . ' - ' : '') . $opt['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <p class="reach-note" id="reach-note">
        <?= $isBicOnly ? 'Sends to every agent in the Market Center(s) you\'re BIC over.'
           : ($isMcOnly ? 'Sends to every agent in the Market Center(s) you lead.'
           : 'Sends to every agent in the company, pulled from the live agent roster.') ?>
      </p>

      <div class="field-full field">
        <label>Subject</label>
        <input type="text" id="em-subject" maxlength="150" placeholder="Subject line">
      </div>

      <div class="field-full field">
        <label>Message</label>
        <textarea id="em-body" placeholder="Write your message…"></textarea>
      </div>

      <div class="form-actions">
        <button class="btn-primary" id="btn-send" onclick="sendEmail()">Send</button>
        <span class="send-status" id="send-status"></span>
      </div>
    </div>

    <table class="email-table">
      <thead>
        <tr>
          <th>Sent</th>
          <th>Subject</th>
          <th>Audience</th>
          <th>Recipients</th>
          <th>Sent By</th>
        </tr>
      </thead>
      <tbody id="email-tbody"><tr><td colspan="5" class="empty-note">Loading…</td></tr></tbody>
    </table>

  </div>
</div>
</div>
<script>
const IS_ADMIN     = <?= is_admin() ? 'true' : 'false' ?>;
const MC_NAME_MAP  = <?= json_encode($mcNameMap) ?>;

function onAudienceChange() {
  const val = document.getElementById('em-audience').value;
  document.getElementById('mc-target-row').style.display = (val === 'mc') ? '' : 'none';
  const note = document.getElementById('reach-note');
  note.textContent = val === 'all'   ? 'Sends to every agent in the company, pulled from the live agent roster.'
                    : val === 'admin' ? 'Sends only to Super Admin and Staff accounts.'
                    : 'Sends to every agent in the selected Market Center.';
}

function audLabel(audience, mcSlug) {
  if (audience === 'all')   return '<span class="aud-chip all">Entire Company</span>';
  if (audience === 'admin') return '<span class="aud-chip admin">Admin &amp; Staff</span>';
  const name = MC_NAME_MAP[mcSlug] || mcSlug || '—';
  return '<span class="aud-chip mc">' + escapeHtml(name) + '</span>';
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
}

function fmtDt(dt) {
  const ts = Date.parse(dt.replace(' ', 'T') + 'Z');
  if (!ts) return dt;
  return new Date(ts).toLocaleString('en-US', {month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit'});
}

function loadHistory() {
  fetch('api/company_email_action.php', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'history'})
  })
  .then(r => r.json())
  .then(d => {
    const tbody = document.getElementById('email-tbody');
    if (!d.ok || !d.rows.length) { tbody.innerHTML = '<tr><td colspan="5" class="empty-note">No emails sent yet.</td></tr>'; return; }
    tbody.innerHTML = d.rows.map(r => `
      <tr>
        <td>${fmtDt(r.sent_at)}</td>
        <td>${escapeHtml(r.subject)}</td>
        <td>${audLabel(r.audience, r.target_mc_slug)}</td>
        <td>${r.recipient_count}</td>
        <td>${escapeHtml(r.sender_email)}</td>
      </tr>`).join('');
  })
  .catch(() => { document.getElementById('email-tbody').innerHTML = '<tr><td colspan="5" class="empty-note">Failed to load.</td></tr>'; });
}

function sendEmail() {
  const audienceEl = document.getElementById('em-audience');
  const audience   = audienceEl.value;
  const mcSlug     = audience === 'mc'
    ? (document.getElementById('em-mc-slug-admin') && document.getElementById('mc-target-row').style.display !== 'none'
        ? document.getElementById('em-mc-slug-admin').value
        : (document.getElementById('em-mc-slug') ? document.getElementById('em-mc-slug').value : ''))
    : '';
  const subject = document.getElementById('em-subject').value.trim();
  const text    = document.getElementById('em-body').value.trim();
  const status  = document.getElementById('send-status');
  const btn     = document.getElementById('btn-send');

  if (!subject || !text) { status.textContent = 'Subject and message are required.'; status.className = 'send-status err'; return; }
  if (audience === 'mc' && !mcSlug) { status.textContent = 'Pick a Market Center.'; status.className = 'send-status err'; return; }
  if (!confirm('Send this email now? This cannot be undone.')) return;

  btn.disabled = true; btn.textContent = 'Sending…';
  status.textContent = ''; status.className = 'send-status';

  fetch('api/company_email_action.php', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'send', audience, target_mc_slug:mcSlug, subject, body:text})
  })
  .then(r => r.json())
  .then(d => {
    btn.disabled = false; btn.textContent = 'Send';
    if (!d.ok) { status.textContent = 'Error: ' + (d.error || 'Unknown'); status.className = 'send-status err'; return; }
    status.textContent = `Sent to ${d.recipients} recipient${d.recipients !== 1 ? 's' : ''}.`;
    status.className = 'send-status ok';
    document.getElementById('em-subject').value = '';
    document.getElementById('em-body').value = '';
    loadHistory();
  })
  .catch(() => { btn.disabled = false; btn.textContent = 'Send'; status.textContent = 'Network error.'; status.className = 'send-status err'; });
}

if (document.getElementById('em-audience') && document.getElementById('em-audience').tagName === 'SELECT') onAudienceChange();
loadHistory();
</script>
</body>
</html>
