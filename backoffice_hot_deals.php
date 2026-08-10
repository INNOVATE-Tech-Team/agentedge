<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
if (!can_send_hot_deals()) { header('Location: index.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Hot Deals — AgentEdge</title>
<link rel="stylesheet" href="assets/app.css">
<style>
.bo-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
.hd-form{background:#f9fdf5;border:1px solid #d4edab;border-radius:10px;padding:20px 24px;margin-bottom:24px}
.hd-form h3{margin:0 0 16px;font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#5b8e0d}
.field-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:12px}
.field-full{margin-bottom:12px}
.field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px}
.field input,.field select{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:6px;
      font-size:13px;box-sizing:border-box;font-family:inherit}
.field input:focus,.field select:focus{outline:2px solid #82C112;border-color:#82C112}
.hd-check{display:flex;align-items:center;gap:6px;font-size:13px;color:#333;cursor:pointer;margin-top:22px}
.btn-primary{padding:9px 20px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
.btn-primary:hover{background:#5b8e0d;color:#fff}
.btn-primary:disabled{opacity:.5;cursor:default}
.btn-secondary{padding:7px 16px;background:#fff;color:#333;border:1px solid #ccc;border-radius:6px;font-weight:700;font-size:12px;cursor:pointer}
.btn-secondary:hover{background:#f5f5f5;border-color:#aaa}
.btn-secondary:disabled{opacity:.5;cursor:default}
.section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint);margin:0 0 8px}
.empty-note{color:var(--faint);font-style:italic;text-align:center;padding:20px}
.send-status{font-size:12px;font-weight:700}
.send-status.ok{color:#2e7d32}
.send-status.err{color:#c0392b}

.deal-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:16px 20px;margin-bottom:14px}
.deal-top{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px}
.deal-price{font-size:20px;font-weight:800;color:#111}
.deal-addr{font-size:13px;color:#666;margin-top:2px}
.deal-badges{display:flex;gap:8px;flex-wrap:wrap}
.badge{font-size:10px;font-weight:700;padding:3px 9px;border-radius:12px;white-space:nowrap}
.badge.value-good{background:#eef5e8;color:#5b8e0d}
.badge.value-bad{background:#fde8e0;color:#c46a1a}
.badge.roi{background:#e8f0fe;color:#1a56c4}
.badge.note{background:#f3f3f3;color:#888}
.deal-detail{font-size:12px;color:#777;margin-top:8px;line-height:1.6}
.leads-box{margin-top:12px;padding-top:12px;border-top:1px solid #eee}
.leads-box .section-label{margin-bottom:6px}
.lead-row{display:flex;align-items:center;gap:8px;font-size:13px;padding:4px 0}
.lead-row label{cursor:pointer;display:flex;align-items:center;gap:8px}
.deal-send-bar{display:flex;align-items:center;gap:12px;margin-top:10px}

.hist-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-top:10px}
.hist-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--faint);
      padding:8px 16px;text-align:left;white-space:nowrap;border-bottom:1px solid var(--border)}
.hist-table td{padding:9px 16px;border-top:1px solid var(--border);vertical-align:middle}
.hist-table tr:first-child td{border-top:none}
.hist-toggle{cursor:pointer;color:#5b8e0d;font-size:12px;font-weight:700}
.hist-recipients{display:none;background:#fafafa;font-size:12px}
.hist-recipients.open{display:table-row}
.spinner-note{color:var(--faint);font-size:13px;padding:12px 0}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('bo_hot_deals', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="bo-eyebrow">Back Office</div>
      <div class="content-title">Hot Deals</div>
    </div>
  </div>
  <div class="wrap">

    <div class="hd-form">
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
      <div style="margin-top:14px;display:flex;align-items:center;gap:14px">
        <button class="btn-primary" id="hd-search-btn" onclick="hdSearch()">Find Deals</button>
        <span class="send-status" id="hd-search-status"></span>
      </div>
    </div>

    <div id="hd-results"></div>

    <div style="margin-top:32px">
      <div class="section-label">Past Sends</div>
      <div id="hd-history"><div class="spinner-note">Loading…</div></div>
    </div>

  </div>
</div>
</div>

<script>
function hdEscape(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
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

  try {
    const r = await fetch('api/hot_deals_preview.php', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(body),
    });
    const data = await r.json();
    btn.disabled = false; btn.textContent = 'Find Deals';
    if (!r.ok || data.ok === false) {
      status.textContent = 'Error: ' + (data.error || data.detail || 'Unknown error');
      status.className = 'send-status err';
      return;
    }
    renderResults(data.candidates || []);
    if (!data.fub_configured) status.textContent = 'Note: FUB lead matching is not configured.';
    if (!data.str_data_configured) status.textContent += (status.textContent ? ' ' : '') + 'Note: rental-income data is not configured — ranked by price vs. comps only.';
  } catch (e) {
    btn.disabled = false; btn.textContent = 'Find Deals';
    status.textContent = 'Network error — could not reach the server.';
    status.className = 'send-status err';
  }
}

function renderResults(candidates) {
  const container = document.getElementById('hd-results');
  if (!candidates.length) {
    container.innerHTML = '<div class="empty-note">No active listings matched this spec.</div>';
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
        `<div class="lead-row"><label><input type="checkbox" class="hd-lead-cb" data-deal="${idx}" data-person-id="${l.person_id}" checked> ${hdEscape(l.name || l.email)} (${hdEscape(l.email)})</label></div>`
      ).join('');
      leadsHtml = `
        <div class="leads-box">
          <div class="section-label">Matching leads (${leads.length})</div>
          ${rows}
          <div class="deal-send-bar">
            <button class="btn-secondary" onclick="hdSend(${idx})">Send to selected</button>
            <span class="send-status" id="hd-send-status-${idx}"></span>
          </div>
        </div>`;
    } else {
      leadsHtml = `<div class="leads-box"><span class="empty-note" style="padding:4px 0">No leads currently searching for this type of property.</span></div>`;
    }

    return `<div class="deal-card" data-listing-key="${hdEscape(c.listing_key)}" data-rationale="${hdEscape(c.rationale || '')}">
      <div class="deal-top">
        <div>
          <div class="deal-price">${hdMoney(c.list_price)}</div>
          <div class="deal-addr">${hdEscape(c.unparsed_address)} · ${c.bedrooms_total}bd/${c.bathrooms_full}ba · ${c.living_area_sqft ? Math.round(c.living_area_sqft).toLocaleString() + ' sqft' : ''}</div>
        </div>
        <div class="deal-badges">${badges.join('')}</div>
      </div>
      <div class="deal-detail">
        ${c.subdivision_name ? hdEscape(c.subdivision_name) + ' · ' : ''}${c.days_on_market != null ? c.days_on_market + ' days on market' : ''}
        ${c.rental_estimate ? ` · Est. annual rental revenue: ${hdMoney(c.rental_estimate.annual_revenue)}` : ''}
        ${c.disclosed_monthly_hoa ? ` · HOA: ${hdMoney(c.disclosed_monthly_hoa)}/mo${c.hoa_source === 'mls' ? ' (from MLS data)' : ' (mentioned in remarks — verify)'}` : ' · HOA not disclosed — verify before pitching'}
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
    const r = await fetch('api/hot_deals_send.php', {
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
    loadHistory();
  } catch (e) {
    statusEl.textContent = 'Network error — could not reach the server.';
    statusEl.className = 'send-status err';
  }
}

async function loadHistory() {
  const el = document.getElementById('hd-history');
  try {
    const r = await fetch('api/hot_deals_history.php', {credentials: 'same-origin'});
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
          <td>${hdEscape(c.listingKey || c.name)}</td>
          <td>${c.stats.sent}</td>
          <td>${c.stats.opened}</td>
          <td>${c.stats.clicked}</td>
        </tr>
        <tr class="hist-recipients" id="hist-recip-${i}"><td colspan="6">
          ${(c.recipients || []).map(rp => `${hdEscape(rp.email)} — ${hdEscape(rp.status)}, ${rp.opens} open(s), ${rp.clicks} click(s)`).join('<br>') || 'No recipients.'}
        </td></tr>
      `).join('')}</tbody>
    </table>`;
  } catch (e) {
    el.innerHTML = '<div class="empty-note">Could not load history.</div>';
  }
}
function hdToggleHist(i) {
  document.getElementById('hist-recip-' + i).classList.toggle('open');
}

loadHistory();
</script>
</body>
</html>
