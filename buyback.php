<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/google_business.php';
$agent = require_login();
if (!can_use_buyback()) { header('Location: index.php'); exit; }
$showAdminTab = is_admin() || is_bic();
$googlePlacesKey = google_places_api_key();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Buy Back Your Time — AgentEdge</title>
<link rel="stylesheet" href="assets/app.css">
<?php if ($googlePlacesKey !== ''): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($googlePlacesKey) ?>&libraries=places&loading=async&callback=bbGmapsReady" async defer></script>
<?php endif; ?>
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
.hd-sync-status{margin-top:12px;font-size:12px;color:#888;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.hd-sync-status.syncing{color:#5b8e0d}
.hd-sync-status.err{color:#c0392b}
.hd-sync-status a{color:#5b8e0d;font-weight:700;cursor:pointer;text-decoration:none}
.hd-sync-status a:hover{text-decoration:underline}
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
.draft-expand-arrow{display:inline-block;font-size:10px;color:#888;transition:transform .15s;transform-origin:center}
.draft-card.expanded .draft-expand-arrow{transform:rotate(90deg)}
.draft-details{margin-top:10px}
.saved-comp-row{display:flex;align-items:center;gap:10px;padding:6px 0;border-top:1px solid #eee;font-size:12px}
.saved-comp-row:first-child{border-top:none}
.saved-comp-row .comp-num{width:18px;height:18px;font-size:9px}
.saved-comp-row .comp-addr{font-weight:700;color:#333}
.saved-comp-row .comp-spec{color:#888}
.saved-comp-row .comp-price{margin-left:auto;font-weight:700;color:#333;white-space:nowrap}
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
.hist-toggle{cursor:pointer;color:#5b8e0d;font-size:12px;font-weight:700}
.hist-recipients{display:none;background:#fafafa;font-size:12px}
.hist-recipients.open{display:table-row}
.field-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:12px}
.hd-check{display:flex;align-items:center;gap:6px;font-size:13px;color:#333;cursor:pointer;margin-top:22px}
.section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint);margin:0 0 8px}
.deal-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:16px 20px;margin-bottom:14px}
.deal-top{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px}
.deal-price{font-size:20px;font-weight:800;color:#111}
.deal-addr{font-size:13px;color:#666;margin-top:2px}
.deal-badges{display:flex;gap:8px;flex-wrap:wrap}
.deal-badges .badge.value-good{background:#eef5e8;color:#5b8e0d}
.deal-badges .badge.value-bad{background:#fde8e0;color:#c46a1a}
.deal-badges .badge.roi{background:#e8f0fe;color:#1a56c4}
.deal-badges .badge.note{background:#f3f3f3;color:#888}
.deal-detail{font-size:12px;color:#777;margin-top:8px;line-height:1.6}
.leads-box{margin-top:12px;padding-top:12px;border-top:1px solid #eee}
.leads-box .section-label{margin-bottom:6px}
.lead-row{display:flex;align-items:center;gap:8px;font-size:13px;padding:4px 0}
.lead-row label{cursor:pointer;display:flex;align-items:center;gap:8px}
.deal-send-bar{display:flex;align-items:center;gap:12px;margin-top:10px}
.hd-share{margin-top:12px;padding-top:12px;border-top:1px solid #eee;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.hd-share-box{width:100%;margin-top:10px}
.hd-share-link{width:100%;font-family:monospace;font-size:12px;padding:6px 8px;border:1px solid var(--border);border-radius:6px}
.hd-share-textarea{width:100%;font-family:inherit;font-size:12px;padding:8px;border:1px solid var(--border);border-radius:6px;resize:vertical;margin-top:4px}
.candidate-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 0;border-top:1px solid #eee}
.candidate-row:first-child{border-top:none}
.comp-map{width:100%;height:320px;border-radius:10px;border:1px solid var(--border);margin-top:16px;background:#eee}
.comp-list{margin-top:14px}
.comp-row{display:flex;align-items:center;gap:12px;padding:10px 12px;border-bottom:1px solid #eee}
.comp-row:last-child{border-bottom:none}
.comp-row input[type=checkbox]{width:16px;height:16px;accent-color:#82C112;cursor:pointer;flex-shrink:0}
.comp-row .comp-addr{font-weight:700;font-size:13px;color:#222}
.comp-row .comp-spec{font-size:12px;color:#888;margin-top:2px}
.comp-row .comp-price{margin-left:auto;font-weight:700;font-size:13px;color:#333;white-space:nowrap}
.comp-toolbar{display:flex;align-items:center;justify-content:space-between;margin-top:16px}
.comp-toolbar .comp-count{font-size:12px;color:#888}
.comp-num{flex-shrink:0;width:22px;height:22px;border-radius:50%;background:#c0392b;color:#fff;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center}
.gm-hover-card{font-family:inherit;max-width:220px}
.gm-hover-card img{width:100%;height:120px;object-fit:cover;border-radius:6px;margin-bottom:6px;display:block}
.gm-hover-card .gm-addr{font-weight:700;font-size:12px;color:#222}
.gm-hover-card .gm-spec{font-size:11px;color:#666;margin-top:2px}
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
      <div class="bb-tab" data-panel="bb-hotdeals">Hot Deals</div>
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
          <input type="search" id="bb-address" placeholder="e.g. 123 Ocean Blvd, Myrtle Beach, SC" autocomplete="off">
        </div>
        <div style="margin-top:14px;display:flex;align-items:center;gap:14px">
          <button class="btn-primary" id="bb-prep-btn" onclick="bbPrep()">Prep this appointment</button>
          <span class="send-status" id="bb-prep-status"></span>
        </div>
      </div>
      <div id="bb-packet"></div>
      <h3 style="margin-top:28px;font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#888">My Saved Searches</h3>
      <div id="bb-delegate-history"></div>
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

    <!-- Hot Deals: find best-value listings, matched to your own FUB leads only -->
    <div id="bb-hotdeals" class="bb-panel">
      <div class="bb-form">
        <h3>Find a deal</h3>
        <div class="field-row">
          <div class="field">
            <label>City</label>
            <input type="text" id="hd-city" placeholder="e.g. North Myrtle Beach">
          </div>
          <div class="field">
            <label>Property Type</label>
            <select id="hd-sub-type">
              <option value="Condominium">Condominium</option>
              <option value="Single Family Residence">Single Family Residence</option>
              <option value="Townhouse">Townhouse</option>
              <option value="Detached">Detached</option>
            </select>
          </div>
          <div class="field">
            <label>Beds (min–max)</label>
            <div style="display:flex;gap:6px">
              <input type="number" id="hd-min-beds" min="0" placeholder="min">
              <input type="number" id="hd-max-beds" min="0" placeholder="max">
            </div>
          </div>
          <div class="field">
            <label>Baths (min–max)</label>
            <div style="display:flex;gap:6px">
              <input type="number" id="hd-min-baths" min="0" placeholder="min">
              <input type="number" id="hd-max-baths" min="0" placeholder="max">
            </div>
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label>Min Price (optional)</label>
            <input type="number" id="hd-min-price" min="0" placeholder="e.g. 300000">
          </div>
          <div class="field">
            <label>Max Price (optional)</label>
            <input type="number" id="hd-max-price" min="0" placeholder="e.g. 600000">
          </div>
          <label class="hd-check"><input type="checkbox" id="hd-oceanfront"> Oceanfront only</label>
        </div>
        <p style="font-size:11px;color:#888;margin:-6px 0 0">Tip: leaving Min Price blank ranks by the very cheapest listings citywide, which often won't match what your leads are actually searching for — most real saved searches have a price floor (e.g. "$300,000+"). Set one close to what your leads are searching for real matches.</p>
        <p class="hint" style="margin-top:10px">Only matches leads assigned to you in Follow Up Boss — never another agent's contacts.</p>
        <div id="hd-sync-status" class="hd-sync-status"></div>
        <div style="margin-top:14px;display:flex;align-items:center;gap:14px">
          <button class="btn-primary" id="hd-search-btn" onclick="hdSearch()">Find Deals</button>
          <span class="send-status" id="hd-search-status"></span>
        </div>
      </div>
      <div id="hd-results"></div>
      <div style="margin-top:32px">
        <div class="section-label">Saved Deals</div>
        <div id="hd-saved"><div class="empty-note">Loading…</div></div>
      </div>
      <div style="margin-top:32px">
        <div class="section-label">Past Sends</div>
        <div id="hd-history"><div class="empty-note">Loading…</div></div>
      </div>
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
    if (tab.dataset.panel === 'bb-hotdeals') { loadHdHistory(); loadHdSyncStatus(); loadHdSaved(); } else { hdStopSyncPoll(); }
    if (tab.dataset.panel === 'bb-admin') { loadAdminTable(); }
  });
});
// Delegate is the default active tab on page load -- the click listener
// above only fires on an actual click, so its history needs an explicit
// initial load the same way the other tabs get one when first switched to.
loadDrafts('delegate', 'bb-delegate-history');

// ── Delegate ─────────────────────────────────────────────────────────────────
async function bbPrep(radiusTierIndex) {
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
      body: JSON.stringify({address: address, radius_tier_index: radiusTierIndex ?? null}),
    });
    const data = await r.json();
    btn.disabled = false; btn.textContent = 'Prep this appointment';
    if (!r.ok || data.ok === false) {
      status.textContent = 'Error: ' + (data.error || data.detail || 'Unknown error');
      status.className = 'send-status err';
      return;
    }
    renderPacket(data);
    loadDrafts('delegate', 'bb-delegate-history', true);
  } catch (e) {
    btn.disabled = false; btn.textContent = 'Prep this appointment';
    status.textContent = 'Network error — could not reach the server.';
    status.className = 'send-status err';
  }
}

function bbAdjustRadius(delta) {
  const next = (bbLastRadiusTierIndex ?? 0) + delta;
  if (next < 0 || next > bbLastMaxRadiusTierIndex) return;
  bbPrep(next);
}

// ── Places autocomplete (address field) ─────────────────────────────────────
// Callback fired by the Maps JS API <script> tag's ?callback= param once it's
// loaded — reuses the same API key already configured for Google Business
// Profile matching (lib/google_business.php), no new key needed.
// google.maps.places.Autocomplete silently returns zero predictions on this
// key/project (confirmed live 2026-08-13 via the browser console: "As of
// March 1st, 2025, google.maps.places.Autocomplete is not available to new
// customers" -- Google's own replacement is PlaceAutocompleteElement, a
// self-contained custom element rather than an enhancement of a plain
// <input>. bbPrep() and everything else still reads address text from the
// original #bb-address input, so that input stays in the DOM (hidden) as
// the single source of truth -- the new element's shadow-DOM input is kept
// synced into it on every keystroke (not just on suggestion-select), since
// not every address (new construction, rural) will be in Google's
// suggestions and agents need to be able to type one in free-hand exactly
// like before. Wrapped defensively: if this fast-evolving API's shape ever
// shifts again, construction/access failures fail soft back to the plain,
// fully-usable text input rather than a half-broken page.
function bbGmapsReady() {
  const legacyInput = document.getElementById('bb-address');
  if (!legacyInput || !window.google || !google.maps || !google.maps.places) return;
  if (!google.maps.places.PlaceAutocompleteElement) return;

  try {
    const ac = new google.maps.places.PlaceAutocompleteElement();
    ac.id = 'bb-address-ac';
    ac.style.display = 'block';
    ac.style.width = '100%';
    if (legacyInput.placeholder) ac.setAttribute('placeholder', legacyInput.placeholder);
    legacyInput.insertAdjacentElement('afterend', ac);
    legacyInput.style.display = 'none';

    const wireShadowSync = () => {
      const innerInput = ac.shadowRoot && ac.shadowRoot.querySelector('input');
      if (innerInput) innerInput.addEventListener('input', () => { legacyInput.value = innerInput.value; });
    };
    requestAnimationFrame(wireShadowSync);

    ac.addEventListener('gmp-select', async (event) => {
      try {
        const place = event.placePrediction.toPlace();
        await place.fetchFields({fields: ['formattedAddress']});
        if (place.formattedAddress) legacyInput.value = place.formattedAddress;
      } catch (e) { /* selection didn't resolve -- legacy input keeps whatever text is already there */ }
    });
  } catch (e) {
    // PlaceAutocompleteElement unavailable/failed to construct -- leave the
    // plain input visible and fully usable, just without suggestions.
  }
}

// ── Delegate packet + comp map/list/CMA ─────────────────────────────────────
let bbLastComps = [];
let bbLastSubject = null;
let bbLastAddress = '';
let bbCompMap = null;
let bbLastRadiusTierIndex = null;
let bbLastMaxRadiusTierIndex = 0;

function renderPacket(data) {
  const el = document.getElementById('bb-packet');
  bbLastComps = data.comps || [];
  bbLastSubject = data.subject || null;
  bbLastAddress = data.address || '';
  bbLastRadiusTierIndex = data.radiusTierIndex ?? null;
  bbLastMaxRadiusTierIndex = data.maxRadiusTierIndex ?? 0;

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
      <div class="comp-toolbar" style="background:#f9fdf5;border:1px solid #d4edab;border-radius:8px;padding:10px 14px">
        <span class="comp-count" id="bb-comp-selected-count"></span>
        <span style="font-weight:800;font-size:14px;color:#5b8e0d" id="bb-selected-median"></span>
      </div>
      <div id="bb-comp-map" class="comp-map"></div>
      <div id="bb-comp-list" class="comp-list"></div>
      <div class="comp-toolbar">
        <div style="display:flex;align-items:center;gap:8px">
          <span class="comp-count">${data.radiusMiles ? 'Search radius: ~' + data.radiusMiles + ' mi' : ''}</span>
          <button class="btn-secondary" id="bb-radius-narrower" onclick="bbAdjustRadius(-1)" ${(bbLastRadiusTierIndex ?? 0) <= 0 ? 'disabled' : ''}>&larr; Narrower</button>
          <button class="btn-secondary" id="bb-radius-wider" onclick="bbAdjustRadius(1)" ${(bbLastRadiusTierIndex ?? 0) >= bbLastMaxRadiusTierIndex ? 'disabled' : ''}>Wider &rarr;</button>
        </div>
        <div style="display:flex;align-items:center;gap:12px">
          <span class="send-status" id="bb-cma-status"></span>
          <button class="btn-primary" id="bb-cma-btn" onclick="bbGenerateCma()">Download CMA PDF</button>
        </div>
      </div>
    </div>`;

  renderCompList();
  renderCompMap();
}

function compPriceLabel(c) {
  if (c.status === 'Closed' && c.closePrice) return 'Sold $' + Math.round(c.closePrice).toLocaleString();
  if (c.listPrice) return (c.status === 'ActiveUnderContract' ? 'Under Contract' : c.status) + ' $' + Math.round(c.listPrice).toLocaleString();
  return c.status || '';
}

function renderCompList() {
  const el = document.getElementById('bb-comp-list');
  if (!el) return;
  if (!bbLastComps.length) { el.innerHTML = '<p class="empty-note">No comps to select.</p>'; updateCompSelectedCount(); return; }
  el.innerHTML = bbLastComps.map((c, i) => `
    <label class="comp-row">
      <span class="comp-num">${i + 1}</span>
      <input type="checkbox" class="bb-comp-cb" value="${i}" checked onchange="onCompToggle(${i})">
      <div>
        <div class="comp-addr">${bbEscape(c.address)}${c.city ? ', ' + bbEscape(c.city) : ''}</div>
        <div class="comp-spec">${c.beds ?? '—'} bd / ${c.baths ?? '—'} ba &bull; ${c.sqft ? c.sqft.toLocaleString() + ' sqft' : '—'}${c.lotAcres ? ' &bull; ' + c.lotAcres + ' ac lot' : ''}</div>
      </div>
      <div class="comp-price">${bbEscape(compPriceLabel(c))}</div>
    </label>`).join('');
  updateCompSelectedCount();
}

function gmHoverCardHtml(label, p) {
  const spec = `${p.beds ?? '—'} bd / ${p.baths ?? '—'} ba &bull; ${p.sqft ? p.sqft.toLocaleString() + ' sqft' : '—'}${p.lotAcres ? ' &bull; ' + p.lotAcres + ' ac lot' : ''}`;
  const img = p.photoUrl ? `<img src="${bbEscape(p.photoUrl)}" alt="">` : '';
  return `<div class="gm-hover-card">${img}<div class="gm-addr">${bbEscape(label)}</div><div class="gm-spec">${spec}</div></div>`;
}

function updateCompSelectedCount() {
  const checked = Array.from(document.querySelectorAll('.bb-comp-cb:checked')).map(cb => bbLastComps[parseInt(cb.value, 10)]);
  const span = document.getElementById('bb-comp-selected-count');
  if (span) span.textContent = checked.length + ' of ' + bbLastComps.length + ' comps selected';

  // Recompute the median from ONLY the checked comps -- unchecking the
  // oceanfront-mismatch comps (or any others) should visibly change the
  // number being discussed, not just the CMA PDF's contents later.
  const pps = checked.map(c => {
    const price = c.status === 'Closed' ? c.closePrice : c.listPrice;
    return (price && c.sqft) ? price / c.sqft : null;
  }).filter(v => v !== null).sort((a, b) => a - b);
  const medianEl = document.getElementById('bb-selected-median');
  if (medianEl) {
    if (pps.length) {
      const mid = Math.floor(pps.length / 2);
      const median = pps.length % 2 ? pps[mid] : (pps[mid - 1] + pps[mid]) / 2;
      medianEl.textContent = 'Selected median: $' + Math.round(median).toLocaleString() + '/sqft';
    } else {
      medianEl.textContent = '';
    }
  }

  const btn = document.getElementById('bb-cma-btn');
  if (btn) btn.disabled = checked.length === 0;
}

function onCompToggle(i) {
  updateCompSelectedCount();
  highlightMapMarker(i);
}

function renderCompMap() {
  const mapEl = document.getElementById('bb-comp-map');
  if (!mapEl) return;
  if (!window.google || !google.maps) { mapEl.outerHTML = '<p class="empty-note">Map unavailable (Google Maps not configured).</p>'; return; }

  const pts = bbLastComps.filter(c => c.lat && c.lon);
  const center = (bbLastSubject && bbLastSubject.lat && bbLastSubject.lon)
    ? {lat: bbLastSubject.lat, lng: bbLastSubject.lon}
    : (pts[0] ? {lat: pts[0].lat, lng: pts[0].lon} : {lat: 33.69, lng: -78.89});

  bbCompMap = new google.maps.Map(mapEl, {center, zoom: 13});
  bbCompMap.bbMarkers = [];
  const infoWindow = new google.maps.InfoWindow();

  if (bbLastSubject && bbLastSubject.lat && bbLastSubject.lon) {
    const subjectMarker = new google.maps.Marker({
      position: {lat: bbLastSubject.lat, lng: bbLastSubject.lon}, map: bbCompMap,
      title: 'Subject: ' + (bbLastSubject.address || bbLastAddress),
      icon: {path: google.maps.SymbolPath.CIRCLE, scale: 9, fillColor: '#82C112', fillOpacity: 1, strokeColor: '#1a1a1a', strokeWeight: 2},
      zIndex: 999,
    });
    subjectMarker.addListener('mouseover', () => {
      infoWindow.setContent(gmHoverCardHtml('Subject: ' + (bbLastSubject.address || bbLastAddress), bbLastSubject));
      infoWindow.open(bbCompMap, subjectMarker);
    });
    subjectMarker.addListener('mouseout', () => infoWindow.close());
  }
  bbLastComps.forEach((c, i) => {
    if (!c.lat || !c.lon) { bbCompMap.bbMarkers[i] = null; return; }
    const marker = new google.maps.Marker({
      position: {lat: c.lat, lng: c.lon}, map: bbCompMap,
      title: c.address + ' — ' + compPriceLabel(c),
      label: {text: String(i + 1), color: '#fff', fontSize: '10px', fontWeight: '700'},
    });
    marker.addListener('mouseover', () => {
      infoWindow.setContent(gmHoverCardHtml('#' + (i + 1) + ' — ' + c.address, c));
      infoWindow.open(bbCompMap, marker);
    });
    marker.addListener('mouseout', () => infoWindow.close());
    bbCompMap.bbMarkers[i] = marker;
  });
}

function highlightMapMarker(i) {
  if (!bbCompMap || !bbCompMap.bbMarkers[i]) return;
  const marker = bbCompMap.bbMarkers[i];
  const checked = document.querySelector('.bb-comp-cb[value="' + i + '"]').checked;
  marker.setOpacity(checked ? 1 : 0.3);
}

async function bbGenerateCma() {
  const btn = document.getElementById('bb-cma-btn');
  const status = document.getElementById('bb-cma-status');
  const compKeys = Array.from(document.querySelectorAll('.bb-comp-cb:checked'))
    .map(cb => bbLastComps[parseInt(cb.value, 10)].listingKey)
    .filter(Boolean);
  if (!compKeys.length) { status.textContent = 'Select at least one comp first.'; status.className = 'send-status err'; return; }

  btn.disabled = true; btn.textContent = 'Generating…';
  status.textContent = ''; status.className = 'send-status';
  try {
    const r = await fetch('api/buyback_cma_pdf.php', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        address: bbLastAddress,
        subject_listing_key: bbLastSubject ? bbLastSubject.listingKey : null,
        comp_listing_keys: compKeys,
      }),
    });
    if (!r.ok || (r.headers.get('Content-Type') || '').indexOf('application/pdf') === -1) {
      const data = await r.json().catch(() => ({}));
      status.textContent = 'Error: ' + (data.error || 'Could not generate the CMA.');
      status.className = 'send-status err';
      btn.disabled = false; btn.textContent = 'Download CMA PDF';
      return;
    }
    const blob = await r.blob();
    const disposition = r.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="([^"]+)"/);
    const filename = match ? match[1] : 'CMA.pdf';
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
    btn.disabled = false; btn.textContent = 'Download CMA PDF';
  } catch (e) {
    status.textContent = 'Network error.'; status.className = 'send-status err';
    btn.disabled = false; btn.textContent = 'Download CMA PDF';
  }
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

// ── Hot Deals (agent-scoped: own FUB leads only) ───────────────────────────
function hdMoney(n) {
  if (n === null || n === undefined) return '—';
  return '$' + Math.round(Number(n)).toLocaleString();
}
function hdPct(n) {
  if (n === null || n === undefined) return null;
  return (n >= 0 ? '+' : '') + Math.round(n * 100) + '%';
}

async function hdSearch() {
  const btn = document.getElementById('hd-search-btn');
  const status = document.getElementById('hd-search-status');
  const city = document.getElementById('hd-city').value.trim();
  if (!city) { status.textContent = 'City is required.'; status.className = 'send-status err'; return; }

  const body = {
    city: city,
    property_sub_type: document.getElementById('hd-sub-type').value,
    min_beds: document.getElementById('hd-min-beds').value || null,
    max_beds: document.getElementById('hd-max-beds').value || null,
    min_baths: document.getElementById('hd-min-baths').value || null,
    max_baths: document.getElementById('hd-max-baths').value || null,
    min_price: document.getElementById('hd-min-price').value || null,
    max_price: document.getElementById('hd-max-price').value || null,
    frontage: document.getElementById('hd-oceanfront').checked ? 'oceanfront' : null,
    limit: 10,
  };

  btn.disabled = true; btn.textContent = 'Searching…';
  status.textContent = ''; status.className = 'send-status';
  document.getElementById('hd-results').innerHTML = '';

  // This searches an agent's full lead history (not a fast recent sample),
  // paced to respect FUB's rate limit -- a large contact list can take up
  // to 1-2 minutes. Without live feedback that read as frozen/broken in
  // testing (confirmed 2026-08-10: one real search took 93s).
  const startedAt = Date.now();
  const tick = () => {
    const secs = Math.round((Date.now() - startedAt) / 1000);
    status.textContent = secs < 8
      ? 'Searching your leads…'
      : `Still searching your leads… (${secs}s — large contact lists can take up to 2 minutes)`;
    status.className = 'send-status';
  };
  tick();
  const timer = setInterval(tick, 1000);

  try {
    const r = await fetch('api/buyback_hotdeals_preview.php', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(body),
    });
    const data = await r.json();
    clearInterval(timer);
    btn.disabled = false; btn.textContent = 'Find Deals';
    if (!r.ok || data.ok === false) {
      status.textContent = 'Error: ' + (data.error || data.detail || 'Unknown error');
      status.className = 'send-status err';
      return;
    }
    renderHdResults(data.candidates || []);
    status.textContent = '';
    if (!data.fub_configured) status.textContent = 'Note: FUB lead matching is not configured.';
    if (!data.str_data_configured) status.textContent += (status.textContent ? ' ' : '') + 'Note: rental-income data is not configured — ranked by price vs. comps only.';
    // The preview response already carries this agent's lead-interest sync
    // status (see innovate.buyback_lead_sync_state on the CRM side) -- reuse
    // it directly instead of a second round-trip to sync-status.php.
    renderHdSyncStatus({status: data.leads_sync_status, last_synced_at: data.leads_last_synced_at});
  } catch (e) {
    clearInterval(timer);
    btn.disabled = false; btn.textContent = 'Find Deals';
    status.textContent = 'Network error — could not reach the server.';
    status.className = 'send-status err';
  }
}

// ── Hot Deals lead-interest sync status ─────────────────────────────────────
// Matching leads reads from a per-agent cache (innovate.buyback_lead_
// interest_cache) that's synced in the background rather than crawled live
// on every search -- this surfaces whether that cache has ever been synced,
// is currently syncing, or was last synced, with a manual refresh option.
let hdSyncPollTimer = null;

function hdRelativeTime(iso) {
  if (!iso) return null;
  const mins = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
  if (mins < 1) return 'just now';
  if (mins < 60) return mins + ' min ago';
  const hours = Math.round(mins / 60);
  if (hours < 24) return hours + (hours === 1 ? ' hour ago' : ' hours ago');
  const days = Math.round(hours / 24);
  return days + (days === 1 ? ' day ago' : ' days ago');
}

function renderHdSyncStatus(data) {
  const el = document.getElementById('hd-sync-status');
  if (!el) return;
  const status = data.status;
  const lastSynced = data.last_synced_at;
  el.className = 'hd-sync-status';

  if (status === 'running' || status === 'started') {
    el.classList.add('syncing');
    el.innerHTML = 'Gathering your lead activity from Follow Up Boss (can take a few minutes for a large book) — matches will improve once this finishes.';
    hdStartSyncPoll();
  } else if (status === 'error') {
    el.classList.add('err');
    el.innerHTML = 'Last sync failed' + (lastSynced ? ' (last good sync ' + hdRelativeTime(lastSynced) + ')' : '') + '. <a onclick="hdRefreshLeads()">Try again</a>';
    hdStopSyncPoll();
  } else if (status === 'never_synced' || !status) {
    el.innerHTML = 'Your lead activity hasn\'t been synced yet — the first search will start this automatically, or <a onclick="hdRefreshLeads()">sync now</a>.';
    hdStopSyncPoll();
  } else {
    el.innerHTML = 'Leads last synced ' + (hdRelativeTime(lastSynced) || 'recently') + '. <a onclick="hdRefreshLeads()">Refresh</a>';
    hdStopSyncPoll();
  }
}

function hdStartSyncPoll() {
  if (hdSyncPollTimer) return;
  hdSyncPollTimer = setInterval(async () => {
    try {
      const r = await fetch('api/buyback_hotdeals_sync_status.php', {credentials: 'same-origin'});
      const data = await r.json();
      if (data.ok !== false) renderHdSyncStatus(data);
    } catch (e) { /* transient network blip -- keep polling silently */ }
  }, 15000);
}

function hdStopSyncPoll() {
  if (hdSyncPollTimer) { clearInterval(hdSyncPollTimer); hdSyncPollTimer = null; }
}

async function loadHdSyncStatus() {
  try {
    const r = await fetch('api/buyback_hotdeals_sync_status.php', {credentials: 'same-origin'});
    const data = await r.json();
    if (data.ok !== false) renderHdSyncStatus(data);
  } catch (e) { /* non-critical status line -- fail silently */ }
}

async function hdRefreshLeads() {
  const el = document.getElementById('hd-sync-status');
  try {
    const r = await fetch('api/buyback_hotdeals_sync.php', {method: 'POST', credentials: 'same-origin'});
    const data = await r.json();
    if (data.ok === false) {
      if (el) { el.className = 'hd-sync-status err'; el.textContent = 'Error: ' + (data.error || 'could not start sync'); }
      return;
    }
    if (data.status === 'recently_synced') {
      renderHdSyncStatus({status: 'done', last_synced_at: data.last_synced_at});
      return;
    }
    renderHdSyncStatus({status: data.status === 'already_running' ? 'running' : 'started'});
  } catch (e) {
    if (el) { el.className = 'hd-sync-status err'; el.textContent = 'Network error — could not reach the server.'; }
  }
}

let hdLastCandidates = [];

function renderHdResults(candidates) {
  const container = document.getElementById('hd-results');
  hdLastCandidates = candidates;
  if (!candidates.length) {
    container.innerHTML = '<div class="empty-note">No active listings matched this spec (within your own assigned leads).</div>';
    return;
  }
  container.innerHTML = candidates.map((c, idx) => {
    const badges = [];
    if (c.value_score !== null && c.value_score !== undefined) {
      const pct = hdPct(c.value_score);
      badges.push(`<span class="badge ${c.value_score >= 0 ? 'value-good' : 'value-bad'}">${pct} vs. comps</span>`);
    }
    if (c.net_roi !== null && c.net_roi !== undefined) {
      badges.push(`<span class="badge roi">${hdPct(c.net_roi)} net ROI</span>`);
    } else if (c.roi_note) {
      badges.push(`<span class="badge note">Rental data not available</span>`);
    }

    const leads = c.matching_leads || [];
    let leadsHtml = '';
    if (leads.length) {
      const rows = leads.map(l =>
        `<div class="lead-row"><label><input type="checkbox" class="hd-lead-cb" data-deal="${idx}" data-person-id="${l.person_id}" checked> ${bbEscape(l.name || l.email)} (${bbEscape(l.email)})</label></div>`
      ).join('');
      leadsHtml = `
        <div class="leads-box">
          <div class="section-label">Matching leads (${leads.length})</div>
          ${rows}
          <div class="deal-send-bar">
            <button class="btn-secondary" onclick="hdSend(${idx})">Send to selected</button>
            <button class="btn-secondary" onclick="hdSaveDeal(${idx})">Save deal</button>
            <span class="send-status" id="hd-send-status-${idx}"></span>
          </div>
        </div>`;
    } else {
      leadsHtml = `<div class="leads-box"><span class="empty-note" style="padding:4px 0">No leads have viewed a similar property recently.</span></div>`;
    }

    return `<div class="deal-card" data-listing-key="${bbEscape(c.listing_key)}" data-rationale="${bbEscape(c.rationale || '')}">
      <div class="deal-top">
        <div>
          <div class="deal-price">${hdMoney(c.list_price)}</div>
          <div class="deal-addr">${bbEscape(c.unparsed_address)} · ${c.bedrooms_total}bd/${c.bathrooms_full}ba · ${c.living_area_sqft ? Math.round(c.living_area_sqft).toLocaleString() + ' sqft' : ''}</div>
        </div>
        <div class="deal-badges">${badges.join('')}</div>
      </div>
      <div class="deal-detail">
        ${c.subdivision_name ? bbEscape(c.subdivision_name) + ' · ' : ''}${c.days_on_market != null ? c.days_on_market + ' days on market' : ''}
        ${c.rental_estimate ? ` · Est. annual rental revenue: ${hdMoney(c.rental_estimate.annual_revenue)}` : ''}
        ${c.disclosed_monthly_hoa ? ` · HOA: ${hdMoney(c.disclosed_monthly_hoa)}/mo${c.hoa_source === 'mls' ? ' (from MLS data)' : ' (mentioned in remarks — verify)'}` : ' · HOA not disclosed — verify before pitching'}
      </div>
      <div class="hd-share">
        <button class="btn-secondary" onclick="hdShareText(${idx})" id="hd-share-btn-${idx}">Get Link &amp; Email Text</button>
        <span class="send-status" id="hd-share-status-${idx}"></span>
        <div class="hd-share-box" id="hd-share-box-${idx}" hidden>
          <label class="section-label">Shareable link (credits you specifically)</label>
          <div class="field-row" style="align-items:center;gap:8px;margin-bottom:8px">
            <input type="text" readonly class="hd-share-link" onclick="this.select()">
            <button class="btn-secondary" onclick="hdCopyShare(${idx}, 'link')">Copy Link</button>
          </div>
          <label class="section-label">Email text (edit freely — send from your own inbox)</label>
          <textarea readonly rows="10" class="hd-share-textarea" onclick="this.select()"></textarea>
          <button class="btn-secondary" style="margin-top:6px" onclick="hdCopyShare(${idx}, 'text')">Copy Email Text</button>
        </div>
      </div>
      ${leadsHtml}
    </div>`;
  }).join('');
}

async function hdSend(idx) {
  const card = document.querySelectorAll('.deal-card')[idx];
  const listingKey = card.dataset.listingKey;
  const rationale = card.dataset.rationale;
  const personIds = Array.from(card.querySelectorAll(`.hd-lead-cb[data-deal="${idx}"]:checked`))
    .map(cb => parseInt(cb.dataset.personId, 10));
  const statusEl = document.getElementById('hd-send-status-' + idx);
  if (!personIds.length) {
    statusEl.textContent = 'Select at least one lead.';
    statusEl.className = 'send-status err';
    return;
  }
  if (!confirm(`Send the hot-deal email for ${listingKey} to ${personIds.length} lead(s)? This sends a real email and cannot be undone.`)) return;

  statusEl.textContent = 'Sending…'; statusEl.className = 'send-status';
  try {
    const r = await fetch('api/buyback_hotdeals_send.php', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({listing_key: listingKey, person_ids: personIds, rationale: rationale}),
    });
    const data = await r.json();
    if (!r.ok || data.ok === false) {
      statusEl.textContent = 'Error: ' + (data.error || data.detail || 'Unknown error');
      statusEl.className = 'send-status err';
      return;
    }
    statusEl.textContent = `Sent to ${data.sent.length} lead(s)` + (data.errors.length ? `, ${data.errors.length} failed` : '') + '.';
    statusEl.className = 'send-status ok';
    loadHdHistory(true);
  } catch (e) {
    statusEl.textContent = 'Network error — could not reach the server.';
    statusEl.className = 'send-status err';
  }
}

// ── Saved Deals ──────────────────────────────────────────────────────────────
// A search result only lived on screen until you navigated away or re-ran
// the search -- this persists a listing + the leads you had checked so it's
// waiting the next time you open the tool, instead of needing a fresh search.
// Sending a saved deal still goes through the same hotdeals/send.php path as
// a live result (with saved_deal_id attached so the CRM marks it sent
// afterward) -- nothing about a save is trusted at send time; the CRM
// re-verifies every recipient live against FUB regardless.
async function hdSaveDeal(idx) {
  const card = document.querySelectorAll('.deal-card')[idx];
  const listingKey = card.dataset.listingKey;
  const rationale = card.dataset.rationale;
  const candidate = hdLastCandidates[idx];
  const checkedIds = Array.from(card.querySelectorAll(`.hd-lead-cb[data-deal="${idx}"]:checked`))
    .map(cb => parseInt(cb.dataset.personId, 10));
  const selectedLeads = (candidate.matching_leads || []).filter(l => checkedIds.includes(l.person_id));
  const statusEl = document.getElementById('hd-send-status-' + idx);
  if (!selectedLeads.length) {
    statusEl.textContent = 'Select at least one lead to save.';
    statusEl.className = 'send-status err';
    return;
  }
  // matching_leads/_has_lead_interest are dropped from the stored snapshot --
  // selected_leads above already carries what's needed to render/send this
  // saved deal, so keeping the full match list too would just be dead weight.
  const snapshot = Object.assign({}, candidate);
  delete snapshot.matching_leads;
  delete snapshot._has_lead_interest;

  statusEl.textContent = 'Saving…'; statusEl.className = 'send-status';
  try {
    const r = await fetch('api/buyback_hotdeals_save.php', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({listing_key: listingKey, listing_snapshot: snapshot, selected_leads: selectedLeads, rationale: rationale}),
    });
    const data = await r.json();
    if (!r.ok || data.ok === false) {
      statusEl.textContent = 'Error: ' + (data.error || data.detail || 'could not save');
      statusEl.className = 'send-status err';
      return;
    }
    statusEl.textContent = 'Saved.';
    statusEl.className = 'send-status ok';
    loadHdSaved(true);
  } catch (e) {
    statusEl.textContent = 'Network error — could not reach the server.';
    statusEl.className = 'send-status err';
  }
}

let hdSavedItems = [];

async function loadHdSaved(force) {
  const el = document.getElementById('hd-saved');
  if (!force && el.dataset.loaded === '1') return;
  el.dataset.loaded = '1';
  try {
    const r = await fetch('api/buyback_hotdeals_saved.php', {credentials: 'same-origin'});
    const data = await r.json();
    if (!r.ok || data.ok === false) {
      el.innerHTML = '<div class="empty-note">Could not load saved deals.</div>';
      return;
    }
    hdSavedItems = data.saved || [];
    renderHdSaved();
  } catch (e) {
    el.innerHTML = '<div class="empty-note">Could not load saved deals.</div>';
  }
}

function renderHdSaved() {
  const el = document.getElementById('hd-saved');
  if (!hdSavedItems.length) {
    el.innerHTML = '<div class="empty-note">No saved deals yet — use "Save deal" on a search result above.</div>';
    return;
  }
  el.innerHTML = hdSavedItems.map((item, i) => {
    const c = item.listing || {};
    const badges = [];
    if (c.value_score !== null && c.value_score !== undefined) {
      badges.push(`<span class="badge ${c.value_score >= 0 ? 'value-good' : 'value-bad'}">${hdPct(c.value_score)} vs. comps</span>`);
    }
    if (c.net_roi !== null && c.net_roi !== undefined) {
      badges.push(`<span class="badge roi">${hdPct(c.net_roi)} net ROI</span>`);
    }
    const leads = item.selected_leads || [];
    const rows = leads.map(l =>
      `<div class="lead-row"><label><input type="checkbox" class="hd-saved-lead-cb" data-saved="${i}" data-person-id="${l.person_id}" checked> ${bbEscape(l.name || l.email)} (${bbEscape(l.email)})</label></div>`
    ).join('');
    return `<div class="deal-card" data-saved-id="${bbEscape(item.id)}">
      <div class="deal-top">
        <div>
          <div class="deal-price">${hdMoney(c.list_price)}</div>
          <div class="deal-addr">${bbEscape(c.unparsed_address)} · ${c.bedrooms_total}bd/${c.bathrooms_full}ba · ${c.living_area_sqft ? Math.round(c.living_area_sqft).toLocaleString() + ' sqft' : ''}</div>
        </div>
        <div class="deal-badges">${badges.join('')}</div>
      </div>
      <div class="leads-box">
        <div class="section-label">Selected leads (${leads.length})</div>
        ${rows}
        <div class="deal-send-bar">
          <button class="btn-secondary" onclick="hdSendSaved(${i})">Send to selected</button>
          <button class="btn-danger" onclick="hdDeleteSaved(${i})">Delete</button>
          <span class="send-status" id="hd-saved-status-${i}"></span>
        </div>
      </div>
    </div>`;
  }).join('');
}

async function hdSendSaved(i) {
  const item = hdSavedItems[i];
  const card = document.querySelectorAll('[data-saved-id]')[i];
  const personIds = Array.from(card.querySelectorAll(`.hd-saved-lead-cb[data-saved="${i}"]:checked`))
    .map(cb => parseInt(cb.dataset.personId, 10));
  const statusEl = document.getElementById('hd-saved-status-' + i);
  if (!personIds.length) {
    statusEl.textContent = 'Select at least one lead.';
    statusEl.className = 'send-status err';
    return;
  }
  if (!confirm(`Send the hot-deal email for ${item.listing_key} to ${personIds.length} lead(s)? This sends a real email and cannot be undone.`)) return;

  statusEl.textContent = 'Sending…'; statusEl.className = 'send-status';
  try {
    const r = await fetch('api/buyback_hotdeals_send.php', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({listing_key: item.listing_key, person_ids: personIds, rationale: item.rationale, saved_deal_id: item.id}),
    });
    const data = await r.json();
    if (!r.ok || data.ok === false) {
      statusEl.textContent = 'Error: ' + (data.error || data.detail || 'Unknown error');
      statusEl.className = 'send-status err';
      return;
    }
    statusEl.textContent = `Sent to ${data.sent.length} lead(s)` + (data.errors.length ? `, ${data.errors.length} failed` : '') + '.';
    statusEl.className = 'send-status ok';
    loadHdHistory(true);
    loadHdSaved(true);
  } catch (e) {
    statusEl.textContent = 'Network error — could not reach the server.';
    statusEl.className = 'send-status err';
  }
}

async function hdDeleteSaved(i) {
  const item = hdSavedItems[i];
  if (!confirm('Delete this saved deal? This cannot be undone.')) return;
  try {
    const r = await fetch('api/buyback_hotdeals_saved_delete.php?saved_id=' + encodeURIComponent(item.id), {
      method: 'POST', credentials: 'same-origin',
    });
    const data = await r.json();
    if (!r.ok || data.ok === false) {
      alert('Error: ' + (data.error || 'could not delete'));
      return;
    }
    loadHdSaved(true);
  } catch (e) {
    alert('Network error — could not reach the server.');
  }
}

// A link + copy-pasteable email for prospects who aren't a tracked FUB
// contact at all (e.g. someone met at an open house) -- sent by the agent
// from their own inbox, entirely outside FUB and the automated campaign
// send. The link credits that specific agent's own site page when they
// have one set up, not the generic brokerage "find an agent" page.
async function hdShareText(idx) {
  const card = document.querySelectorAll('.deal-card')[idx];
  const listingKey = card.dataset.listingKey;
  const btn = document.getElementById('hd-share-btn-' + idx);
  const status = document.getElementById('hd-share-status-' + idx);
  const box = document.getElementById('hd-share-box-' + idx);

  btn.disabled = true; btn.textContent = 'Loading…';
  status.textContent = ''; status.className = 'send-status';

  try {
    const r = await fetch('api/buyback_hotdeals_share.php', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({listing_key: listingKey}),
    });
    const data = await r.json();
    btn.disabled = false; btn.textContent = 'Get Link & Email Text';
    if (!r.ok || data.ok === false) {
      status.textContent = 'Error: ' + (data.error || data.detail || 'Unknown error');
      status.className = 'send-status err';
      return;
    }
    box.querySelector('.hd-share-link').value = data.link;
    box.querySelector('.hd-share-textarea').value = `Subject: ${data.subject}\n\n${data.body_text}`;
    box.hidden = false;
  } catch (e) {
    btn.disabled = false; btn.textContent = 'Get Link & Email Text';
    status.textContent = 'Network error — could not reach the server.';
    status.className = 'send-status err';
  }
}

function hdCopyShare(idx, which) {
  const box = document.getElementById('hd-share-box-' + idx);
  const el = box.querySelector(which === 'link' ? '.hd-share-link' : '.hd-share-textarea');
  el.select();
  navigator.clipboard.writeText(el.value).then(() => {
    const status = document.getElementById('hd-share-status-' + idx);
    status.textContent = 'Copied to clipboard.';
    status.className = 'send-status ok';
  }).catch(() => {
    const status = document.getElementById('hd-share-status-' + idx);
    status.textContent = 'Could not copy — select the text manually.';
    status.className = 'send-status err';
  });
}

async function loadHdHistory(force) {
  const el = document.getElementById('hd-history');
  if (!force && el.dataset.loaded === '1') return;
  el.dataset.loaded = '1';
  try {
    const r = await fetch('api/buyback_hotdeals_history.php', {credentials: 'same-origin'});
    const data = await r.json();
    if (!r.ok || data.ok === false) {
      el.innerHTML = '<div class="empty-note">Could not load history.</div>';
      return;
    }
    const rows = data.rows || [];
    if (!rows.length) {
      el.innerHTML = '<div class="empty-note">No hot-deal emails sent yet.</div>';
      return;
    }
    el.innerHTML = `<table class="hist-table">
      <thead><tr><th></th><th>Sent</th><th>Listing</th><th>Sent</th><th>Opened</th><th>Clicked</th></tr></thead>
      <tbody>${rows.map((c, i) => `
        <tr>
          <td><span class="hist-toggle" onclick="hdToggleHist(${i})">▸ details</span></td>
          <td>${c.createdAt ? new Date(c.createdAt).toLocaleDateString() : '—'}</td>
          <td>${bbEscape(c.listingKey || c.name)}</td>
          <td>${c.stats.sent}</td>
          <td>${c.stats.opened}</td>
          <td>${c.stats.clicked}</td>
        </tr>
        <tr class="hist-recipients" id="hd-hist-recip-${i}"><td colspan="6">
          ${(c.recipients || []).map(rp => `${bbEscape(rp.email)} — ${bbEscape(rp.status)}, ${rp.opens} open(s), ${rp.clicks} click(s)`).join('<br>') || 'No recipients.'}
        </td></tr>
      `).join('')}</tbody>
    </table>`;
  } catch (e) {
    el.innerHTML = '<div class="empty-note">Could not load history.</div>';
  }
}
function hdToggleHist(i) {
  document.getElementById('hd-hist-recip-' + i).classList.toggle('open');
}

// ── Shared draft queue (Automate + Eliminate + Delegate's saved searches) ──
function bbToggleDraftCard(topEl) {
  const card = topEl.closest('.draft-card');
  const details = card.querySelector('.draft-details');
  details.hidden = !details.hidden;
  card.classList.toggle('expanded', !details.hidden);
  // A Google Map initialized while its container is still `hidden` renders
  // blank (zero-size at init time) -- so the map is built lazily on first
  // expand instead of at insertion time, once the div actually has size.
  if (!details.hidden) {
    const mapEl = details.querySelector('.comp-map[data-draft-id]');
    if (mapEl && !mapEl.dataset.rendered) {
      mapEl.dataset.rendered = '1';
      renderSavedCompMap(mapEl, bbSavedSnapshots[mapEl.dataset.draftId]);
    }
  }
}

// Renders the comps a saved Delegate search actually used, from the stored
// snapshot -- not a live re-query, so it still matches what the agent saw
// even if a comp has since sold/gone under contract/been delisted. Reuses
// compPriceLabel(), which only cares about status/closePrice/listPrice --
// the same shape _comp_out() already produces server-side.
// Comp-level detail (address/price/lat-lon) wasn't always saved into
// context_snapshot -- older searches only have aggregate stats, so there's
// nothing here to list or plot on a map.
const bbSavedSnapshots = {};

function renderSavedComps(d, agentType) {
  const snap = d.contextSnapshot;
  if (!snap || !Array.isArray(snap.comps) || !snap.comps.length) {
    // Automate/Eliminate snapshots never carry a comps array by design --
    // only note the absence for Delegate, where a map/list is expected.
    return agentType === 'delegate'
      ? '<div class="empty-note" style="margin-top:8px">Comp details weren\'t saved for this search — only the summary above is available.</div>'
      : '';
  }
  bbSavedSnapshots[d.id] = snap;
  const subjectLine = snap.subject ? `<div class="draft-meta" style="margin:8px 0 4px">Subject: ${bbEscape(snap.subject.address || d.targetLabel)}</div>` : '';
  const mapDiv = snap.comps.some(c => c.lat && c.lon)
    ? `<div id="saved-comp-map-${bbEscape(d.id)}" class="comp-map" data-draft-id="${bbEscape(d.id)}"></div>`
    : '';
  return subjectLine + mapDiv + '<div class="saved-comp-list">' + snap.comps.map((c, i) => `
    <div class="saved-comp-row">
      <span class="comp-num">${i + 1}</span>
      <div>
        <div class="comp-addr">${bbEscape(c.address)}${c.city ? ', ' + bbEscape(c.city) : ''}</div>
        <div class="comp-spec">${c.beds ?? '—'} bd / ${c.baths ?? '—'} ba &bull; ${c.sqft ? c.sqft.toLocaleString() + ' sqft' : '—'}</div>
      </div>
      <div class="comp-price">${bbEscape(compPriceLabel(c))}</div>
    </div>`).join('') + '</div>';
}

// Mirrors renderCompMap() but reads from a saved snapshot instead of the
// bbLast* globals, and takes an explicit target element -- a history list
// can have several cards, each needing its own independent map instance.
function renderSavedCompMap(mapEl, snap) {
  if (!snap) return;
  if (!window.google || !google.maps) { mapEl.outerHTML = '<p class="empty-note">Map unavailable (Google Maps not configured).</p>'; return; }
  const comps = snap.comps || [];
  const subject = snap.subject || null;
  const pts = comps.filter(c => c.lat && c.lon);
  const center = (subject && subject.lat && subject.lon)
    ? {lat: subject.lat, lng: subject.lon}
    : (pts[0] ? {lat: pts[0].lat, lng: pts[0].lon} : {lat: 33.69, lng: -78.89});

  const map = new google.maps.Map(mapEl, {center, zoom: 13});
  const infoWindow = new google.maps.InfoWindow();

  if (subject && subject.lat && subject.lon) {
    const subjectMarker = new google.maps.Marker({
      position: {lat: subject.lat, lng: subject.lon}, map,
      title: 'Subject: ' + (subject.address || ''),
      icon: {path: google.maps.SymbolPath.CIRCLE, scale: 9, fillColor: '#82C112', fillOpacity: 1, strokeColor: '#1a1a1a', strokeWeight: 2},
      zIndex: 999,
    });
    subjectMarker.addListener('mouseover', () => {
      infoWindow.setContent(gmHoverCardHtml('Subject: ' + (subject.address || ''), subject));
      infoWindow.open(map, subjectMarker);
    });
    subjectMarker.addListener('mouseout', () => infoWindow.close());
  }
  comps.forEach((c, i) => {
    if (!c.lat || !c.lon) return;
    const marker = new google.maps.Marker({
      position: {lat: c.lat, lng: c.lon}, map,
      title: c.address + ' — ' + compPriceLabel(c),
      label: {text: String(i + 1), color: '#fff', fontSize: '10px', fontWeight: '700'},
    });
    marker.addListener('mouseover', () => {
      infoWindow.setContent(gmHoverCardHtml('#' + (i + 1) + ' — ' + c.address, c));
      infoWindow.open(map, marker);
    });
    marker.addListener('mouseout', () => infoWindow.close());
  });
}

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
        <div class="draft-top" style="cursor:pointer" onclick="bbToggleDraftCard(this)">
          <div>
            <div class="draft-target"><span class="draft-expand-arrow">&#9656;</span> ${bbEscape(d.targetLabel)}</div>
            <div class="draft-meta">${d.generatedAt ? new Date(d.generatedAt).toLocaleString() : ''}</div>
          </div>
          <span class="badge ${bbEscape(d.status)}">${bbEscape(d.status)}</span>
        </div>
        <div class="draft-details" hidden>
          <div class="draft-subject">${bbEscape(d.subject)}</div>
          <div class="draft-body">${bbEscape(d.bodyText)}</div>
          ${renderSavedComps(d, agentType)}
          ${d.status === 'draft' ? `
            <div class="draft-actions">
              <button class="btn-primary" onclick="bbApprove('${d.id}', this)">Approve & send</button>
              <button class="btn-danger" onclick="bbReject('${d.id}', this)">Reject</button>
            </div>` : ''}
          ${agentType === 'delegate' ? `
            <div class="draft-actions">
              <button class="btn-danger" onclick="bbDeleteDraft('${d.id}', this)">Delete</button>
            </div>` : ''}
          ${d.status === 'failed' && d.sendError ? `<div class="draft-meta" style="color:#c0392b;margin-top:6px">Error: ${bbEscape(d.sendError)}</div>` : ''}
        </div>
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

async function bbDeleteDraft(draftId, btn) {
  if (!confirm('Delete this saved search? This cannot be undone.')) return;
  const card = btn.closest('.draft-card');
  btn.disabled = true;
  try {
    const r = await fetch('api/buyback_draft_delete.php?draft_id=' + encodeURIComponent(draftId), {method: 'POST', credentials: 'same-origin'});
    const data = await r.json();
    if (!r.ok || data.ok === false) { alert('Error: ' + (data.error || data.detail || 'Unknown error')); btn.disabled = false; return; }
    card.remove();
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
