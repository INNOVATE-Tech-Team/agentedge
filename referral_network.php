<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/referral_network.php';

$agent = require_login();
$tab   = ($_GET['tab'] ?? 'partners') === 'requests' ? 'requests' : 'partners';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Referral Network — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .rn-tabs{display:flex;gap:0;border-bottom:2px solid #E6E7E8;margin-bottom:20px;flex-wrap:wrap}
    .rn-tab-btn{padding:9px 16px;border:none;background:none;font-size:13px;font-weight:700;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;color:#666}
    .rn-tab-btn.active{color:#000;border-bottom-color:#82C112}
    .rn-tab-pane{display:none}
    .rn-tab-pane.active{display:block}
    .rn-toolbar{display:flex;justify-content:flex-end;margin-bottom:16px}
    .rn-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:16px 20px;margin-bottom:14px}
    .rn-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
    .rn-name{font-size:15px;font-weight:800}
    .rn-meta{font-size:12px;color:var(--faint);margin-top:2px}
    .rn-actions{display:flex;gap:6px;flex-shrink:0}
    .rn-empty{text-align:center;color:var(--faint);padding:30px;font-size:13px}
    .rn-form{display:grid;grid-template-columns:1fr 1fr;gap:10px 16px;margin-bottom:14px}
    .rn-form .full{grid-column:1/-1}
    .rn-form label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--faint);margin-bottom:4px}
    .rn-form input,.rn-form select,.rn-form textarea{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;box-sizing:border-box;font-family:inherit}
    .rn-form textarea{min-height:60px;resize:vertical}
    .btn-add{padding:9px 18px;background:var(--green,#82C112);color:#111;font-weight:800;font-size:13px;border:0;border-radius:6px;cursor:pointer}
    .btn-add:hover{background:#5b8e0d;color:#fff}
    .btn-sm{padding:5px 10px;font-size:11px;border-radius:5px;border:1px solid var(--border);background:#fff;cursor:pointer;white-space:nowrap}
    .btn-sm:hover{border-color:#82C112;color:#5b8e0d}
    .btn-sm.danger:hover{border-color:#c00;color:#c00}
    .rn-leads{margin-top:12px;border-top:1px solid #f0f0f0;padding-top:12px}
    .rn-lead-row{display:flex;align-items:center;gap:10px;padding:6px 0;font-size:12.5px;border-bottom:1px solid #f7f7f7}
    .rn-lead-row:last-child{border-bottom:none}
    .rn-lead-status{font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap}
    .st-sent{background:#eef2fb;color:#2b5f9e}
    .st-contacted{background:#fdf3e3;color:#a06a1c}
    .st-under_contract{background:#f3e8fb;color:#7a2ba0}
    .st-closed_won{background:#eef5e8;color:#5b8e0d}
    .st-closed_lost{background:#fdeaea;color:#b3372c}
    .rn-req-badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;background:#e8f5d0;color:#5b8e0d;text-transform:uppercase}
    .rn-resp{background:#f9fdf5;border:1px solid #e0eed0;border-radius:8px;padding:10px 12px;margin-top:8px;font-size:12.5px}
    .rn-resp + .rn-resp{margin-top:6px}
    .hidden{display:none}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('referral_network', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div class="content-title">Referral Network</div>
        <div class="content-hello">Track your outside-market referral partners, and ask the company for a hand where you don't have one.</div>
      </div>
    </header>
    <main class="wrap">

      <div class="rn-tabs">
        <button class="rn-tab-btn<?= $tab === 'partners'  ? ' active' : '' ?>" data-tab="partners"  onclick="switchRnTab('partners')">My Partners</button>
        <button class="rn-tab-btn<?= $tab === 'requests' ? ' active' : '' ?>" data-tab="requests" onclick="switchRnTab('requests')">Company Requests</button>
      </div>

      <!-- ── MY PARTNERS ─────────────────────────────────────────────────── -->
      <div id="rn-tab-partners" class="rn-tab-pane<?= $tab === 'partners' ? ' active' : '' ?>">
        <div class="rn-toolbar"><button class="btn-add" onclick="openPartnerForm()">+ Add Partner</button></div>

        <div id="partner-form-card" class="rn-card hidden">
          <div class="rn-name" id="partner-form-title">Add Partner</div>
          <div class="rn-form" style="margin-top:12px">
            <input type="hidden" id="pf-id">
            <div class="full"><label>Name</label><input id="pf-name" placeholder="Full name"></div>
            <div><label>Company / Brokerage</label><input id="pf-company"></div>
            <div><label>Specialty</label><input id="pf-specialty" placeholder="e.g. Luxury, Relocation, New Construction"></div>
            <div><label>State</label><select id="pf-state"></select></div>
            <div><label>Metro</label><select id="pf-metro"></select></div>
            <div><label>Phone</label><input id="pf-phone" type="tel"></div>
            <div><label>Email</label><input id="pf-email" type="email"></div>
            <div class="full"><label>How You Met</label><input id="pf-how_met" placeholder="e.g. NAR Conference 2026"></div>
            <div class="full"><label>Notes</label><textarea id="pf-notes"></textarea></div>
          </div>
          <button class="btn-add" onclick="savePartner()">Save</button>
          <button class="btn-sm" onclick="closePartnerForm()">Cancel</button>
          <span id="partner-form-msg" style="font-size:12px;color:var(--faint);margin-left:8px"></span>
        </div>

        <div id="partners-list"></div>
      </div>

      <!-- ── COMPANY REQUESTS ────────────────────────────────────────────── -->
      <div id="rn-tab-requests" class="rn-tab-pane<?= $tab === 'requests' ? ' active' : '' ?>">
        <div class="rn-card">
          <div class="rn-name">Post a Request</div>
          <p class="rn-meta" style="margin:4px 0 12px">Don't have a partner somewhere? Ask the whole company — anyone with a connection there can respond directly to you.</p>
          <div class="rn-form">
            <div><label>Referral Type</label><select id="rf-type"><option value="buyer">Buyer</option><option value="seller">Seller</option><option value="other">Other</option></select></div>
            <div><label>State</label><select id="rf-state"></select></div>
            <div><label>Metro</label><select id="rf-metro"></select></div>
            <div class="full"><label>What are you looking for?</label><textarea id="rf-notes" placeholder="e.g. Buyer relocating from Myrtle Beach, needs a strong luxury agent by end of March."></textarea></div>
          </div>
          <button class="btn-add" onclick="createRequest()">Post Request</button>
          <span id="request-form-msg" style="font-size:12px;color:var(--faint);margin-left:8px"></span>
        </div>

        <div id="requests-list"></div>
      </div>

    </main>
  </div>
</div>
<script>
const ME = <?= json_encode(strtolower(trim($agent['email'] ?? ''))) ?>;
let METROS = [];
let STATE_TO_METROS = {};

function esc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmtDate(s) { if (!s) return ''; const d = new Date(s.replace(' ', 'T') + 'Z'); return isNaN(d) ? s : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); }
function post(body) {
  return fetch('api/referral_network.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(r => r.json());
}

window.switchRnTab = function (t) {
  document.querySelectorAll('.rn-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === t));
  document.querySelectorAll('.rn-tab-pane').forEach(p => p.classList.toggle('active', p.id === 'rn-tab-' + t));
  history.replaceState(null, '', 'referral_network.php?tab=' + t);
};

function populateStateSelect(sel) {
  const states = [...new Map(METROS.map(m => [m.state_code, m.state_name])).entries()].sort((a, b) => a[1].localeCompare(b[1]));
  sel.innerHTML = '<option value="">Select a state…</option>' + states.map(([code, name]) => `<option value="${code}">${esc(name)}</option>`).join('');
}
function populateMetroSelect(sel, stateCode, selectedId) {
  const list = STATE_TO_METROS[stateCode] || [];
  sel.innerHTML = '<option value="">Select a market…</option>' + list.map(m => `<option value="${m.id}"${String(m.id) === String(selectedId) ? ' selected' : ''}>${esc(m.metro_name)}</option>`).join('');
}
function wireStateMetro(stateSel, metroSel) {
  stateSel.addEventListener('change', () => populateMetroSelect(metroSel, stateSel.value, null));
}

// ── Partners ───────────────────────────────────────────────────────────────────
let PARTNERS = [];

function openPartnerForm(partner) {
  document.getElementById('partner-form-card').classList.remove('hidden');
  document.getElementById('partner-form-title').textContent = partner ? 'Edit Partner' : 'Add Partner';
  document.getElementById('pf-id').value = partner ? partner.id : '';
  document.getElementById('pf-name').value = partner ? partner.name : '';
  document.getElementById('pf-company').value = partner ? partner.company : '';
  document.getElementById('pf-specialty').value = partner ? partner.specialty : '';
  document.getElementById('pf-phone').value = partner ? partner.phone : '';
  document.getElementById('pf-email').value = partner ? partner.email : '';
  document.getElementById('pf-how_met').value = partner ? partner.how_met : '';
  document.getElementById('pf-notes').value = partner ? partner.notes : '';
  const stateSel = document.getElementById('pf-state');
  const metroSel = document.getElementById('pf-metro');
  populateStateSelect(stateSel);
  stateSel.value = partner ? partner.state_code : '';
  populateMetroSelect(metroSel, stateSel.value, partner ? partner.metro_id : null);
  document.getElementById('partner-form-card').scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function closePartnerForm() { document.getElementById('partner-form-card').classList.add('hidden'); }

function savePartner() {
  const msg = document.getElementById('partner-form-msg');
  const body = {
    action: 'save_partner',
    id: document.getElementById('pf-id').value || 0,
    name: document.getElementById('pf-name').value.trim(),
    company: document.getElementById('pf-company').value.trim(),
    specialty: document.getElementById('pf-specialty').value.trim(),
    metro_id: document.getElementById('pf-metro').value || 0,
    phone: document.getElementById('pf-phone').value.trim(),
    email: document.getElementById('pf-email').value.trim(),
    how_met: document.getElementById('pf-how_met').value.trim(),
    notes: document.getElementById('pf-notes').value.trim(),
  };
  if (!body.name) { msg.textContent = 'Name is required.'; return; }
  if (!body.metro_id) { msg.textContent = 'Please pick a state and market.'; return; }
  msg.textContent = 'Saving…';
  post(body).then(res => {
    if (!res.ok) { msg.textContent = res.error || 'Save failed.'; return; }
    closePartnerForm();
    loadBootstrap();
  });
}

function deletePartner(id, name) {
  if (!confirm(`Remove ${name} and their referral history? This can't be undone.`)) return;
  post({ action: 'delete_partner', id }).then(res => { if (res.ok) loadBootstrap(); else alert(res.error || 'Delete failed.'); });
}

const STATUS_LABELS = { sent: 'Sent', contacted: 'Contacted', under_contract: 'Under Contract', closed_won: 'Closed — Won', closed_lost: 'Closed — Lost' };

function renderPartners() {
  const wrap = document.getElementById('partners-list');
  if (!PARTNERS.length) { wrap.innerHTML = '<div class="rn-empty">No partners yet — add the people you\'ve connected with in markets you don\'t cover.</div>'; return; }
  wrap.innerHTML = PARTNERS.map(p => {
    const leadRows = (p.leads || []).map(l => `
      <div class="rn-lead-row" data-lead-id="${l.id}">
        <span style="min-width:70px;font-weight:700">${l.direction === 'received' ? 'Received' : 'Sent'}</span>
        <span style="flex:1">${esc(l.client_name) || '<span style=\"color:#bbb\">No client name</span>'}${l.client_contact ? ' — ' + esc(l.client_contact) : ''}</span>
        <select class="btn-sm" onchange="updateLeadStatus(${l.id}, ${p.id}, this.value)">
          ${Object.entries(STATUS_LABELS).map(([k, v]) => `<option value="${k}"${k === l.status ? ' selected' : ''}>${v}</option>`).join('')}
        </select>
        <span class="rn-lead-status st-${l.status}">${STATUS_LABELS[l.status]}</span>
        <button class="btn-sm danger" onclick="deleteLead(${l.id})">✕</button>
      </div>`).join('') || '<div style="font-size:12px;color:var(--faint);padding:6px 0">No referrals logged yet.</div>';

    return `
    <div class="rn-card" data-partner-id="${p.id}">
      <div class="rn-card-head">
        <div>
          <div class="rn-name">${esc(p.name)}</div>
          <div class="rn-meta">${esc(p.company) || '—'} · ${esc(p.metro_name)}, ${esc(p.state_code)}${p.specialty ? ' · ' + esc(p.specialty) : ''}</div>
          <div class="rn-meta">${esc(p.phone) || ''}${p.phone && p.email ? ' · ' : ''}${esc(p.email) || ''}</div>
        </div>
        <div class="rn-actions">
          <button class="btn-sm" onclick='openPartnerForm(${JSON.stringify(p).replace(/'/g, "&#39;")})'>Edit</button>
          <button class="btn-sm danger" onclick="deletePartner(${p.id}, '${esc(p.name).replace(/'/g, "\\'")}')">Delete</button>
        </div>
      </div>
      <div class="rn-leads">
        <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);margin-bottom:6px">Referral History</div>
        ${leadRows}
        <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
          <select id="lead-dir-${p.id}" class="btn-sm"><option value="sent">Sent to them</option><option value="received">Received from them</option></select>
          <input id="lead-name-${p.id}" class="btn-sm" placeholder="Client name" style="flex:1;min-width:120px">
          <input id="lead-contact-${p.id}" class="btn-sm" placeholder="Client phone/email" style="flex:1;min-width:140px">
          <button class="btn-sm" onclick="logLead(${p.id})">+ Log Referral</button>
        </div>
      </div>
    </div>`;
  }).join('');
}

function logLead(partnerId) {
  const name = document.getElementById(`lead-name-${partnerId}`).value.trim();
  const contact = document.getElementById(`lead-contact-${partnerId}`).value.trim();
  const direction = document.getElementById(`lead-dir-${partnerId}`).value;
  post({ action: 'save_lead', partner_id: partnerId, direction, client_name: name, client_contact: contact }).then(res => {
    if (res.ok) loadBootstrap(); else alert(res.error || 'Save failed.');
  });
}
function updateLeadStatus(leadId, partnerId, status) {
  post({ action: 'save_lead', id: leadId, partner_id: partnerId, status }).then(res => { if (res.ok) loadBootstrap(); else alert(res.error || 'Update failed.'); });
}
function deleteLead(id) {
  if (!confirm('Delete this referral log entry?')) return;
  post({ action: 'delete_lead', id }).then(res => { if (res.ok) loadBootstrap(); else alert(res.error || 'Delete failed.'); });
}

// ── Requests ───────────────────────────────────────────────────────────────────
let REQUESTS = [];

const TYPE_LABELS = { buyer: 'Buyer', seller: 'Seller', other: 'Referral' };

function createRequest() {
  const msg = document.getElementById('request-form-msg');
  const metroId = document.getElementById('rf-metro').value;
  const notes = document.getElementById('rf-notes').value.trim();
  const referralType = document.getElementById('rf-type').value;
  if (!metroId) { msg.textContent = 'Please pick a state and market.'; return; }
  msg.textContent = 'Posting…';
  post({ action: 'create_request', metro_id: metroId, notes, referral_type: referralType }).then(res => {
    if (!res.ok) { msg.textContent = res.error || 'Post failed.'; return; }
    document.getElementById('rf-notes').value = '';
    msg.innerHTML = '';
    if (res.post_text) {
      const span = document.createElement('span');
      span.textContent = res.fb_posted ? 'Posted — also shared to Facebook. ' : 'Posted. ';
      if (!res.fb_posted) {
        const btn = document.createElement('button');
        btn.className = 'btn-sm';
        btn.textContent = 'Copy post text for Facebook';
        btn.onclick = () => { navigator.clipboard.writeText(res.post_text); btn.textContent = 'Copied!'; setTimeout(() => btn.textContent = 'Copy post text for Facebook', 2000); };
        span.appendChild(btn);
      }
      msg.appendChild(span);
    }
    loadBootstrap();
  });
}
function closeRequest(id) {
  post({ action: 'close_request', id }).then(res => { if (res.ok) loadBootstrap(); else alert(res.error || 'Failed.'); });
}

function toggleRespond(id) {
  const panel = document.getElementById(`respond-panel-${id}`);
  if (!panel) return;
  panel.classList.toggle('hidden');
}

function sendResponse(id) {
  const partnerId = document.getElementById(`respond-partner-${id}`).value;
  const message = document.getElementById(`respond-msg-${id}`).value.trim();
  const status = document.getElementById(`respond-status-${id}`);
  if (!partnerId && !message) { status.textContent = 'Add a note or pick a partner to share.'; return; }
  status.textContent = 'Sending…';
  post({ action: 'respond_request', request_id: id, message, partner_id: partnerId || 0 }).then(res => {
    if (res.ok) { loadBootstrap(); } else { status.textContent = res.error || 'Failed.'; }
  });
}

function renderRequests() {
  const wrap = document.getElementById('requests-list');
  if (!REQUESTS.length) { wrap.innerHTML = '<div class="rn-empty">No requests yet.</div>'; return; }
  const partnerOptions = PARTNERS.map(p => `<option value="${p.id}">${esc(p.name)}${p.company ? ' — ' + esc(p.company) : ''} (${esc(p.metro_name)}, ${esc(p.state_code)})</option>`).join('');

  wrap.innerHTML = REQUESTS.map(r => {
    const responses = r.mine ? (r.responses || []).map(resp => {
      const shared = resp.shared_partner_name ? `
        <div style="margin-top:6px;padding:8px 10px;background:#fff;border:1px solid #e0eed0;border-radius:6px">
          <strong>${esc(resp.shared_partner_name)}</strong>${resp.shared_partner_company ? ' — ' + esc(resp.shared_partner_company) : ''}
          ${resp.shared_partner_specialty ? `<div style="font-size:11.5px;color:var(--faint)">${esc(resp.shared_partner_specialty)}</div>` : ''}
          <div style="font-size:11.5px;margin-top:2px">${esc(resp.shared_partner_phone) || ''}${resp.shared_partner_phone && resp.shared_partner_email ? ' · ' : ''}${esc(resp.shared_partner_email) || ''}</div>
        </div>` : '';
      const isFb = resp.source === 'facebook';
      const who = isFb ? `${esc(resp.fb_commenter_name)} <span style="font-size:10px;font-weight:700;color:#1877F2;background:#e7f0fd;padding:1px 6px;border-radius:8px;margin-left:4px">FACEBOOK</span>` : esc(resp.responder_email);
      return `<div class="rn-resp"><strong>${who}</strong>${resp.message ? ' — ' + esc(resp.message) : ''}${shared}<div style="color:var(--faint);font-size:11px;margin-top:4px">${fmtDate(resp.created_at)}</div></div>`;
    }).join('') : '';

    const respondPanel = (r.status === 'open' && !r.mine) ? `
      <div id="respond-panel-${r.id}" class="hidden" style="margin-top:10px;border-top:1px solid #f0f0f0;padding-top:10px">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--faint);margin-bottom:6px">Respond to ${esc(r.agent_email)}</div>
        <select id="respond-partner-${r.id}" class="btn-sm" style="width:100%;margin-bottom:6px">
          <option value="">— Just a note, no partner to share —</option>
          ${partnerOptions}
        </select>
        <textarea id="respond-msg-${r.id}" class="btn-sm" style="width:100%;min-height:50px;box-sizing:border-box" placeholder="Optional note…"></textarea>
        <div style="margin-top:6px">
          <button class="btn-add" style="padding:6px 14px;font-size:12px" onclick="sendResponse(${r.id})">Send</button>
          <button class="btn-sm" onclick="toggleRespond(${r.id})">Cancel</button>
          <span id="respond-status-${r.id}" style="font-size:12px;color:var(--faint);margin-left:6px"></span>
        </div>
      </div>` : '';

    const actions = r.status !== 'open' ? '' : (r.mine
      ? `<button class="btn-sm" onclick="closeRequest(${r.id})">Mark Closed</button>`
      : `<button class="btn-add" style="padding:6px 14px;font-size:12px" onclick="toggleRespond(${r.id})">I can help</button>`);

    return `
    <div class="rn-card">
      <div class="rn-card-head">
        <div>
          <div class="rn-name">${TYPE_LABELS[r.referral_type] || 'Referral'} — ${esc(r.metro_name)}, ${esc(r.state_code)} ${r.mine ? '<span class="rn-req-badge">You</span>' : ''}${r.fb_post_id ? '<span class="rn-req-badge" style="background:#e7f0fd;color:#1877F2">On Facebook</span>' : ''}</div>
          <div class="rn-meta">${r.status === 'open' ? 'Open' : 'Closed'} · posted ${fmtDate(r.created_at)}${r.mine ? '' : ' by ' + esc(r.agent_email)}</div>
          ${r.notes ? `<div style="font-size:13px;margin-top:8px">${esc(r.notes)}</div>` : ''}
        </div>
        <div class="rn-actions">${actions}</div>
      </div>
      ${respondPanel}
      ${responses}
    </div>`;
  }).join('');
}

// ── Bootstrap ────────────────────────────────────────────────────────────────
function loadBootstrap() {
  fetch('api/referral_network.php', { credentials: 'same-origin' }).then(r => r.json()).then(d => {
    if (!d.ok) return;
    METROS = d.metros;
    STATE_TO_METROS = {};
    METROS.forEach(m => { (STATE_TO_METROS[m.state_code] = STATE_TO_METROS[m.state_code] || []).push(m); });
    PARTNERS = d.partners;
    REQUESTS = d.requests;
    renderPartners();
    renderRequests();
    populateStateSelect(document.getElementById('rf-state'));
  }).catch(() => {
    document.getElementById('partners-list').innerHTML = '<div class="rn-empty">Could not load — please refresh.</div>';
  });
}
wireStateMetro(document.getElementById('pf-state'), document.getElementById('pf-metro'));
wireStateMetro(document.getElementById('rf-state'), document.getElementById('rf-metro'));
loadBootstrap();
</script>
</body>
</html>
