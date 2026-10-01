<?php
// My AI Agents — deploy your own AI agent on your own Follow Up Boss book.
// Backend: coastline-server /public/agentedge/ai/* (via api/ai_agents.php).
// Nothing an agent drafts is sent until the agent approves it here; the CRM
// enforces that (user agents can never run unattended). Added 2026-09-30.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
if (!can_use_buyback()) { header('Location: index.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My AI Agents — AgentEdge</title>
<link rel="stylesheet" href="assets/app.css">
<style>
.bo-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
.ai-intro{font-size:13px;color:#555;line-height:1.6;margin:0 0 22px;max-width:760px}
.ai-tabs{display:flex;gap:6px;margin-bottom:20px;border-bottom:1px solid var(--border);flex-wrap:wrap}
.ai-tab{padding:10px 18px;font-size:13px;font-weight:700;color:#888;cursor:pointer;border-bottom:2px solid transparent}
.ai-tab.active{color:#5b8e0d;border-bottom-color:#82C112}
.ai-tab .count{display:inline-block;min-width:18px;padding:1px 6px;margin-left:6px;border-radius:9px;background:#82C112;color:#000;font-size:11px;text-align:center}
.ai-panel{display:none}.ai-panel.active{display:block}
.ai-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:18px 22px;margin-bottom:16px}
.ai-card.deployed{border-left:4px solid #82C112}
.ai-card h3{margin:0 0 4px;font-size:16px;font-weight:800;color:#1a1a1a}
.ai-card p.desc{margin:0 0 14px;font-size:13px;color:#555;line-height:1.55}
.ai-row{display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.ai-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:10px 0 14px}
.field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px}
.field input,.field select,.field textarea{width:100%;padding:9px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;box-sizing:border-box;font-family:inherit}
.field input:focus,.field select:focus,.field textarea:focus{outline:2px solid #82C112;border-color:#82C112}
.field .hint{font-size:11px;color:#999;margin-top:3px}
.btn-primary{padding:9px 20px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
.btn-primary:hover{background:#5b8e0d;color:#fff}
.btn-primary:disabled{opacity:.5;cursor:default}
.btn-secondary{padding:7px 16px;background:#fff;color:#333;border:1px solid #ccc;border-radius:6px;font-weight:700;font-size:12px;cursor:pointer}
.btn-secondary:hover{background:#f5f5f5;border-color:#aaa}
.btn-danger{padding:7px 16px;background:#fff;color:#c0392b;border:1px solid #f0c4bc;border-radius:6px;font-weight:700;font-size:12px;cursor:pointer}
.btn-danger:hover{background:#fdf0ed}
.pill{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.pill.on{background:#e8f5d6;color:#3d6b06}.pill.off{background:#eee;color:#777}.pill.practice{background:#fff4d6;color:#8a6200}
.switch{display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:700;cursor:pointer}
.switch input{width:18px;height:18px;accent-color:#82C112}
.status{font-size:12px;font-weight:700}.status.ok{color:#2e7d32}.status.err{color:#c0392b}
.meta{font-size:12px;color:#888}
.empty-note{color:var(--faint);font-style:italic;text-align:center;padding:20px}
.draft-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:16px 20px;margin-bottom:14px}
.draft-head{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.draft-to{font-weight:800;font-size:14px;color:#1a1a1a}
.draft-why{background:#f9fdf5;border:1px solid #d4edab;border-radius:6px;padding:8px 12px;font-size:12px;color:#3d6b06;margin-bottom:10px}
.draft-card textarea{min-height:140px}
.hist-row{display:grid;grid-template-columns:150px 1fr 110px;gap:12px;padding:10px 0;border-top:1px solid #eee;font-size:13px}
.hist-row:first-child{border-top:none}
@media (max-width:640px){.hist-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('ai_agents', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="bo-eyebrow">My Tools</div>
      <div class="content-title">My AI Agents</div>
    </div>
  </div>
  <div class="wrap">
    <p class="ai-intro">Deploy an AI agent that works your own Follow Up Boss database. It reads each contact's history and drafts
      personal emails in your voice. <strong>Nothing is ever sent without your approval.</strong> Drafts wait for you
      under <em>Waiting for you</em>, where you can edit, approve or reject each one.</p>

    <div class="ai-tabs">
      <div class="ai-tab active" data-panel="ai-mine">My agents</div>
      <div class="ai-tab" data-panel="ai-queue">Waiting for you <span class="count" id="ai-pending-count" hidden>0</span></div>
      <div class="ai-tab" data-panel="ai-history">History</div>
    </div>

    <div class="ai-panel active" id="ai-mine"><div id="ai-agents-list"><div class="empty-note">Loading…</div></div></div>
    <div class="ai-panel" id="ai-queue"><div id="ai-queue-list"><div class="empty-note">Loading…</div></div></div>
    <div class="ai-panel" id="ai-history">
      <div class="ai-row">
        <div class="field" style="min-width:200px">
          <label>Show</label>
          <select id="ai-hist-status">
            <option value="sent">Sent</option>
            <option value="shadow">Practice-mode drafts</option>
            <option value="rejected">Rejected</option>
            <option value="blocked">Blocked by safety rules</option>
            <option value="failed">Failed</option>
          </select>
        </div>
      </div>
      <div class="ai-card" id="ai-hist-list"><div class="empty-note">Loading…</div></div>
    </div>
  </div>
</div>
</div>

<script>
const API = 'api/ai_agents.php';
const DAYS = {mon:'Monday',tue:'Tuesday',wed:'Wednesday',thu:'Thursday',fri:'Friday',sat:'Saturday',sun:'Sunday'};
const BLOCK_REASONS = {
  recipient_cooldown: 'Contacted too recently', do_not_contact: 'On the do-not-contact list',
  email_unsubscribed: 'Unsubscribed', no_contact_info: 'No email on file', daily_limit: 'Daily send limit reached',
  daily_cost_cap: 'Daily AI budget reached', agent_disabled: 'Agent was off',
};
let templates = [], agents = [];

function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function when(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return d.toLocaleDateString(undefined, {month:'short', day:'numeric'}) + ' ' + d.toLocaleTimeString(undefined, {hour:'numeric', minute:'2-digit'});
}
async function call(op, params = {}, body = null) {
  const qs = new URLSearchParams({op, ...params});
  const res = await fetch(API + '?' + qs, body === null ? {} : {
    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body),
  });
  let data = {};
  try { data = await res.json(); } catch (e) {}
  if (!res.ok || data.ok === false) {
    const msg = typeof data.detail === 'string' ? data.detail : (data.error || ('Request failed (' + res.status + ')'));
    throw new Error(msg);
  }
  return data;
}

// ── Tabs ─────────────────────────────────────────────────────────────────────
document.querySelectorAll('.ai-tab').forEach(tab => tab.addEventListener('click', () => {
  document.querySelectorAll('.ai-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.ai-panel').forEach(p => p.classList.remove('active'));
  tab.classList.add('active');
  document.getElementById(tab.dataset.panel).classList.add('active');
  if (tab.dataset.panel === 'ai-queue') loadQueue();
  if (tab.dataset.panel === 'ai-history') loadHistory();
}));
document.getElementById('ai-hist-status').addEventListener('change', loadHistory);

// ── My agents ────────────────────────────────────────────────────────────────
async function loadAgents() {
  const box = document.getElementById('ai-agents-list');
  try {
    [templates, agents] = await Promise.all([call('templates').then(d => d.templates), call('agents').then(d => d.agents)]);
  } catch (e) {
    box.innerHTML = '<div class="ai-card"><span class="status err">' + esc(e.message) + '</span></div>';
    return;
  }
  const pending = agents.reduce((n, a) => n + (a.pendingApproval || 0), 0);
  const badge = document.getElementById('ai-pending-count');
  badge.textContent = pending; badge.hidden = !pending;
  box.innerHTML = templates.map(t => {
    const a = agents.find(x => x.templateKey === t.key);
    return a ? deployedCard(t, a) : templateCard(t);
  }).join('') || '<div class="empty-note">No agents are available yet.</div>';
}

function templateCard(t) {
  return `<div class="ai-card">
    <h3>${esc(t.name)}</h3>
    <p class="desc">${esc(t.description)}</p>
    <div class="ai-row">
      <button class="btn-primary" onclick="deploy('${esc(t.key)}', this)">Deploy this agent</button>
      <span class="status" id="st-${esc(t.key)}"></span>
    </div>
    <div class="meta">It starts switched off. You choose the settings and turn it on when you're ready.</div>
  </div>`;
}

function deployedCard(t, a) {
  const c = a.config || {};
  const statePill = !a.enabled ? '<span class="pill off">Off</span>'
    : a.mode === 'shadow' ? '<span class="pill practice">On &middot; practice mode</span>' : '<span class="pill on">On</span>';
  const lr = a.lastRun;
  let last = 'Has not run yet.';
  if (lr) {
    const s = lr.summary || {};
    last = lr.status === 'running' ? 'Running now…'
      : `Last run ${when(lr.started_at)}: ${s.drafted || 0} draft${s.drafted === 1 ? '' : 's'}` + (lr.error ? ' (stopped with an error)' : '');
  }
  const k = esc(t.key);
  const dayOpts = Object.entries(DAYS).map(([v, l]) => `<option value="${v}"${c.run_day === v ? ' selected' : ''}>${l}</option>`).join('');
  return `<div class="ai-card deployed" id="card-${k}">
    <div class="ai-row" style="justify-content:space-between">
      <h3>${esc(t.name)}</h3>${statePill}
    </div>
    <p class="desc">${esc(t.description)}</p>
    <div class="ai-row">
      <label class="switch"><input type="checkbox" id="en-${k}"${a.enabled ? ' checked' : ''}> Agent is on</label>
      <div class="field" style="min-width:260px;margin:0">
        <select id="mode-${k}">
          <option value="approval"${a.mode === 'approval' ? ' selected' : ''}>Drafts wait for my approval</option>
          <option value="shadow"${a.mode === 'shadow' ? ' selected' : ''}>Practice mode (show me drafts, never send)</option>
        </select>
      </div>
    </div>
    <div class="ai-grid">
      <div class="field"><label>Quiet for at least</label>
        <input type="number" id="qd-${k}" min="30" max="730" value="${esc(c.quiet_days)}"><div class="hint">days with no activity in FUB</div></div>
      <div class="field"><label>Drafts per run</label>
        <input type="number" id="md-${k}" min="1" max="25" value="${esc(c.max_drafts_per_run)}"><div class="hint">1 to 25</div></div>
      <div class="field"><label>Runs every</label><select id="rd-${k}">${dayOpts}</select><div class="hint">at about 8:30am ET</div></div>
      <div class="field"><label>Skip these FUB stages</label>
        <input type="text" id="xs-${k}" value="${esc((c.exclude_stages || []).join(', '))}"><div class="hint">comma separated</div></div>
    </div>
    <div class="field"><label>How you write (optional)</label>
      <textarea id="vn-${k}" rows="3" maxlength="600" placeholder="e.g. I'm casual and upbeat, I sign off with 'Talk soon', I never use exclamation points.">${esc(c.voice_notes)}</textarea></div>
    <div class="ai-row" style="margin-top:6px">
      <button class="btn-primary" onclick="saveAgent('${k}', this)">Save</button>
      <button class="btn-secondary" onclick="runNow('${k}', this)"${a.enabled ? '' : ' disabled title="Turn the agent on first"'}>Run now</button>
      <span class="status" id="st-${k}"></span>
    </div>
    <div class="meta">${esc(last)}${a.pendingApproval ? ` &middot; <strong>${a.pendingApproval} waiting for you</strong>` : ''}</div>
  </div>`;
}

async function deploy(key, btn) {
  const st = document.getElementById('st-' + key);
  btn.disabled = true; st.className = 'status'; st.textContent = 'Setting up…';
  try {
    await call('deploy', {}, {template_key: key});
    await loadAgents();
  } catch (e) {
    btn.disabled = false; st.className = 'status err';
    st.innerHTML = esc(e.message) + (/fub|follow up boss/i.test(e.message) ? ' <a href="profile.php">Link your Follow Up Boss account on My Profile</a>.' : '');
  }
}

async function saveAgent(key, btn) {
  const st = document.getElementById('st-' + key);
  const v = id => document.getElementById(id + '-' + key).value;
  btn.disabled = true; st.className = 'status'; st.textContent = 'Saving…';
  try {
    await call('update', {template: key}, {
      enabled: document.getElementById('en-' + key).checked,
      mode: v('mode'),
      config: {
        quiet_days: parseInt(v('qd'), 10), max_drafts_per_run: parseInt(v('md'), 10), run_day: v('rd'),
        exclude_stages: v('xs').split(',').map(s => s.trim()).filter(Boolean), voice_notes: v('vn'),
      },
    });
    await loadAgents();
    const st2 = document.getElementById('st-' + key);
    if (st2) { st2.className = 'status ok'; st2.textContent = 'Saved'; }
  } catch (e) {
    btn.disabled = false; st.className = 'status err'; st.textContent = e.message;
  }
}

async function runNow(key, btn) {
  const st = document.getElementById('st-' + key);
  btn.disabled = true; st.className = 'status'; st.textContent = 'Starting…';
  try {
    await call('run', {template: key}, {});
    st.className = 'status ok';
    st.textContent = 'Running. Drafts will show up under "Waiting for you" over the next few minutes.';
    setTimeout(loadAgents, 60000);
  } catch (e) {
    btn.disabled = false; st.className = 'status err'; st.textContent = e.message;
  }
}

// ── Approval queue ───────────────────────────────────────────────────────────
async function loadQueue() {
  const box = document.getElementById('ai-queue-list');
  box.innerHTML = '<div class="empty-note">Loading…</div>';
  let rows;
  try { rows = (await call('actions', {status: 'pending_approval'})).actions; }
  catch (e) { box.innerHTML = '<div class="ai-card"><span class="status err">' + esc(e.message) + '</span></div>'; return; }
  if (!rows.length) { box.innerHTML = '<div class="empty-note">Nothing is waiting for you right now.</div>'; return; }
  box.innerHTML = rows.map(r => {
    const id = esc(r.id), why = (r.metadata || {}).why;
    return `<div class="draft-card" id="d-${id}">
      <div class="draft-head">
        <div><div class="draft-to">${esc(r.recipient_name || r.recipient_key)}</div><div class="meta">${esc(r.recipient_key)}</div></div>
        <div class="meta">Drafted ${esc(when(r.created_at))}</div>
      </div>
      ${why ? `<div class="draft-why"><strong>Why this person:</strong> ${esc(why)}</div>` : ''}
      <div class="field"><label>Subject</label><input type="text" id="ds-${id}" value="${esc(r.subject)}"></div>
      <div class="field"><label>Email</label><textarea id="db-${id}">${esc(r.body)}</textarea>
        <div class="hint">Your name, the INNOVATE header and the unsubscribe footer are added when it sends.</div></div>
      <div class="ai-row">
        <button class="btn-primary" onclick="approve('${id}', this)">Approve &amp; send</button>
        <button class="btn-danger" onclick="reject('${id}', this)">Reject</button>
        <span class="status" id="dst-${id}"></span>
      </div>
    </div>`;
  }).join('');
}

async function approve(id, btn) {
  const st = document.getElementById('dst-' + id);
  btn.disabled = true; st.className = 'status'; st.textContent = 'Approving…';
  try {
    await call('approve', {action_id: id}, {
      subject: document.getElementById('ds-' + id).value, body: document.getElementById('db-' + id).value,
    });
    st.className = 'status ok'; st.textContent = 'Approved. It goes out within a few minutes (during sending hours).';
    setTimeout(() => { document.getElementById('d-' + id)?.remove(); loadAgents(); }, 1500);
  } catch (e) { btn.disabled = false; st.className = 'status err'; st.textContent = e.message; }
}

async function reject(id, btn) {
  const st = document.getElementById('dst-' + id);
  btn.disabled = true;
  try {
    await call('reject', {action_id: id}, {});
    document.getElementById('d-' + id)?.remove(); loadAgents();
  } catch (e) { btn.disabled = false; st.className = 'status err'; st.textContent = e.message; }
}

// ── History ──────────────────────────────────────────────────────────────────
async function loadHistory() {
  const box = document.getElementById('ai-hist-list');
  const status = document.getElementById('ai-hist-status').value;
  box.innerHTML = '<div class="empty-note">Loading…</div>';
  let rows;
  try { rows = (await call('actions', {status, limit: 100})).actions; }
  catch (e) { box.innerHTML = '<span class="status err">' + esc(e.message) + '</span>'; return; }
  if (!rows.length) { box.innerHTML = '<div class="empty-note">Nothing here yet.</div>'; return; }
  box.innerHTML = rows.map(r => `<div class="hist-row">
      <div><strong>${esc(r.recipient_name || r.recipient_key)}</strong><div class="meta">${esc(when(r.executed_at || r.created_at))}</div></div>
      <div><div style="font-weight:700">${esc(r.subject)}</div><div class="meta">${esc((r.body || '').slice(0, 220))}${(r.body || '').length > 220 ? '…' : ''}</div></div>
      <div class="meta">${esc(r.block_reason ? (BLOCK_REASONS[r.block_reason] || r.block_reason) : r.status)}</div>
    </div>`).join('');
}

loadAgents();
</script>
</body>
</html>
