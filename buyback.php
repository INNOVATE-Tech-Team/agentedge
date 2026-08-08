<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
if (!can_use_buyback()) { header('Location: index.php'); exit; }
$showAdminTab = is_admin() || is_bic();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Buy Back Your Time — AgentEdge</title>
<link rel="stylesheet" href="assets/app.css">
<style>
.bo-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
.bb-tabs{display:flex;gap:6px;margin-bottom:20px;border-bottom:1px solid var(--border);flex-wrap:wrap}
.bb-tab{padding:10px 18px;font-size:13px;font-weight:700;color:#888;cursor:pointer;border-bottom:2px solid transparent}
.bb-tab.active{color:#5b8e0d;border-bottom-color:#82C112}
.bb-panel{display:none}
.bb-panel.active{display:block}
.bb-form{background:#f9fdf5;border:1px solid #d4edab;border-radius:10px;padding:20px 24px;margin-bottom:24px}
.bb-form h3{margin:0 0 12px;font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#5b8e0d}
.bb-form p.hint{font-size:12px;color:#888;margin:-4px 0 14px}
.field{margin-bottom:12px}
.field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px}
.field input,.field select,.field textarea{width:100%;padding:9px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;box-sizing:border-box;font-family:inherit}
.field input:focus,.field select:focus,.field textarea:focus{outline:2px solid #82C112;border-color:#82C112}
.btn-primary{padding:9px 20px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
.btn-primary:hover{background:#5b8e0d;color:#fff}
.btn-primary:disabled{opacity:.5;cursor:default}
.btn-secondary{padding:7px 16px;background:#fff;color:#333;border:1px solid #ccc;border-radius:6px;font-weight:700;font-size:12px;cursor:pointer}
.btn-secondary:hover{background:#f5f5f5;border-color:#aaa}
.btn-danger{padding:7px 16px;background:#fff;color:#c0392b;border:1px solid #f0c4bc;border-radius:6px;font-weight:700;font-size:12px;cursor:pointer}
.btn-danger:hover{background:#fdf0ed}
.send-status{font-size:12px;font-weight:700}
.send-status.ok{color:#2e7d32}
.send-status.err{color:#c0392b}
.empty-note{color:var(--faint);font-style:italic;text-align:center;padding:20px}
.packet-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px 24px;margin-top:20px}
.packet-card h4{margin:0 0 8px;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#333}
.packet-card p{font-size:13px;line-height:1.6;color:#333;margin:0 0 16px}
.obj-row{border-top:1px solid #eee;padding:10px 0}
.obj-row:first-child{border-top:none}
.obj-row .obj{font-weight:700;font-size:13px;color:#333}
.obj-row .resp{font-size:13px;color:#555;margin-top:4px}
.subject-note{font-size:12px;color:#888;margin-bottom:14px}
.draft-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:16px 20px;margin-bottom:14px}
.draft-top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
.draft-target{font-weight:700;font-size:13px;color:#111}
.draft-meta{font-size:11px;color:#888;margin-top:2px}
.badge{font-size:10px;font-weight:700;padding:3px 9px;border-radius:12px;white-space:nowrap}
.badge.draft{background:#fef6e0;color:#a86c00}
.badge.sent{background:#eef5e8;color:#5b8e0d}
.badge.failed{background:#fde8e0;color:#c46a1a}
.badge.rejected{background:#f3f3f3;color:#888}
.draft-subject{font-weight:700;font-size:13px;margin-top:10px}
.draft-body{font-size:13px;color:#444;white-space:pre-wrap;margin-top:6px;line-height:1.6}
.draft-actions{display:flex;gap:8px;margin-top:12px}
.hist-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden}
.hist-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--faint);padding:8px 16px;text-align:left;white-space:nowrap;border-bottom:1px solid var(--border)}
.hist-table td{padding:9px 16px;border-top:1px solid var(--border);vertical-align:middle}
.hist-table tr:first-child td{border-top:none}
.candidate-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 0;border-top:1px solid #eee}
.candidate-row:first-child{border-top:none}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('buyback', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="bo-eyebrow">My Tools</div>
      <div class="content-title">Buy Back Your Time</div>
    </div>
  </div>
  <div class="wrap">

    <div class="bb-tabs">
      <div class="bb-tab active" data-panel="bb-delegate">Appointment Prep</div>
      <div class="bb-tab" data-panel="bb-automate">Equity Review</div>
      <div class="bb-tab" data-panel="bb-eliminate">Database Audit</div>
      <?php if ($showAdminTab): ?>
      <div class="bb-tab" data-panel="bb-admin">Team Activity</div>
      <?php endif; ?>
    </div>

    <!-- Delegate: on-demand, no approval needed -->
    <div id="bb-delegate" class="bb-panel active">
      <div class="bb-form">
        <h3>Get ready for an appointment</h3>
        <div class="field">
          <label>Property address</label>
          <input type="text" id="bb-address" placeholder="e.g. 123 Ocean Blvd, Myrtle Beach, SC">
        </div>
        <div style="margin-top:14px;display:flex;align-items:center;gap:14px">
          <button class="btn-primary" id="bb-prep-btn" onclick="bbPrep()">Prep this appointment</button>
          <span class="send-status" id="bb-prep-status"></span>
        </div>
      </div>
      <div id="bb-packet"></div>
    </div>

    <!-- Automate: pick a contact, generate a draft, review before send -->
    <div id="bb-automate" class="bb-panel">
      <div class="bb-form">
        <h3>Equity Review</h3>
        <p class="hint">Generates a real comps-based market update email for a past client — nothing sends until you approve it below.</p>
        <div id="bb-candidates"><div class="empty-note">Loading your contacts…</div></div>
      </div>
      <div id="bb-automate-drafts"></div>
    </div>

    <!-- Eliminate: run the audit, review the queue before send -->
    <div id="bb-eliminate" class="bb-panel">
      <div class="bb-form">
        <h3>Database Audit</h3>
        <p class="hint">Finds contacts who've gone quiet (90+ days no activity) and drafts a personal check-in — nothing sends until you approve it below.</p>
        <div style="display:flex;align-items:center;gap:14px">
          <button class="btn-primary" id="bb-eliminate-btn" onclick="bbEliminateRun()">Run my database audit</button>
          <span class="send-status" id="bb-eliminate-status"></span>
        </div>
      </div>
      <div id="bb-eliminate-drafts"></div>
    </div>

    <?php if ($showAdminTab): ?>
    <!-- Admin/BIC oversight: read-only cross-agent view -->
    <div id="bb-admin" class="bb-panel">
      <div id="bb-admin-table"><div class="empty-note">Loading…</div></div>
    </div>
    <?php endif; ?>

  </div>
</div>
</div>

<script>
function bbEscape(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// ── Tabs ─────────────────────────────────────────────────────────────────────
document.querySelectorAll('.bb-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.bb-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.bb-panel').forEach(p => p.classList.remove('active'));
    tab.classList.add('active');
    document.getElementById(tab.dataset.panel).classList.add('active');
    if (tab.dataset.panel === 'bb-automate') { loadCandidates(); loadDrafts('automate', 'bb-automate-drafts'); }
    if (tab.dataset.panel === 'bb-eliminate') { loadDrafts('eliminate', 'bb-eliminate-drafts'); }
    if (tab.dataset.panel === 'bb-admin') { loadAdminTable(); }
  });
});

// ── Delegate ─────────────────────────────────────────────────────────────────
async function bbPrep() {
  const btn = document.getElementById('bb-prep-btn');
  const status = document.getElementById('bb-prep-status');
  const address = document.getElementById('bb-address').value.trim();
  if (!address) { status.textContent = 'Enter an address first.'; status.className = 'send-status err'; return; }

  btn.disabled = true; btn.textContent = 'Preparing…';
  status.textContent = ''; status.className = 'send-status';
  document.getElementById('bb-packet').innerHTML = '';

  try {
    const r = await fetch('api/buyback_prep.php', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({address: address}),
    });
    const data = await r.json();
    btn.disabled = false; btn.textContent = 'Prep this appointment';
    if (!r.ok || data.ok === false) {
      status.textContent = 'Error: ' + (data.error || data.detail || 'Unknown error');
      status.className = 'send-status err';
      return;
    }
    renderPacket(data);
  } catch (e) {
    btn.disabled = false; btn.textContent = 'Prep this appointment';
    status.textContent = 'Network error — could not reach the server.';
    status.className = 'send-status err';
  }
}

function renderPacket(data) {
  const el = document.getElementById('bb-packet');
  const subjectNote = data.subjectFound
    ? `Found this address in current MLS data — comps below are real, matched listings.`
    : `Couldn't match this address to a current MLS listing — comps below reflect general market data where available. Treat the numbers as a starting point, not a match to this specific property.`;
  const objections = (data.objections || []).map(o => `
    <div class="obj-row">
      <div class="obj">${bbEscape(o.objection)}</div>
      <div class="resp">${bbEscape(o.response)}</div>
    </div>`).join('');
  el.innerHTML = `
    <div class="packet-card">
      <div class="subject-note">${subjectNote} ${data.compCount ? `(${data.compCount} comps found)` : ''}</div>
      <h4>Comps Summary</h4>
      <p>${bbEscape(data.compsSummary)}</p>
      <h4>Likely Objections & Responses</h4>
      ${objections || '<p class="empty-note">None generated.</p>'}
      <h4>Pricing Story</h4>
      <p>${bbEscape(data.pricingStory)}</p>
    </div>`;
}

// ── Automate ─────────────────────────────────────────────────────────────────
let candidatesLoaded = false;
async function loadCandidates() {
  if (candidatesLoaded) return;
  candidatesLoaded = true;
  const el = document.getElementById('bb-candidates');
  try {
    const r = await fetch('api/buyback_automate_candidates.php', {credentials: 'same-origin'});
    const data = await r.json();
    if (!r.ok || data.ok === false) {
      el.innerHTML = `<div class="empty-note">${bbEscape(data.error || 'Could not load your contacts.')}</div>`;
      return;
    }
    const candidates = (data.candidates || []).filter(c => c.email);
    if (!candidates.length) {
      el.innerHTML = '<div class="empty-note">No contacts with an email on file.</div>';
      return;
    }
    el.innerHTML = candidates.slice(0, 50).map(c => `
      <div class="candidate-row">
        <div>${bbEscape(c.name)} <span style="color:#999">(${bbEscape(c.email)})</span></div>
        <button class="btn-secondary" onclick="bbAutomateGenerate(${c.id}, this)">Generate review</button>
      </div>`).join('');
  } catch (e) {
    el.innerHTML = '<div class="empty-note">Network error.</div>';
  }
}

async function bbAutomateGenerate(personId, btn) {
  btn.disabled = true; btn.textContent = 'Generating…';
  try {
    const r = await fetch('api/buyback_automate_generate.php', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({person_id: personId}),
    });
    const data = await r.json();
    btn.disabled = false; btn.textContent = 'Generate review';
    if (!r.ok || data.ok === false) { alert('Error: ' + (data.error || data.detail || 'Unknown error')); return; }
    loadDrafts('automate', 'bb-automate-drafts', true);
  } catch (e) {
    btn.disabled = false; btn.textContent = 'Generate review';
    alert('Network error.');
  }
}

// ── Eliminate ────────────────────────────────────────────────────────────────
async function bbEliminateRun() {
  const btn = document.getElementById('bb-eliminate-btn');
  const status = document.getElementById('bb-eliminate-status');
  btn.disabled = true; btn.textContent = 'Running…';
  status.textContent = ''; status.className = 'send-status';
  try {
    const r = await fetch('api/buyback_eliminate_run.php', {method: 'POST', credentials: 'same-origin'});
    const data = await r.json();
    btn.disabled = false; btn.textContent = 'Run my database audit';
    if (!r.ok || data.ok === false) {
      status.textContent = 'Error: ' + (data.error || data.detail || 'Unknown error');
      status.className = 'send-status err';
      return;
    }
    status.textContent = `Generated ${data.created} new draft(s).`;
    status.className = 'send-status ok';
    loadDrafts('eliminate', 'bb-eliminate-drafts', true);
  } catch (e) {
    btn.disabled = false; btn.textContent = 'Run my database audit';
    status.textContent = 'Network error.';
    status.className = 'send-status err';
  }
}

// ── Shared draft queue (Automate + Eliminate) ───────────────────────────────
async function loadDrafts(agentType, containerId, force) {
  const el = document.getElementById(containerId);
  if (!force && el.dataset.loaded === '1') return;
  el.dataset.loaded = '1';
  el.innerHTML = '<div class="empty-note">Loading…</div>';
  try {
    const r = await fetch('api/buyback_drafts.php?agent_type=' + encodeURIComponent(agentType), {credentials: 'same-origin'});
    const data = await r.json();
    if (!r.ok || data.ok === false) {
      el.innerHTML = `<div class="empty-note">${bbEscape(data.error || 'Could not load drafts.')}</div>`;
      return;
    }
    const drafts = data.drafts || [];
    if (!drafts.length) {
      el.innerHTML = '<div class="empty-note">No drafts yet.</div>';
      return;
    }
    el.innerHTML = drafts.map(d => `
      <div class="draft-card" data-id="${bbEscape(d.id)}">
        <div class="draft-top">
          <div>
            <div class="draft-target">${bbEscape(d.targetLabel)}</div>
            <div class="draft-meta">${d.generatedAt ? new Date(d.generatedAt).toLocaleString() : ''}</div>
          </div>
          <span class="badge ${bbEscape(d.status)}">${bbEscape(d.status)}</span>
        </div>
        <div class="draft-subject">${bbEscape(d.subject)}</div>
        <div class="draft-body">${bbEscape(d.bodyText)}</div>
        ${d.status === 'draft' ? `
          <div class="draft-actions">
            <button class="btn-primary" onclick="bbApprove('${d.id}', this)">Approve & send</button>
            <button class="btn-danger" onclick="bbReject('${d.id}', this)">Reject</button>
          </div>` : ''}
        ${d.status === 'failed' && d.sendError ? `<div class="draft-meta" style="color:#c0392b;margin-top:6px">Error: ${bbEscape(d.sendError)}</div>` : ''}
      </div>`).join('');
  } catch (e) {
    el.innerHTML = '<div class="empty-note">Network error.</div>';
  }
}

async function bbApprove(draftId, btn) {
  if (!confirm('Send this email now? This cannot be undone.')) return;
  const card = btn.closest('.draft-card');
  btn.disabled = true; btn.textContent = 'Sending…';
  try {
    const r = await fetch('api/buyback_draft_approve.php?draft_id=' + encodeURIComponent(draftId), {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'}, body: JSON.stringify({}),
    });
    const data = await r.json();
    if (!r.ok || data.ok === false) { alert('Error: ' + (data.error || data.detail || 'Unknown error')); btn.disabled = false; btn.textContent = 'Approve & send'; return; }
    const badge = card.querySelector('.badge'); badge.textContent = 'sent'; badge.className = 'badge sent';
    card.querySelector('.draft-actions').remove();
  } catch (e) {
    alert('Network error.'); btn.disabled = false; btn.textContent = 'Approve & send';
  }
}

async function bbReject(draftId, btn) {
  const card = btn.closest('.draft-card');
  btn.disabled = true;
  try {
    const r = await fetch('api/buyback_draft_reject.php?draft_id=' + encodeURIComponent(draftId), {method: 'POST', credentials: 'same-origin'});
    const data = await r.json();
    if (!r.ok || data.ok === false) { alert('Error: ' + (data.error || data.detail || 'Unknown error')); btn.disabled = false; return; }
    const badge = card.querySelector('.badge'); badge.textContent = 'rejected'; badge.className = 'badge rejected';
    card.querySelector('.draft-actions').remove();
  } catch (e) {
    alert('Network error.'); btn.disabled = false;
  }
}

<?php if ($showAdminTab): ?>
// ── Admin/BIC oversight ──────────────────────────────────────────────────────
async function loadAdminTable() {
  const el = document.getElementById('bb-admin-table');
  el.innerHTML = '<div class="empty-note">Loading…</div>';
  try {
    const r = await fetch('api/buyback_admin_drafts.php', {credentials: 'same-origin'});
    const data = await r.json();
    if (!r.ok || data.ok === false) {
      el.innerHTML = `<div class="empty-note">${bbEscape(data.error || 'Could not load team activity.')}</div>`;
      return;
    }
    const rows = data.drafts || [];
    if (!rows.length) { el.innerHTML = '<div class="empty-note">No activity yet.</div>'; return; }
    el.innerHTML = `<table class="hist-table">
      <thead><tr><th>Agent</th><th>Tool</th><th>Target</th><th>Status</th><th>Generated</th></tr></thead>
      <tbody>${rows.map(r => `
        <tr>
          <td>${bbEscape(r.agentName || r.agentEmail)}</td>
          <td>${bbEscape(r.agentType)}</td>
          <td>${bbEscape(r.targetLabel)}</td>
          <td><span class="badge ${bbEscape(r.status)}">${bbEscape(r.status)}</span></td>
          <td>${r.generatedAt ? new Date(r.generatedAt).toLocaleDateString() : ''}</td>
        </tr>`).join('')}</tbody>
    </table>`;
  } catch (e) {
    el.innerHTML = '<div class="empty-note">Network error.</div>';
  }
}
<?php endif; ?>
</script>
</body>
</html>
