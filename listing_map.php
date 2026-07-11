<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/local_db.php';
$agent = require_login();

$me = $agent['email'];
$db = local_db();

$sf = $db->prepare("SELECT * FROM listing_farms WHERE agent_email=? ORDER BY name"); $sf->execute([$me]); $farms = $sf->fetchAll(PDO::FETCH_ASSOC);
$sp = $db->prepare("SELECT * FROM listing_prospects WHERE agent_email=?"); $sp->execute([$me]); $prospects = $sp->fetchAll(PDO::FETCH_ASSOC);

$hasDemoFarm = false;
foreach ($farms as $f) { if (!empty($f['is_demo'])) { $hasDemoFarm = true; break; } }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Listing Intel Map</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
  <style>
    :root{
      --green:#82C112; --green-d:#5b8e0d; --ink:#111; --faint:#8a8a8a; --text-2:#666; --text-3:#999;
      --surface:#fff; --surface-2:#F8F9FA; --border:#E6E7E8; --red:#A40000; --gold:#A07221;
    }
    html,body{height:100%;margin:0;overflow:hidden;font-family:'Inter',-apple-system,sans-serif}
    .tnum{font-family:'JetBrains Mono',monospace;font-variant-numeric:tabular-nums}
    .lm-header{display:flex;align-items:center;gap:12px;padding:10px 16px;border-bottom:1px solid var(--border);background:#000;position:relative;z-index:600}
    .lm-brand{display:flex;align-items:center;gap:8px;flex:1;min-width:0}
    .lm-brand-name{font-size:13px;font-weight:800;color:#fff;white-space:nowrap}
    .lm-brand-sub{font-size:9px;font-weight:700;color:var(--green);text-transform:uppercase;letter-spacing:.1em}
    .lm-back{font-size:12px;color:#999;text-decoration:none;white-space:nowrap}
    .lm-back:hover{color:#fff}
    #lm-map{position:absolute;top:53px;left:0;right:0;bottom:0}
    .lm-legend{position:absolute;bottom:16px;left:16px;z-index:500;background:#fff;padding:10px 14px;
      border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.15);font-size:12px;display:flex;flex-direction:column;gap:5px}
    .chip{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:600;white-space:nowrap}
    .chip-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
    .btn{display:inline-flex;align-items:center;gap:5px;padding:8px 14px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}
    .btn-primary{background:var(--green);color:#fff}
    .btn-primary:hover{background:var(--green-d)}
    .btn-ghost{background:rgba(255,255,255,.1);color:#fff}
    .btn-ghost:hover{background:rgba(255,255,255,.18)}
    .btn-sm{padding:5px 10px;font-size:12px}
    .btn-row{display:flex;gap:8px;flex-wrap:wrap}
    .score-wrap{display:flex;align-items:center;gap:6px}
    .score-bar{flex:1;height:5px;background:#eee;border-radius:3px;overflow:hidden}
    .score-fill{height:100%;border-radius:3px}
    .score-num{font-size:11px;font-weight:700;color:var(--faint);width:22px;text-align:right}
    .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:1000}
    .modal-overlay.open{display:flex}
    .modal{background:#fff;border-radius:12px;width:min(420px,95vw);max-height:90vh;overflow-y:auto;padding:24px;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.25)}
    .modal h3{margin:0 0 4px;font-size:17px;font-weight:800;color:var(--ink)}
    .modal .sub{font-size:12px;color:var(--faint);margin-bottom:18px}
    .modal-close{position:absolute;top:16px;right:16px;background:none;border:none;font-size:22px;cursor:pointer;color:#888;line-height:1}
    .form-row{margin-bottom:14px}
    .form-row label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-3);margin-bottom:5px}
    .form-input{width:100%;padding:9px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--surface-2);color:var(--ink)}
    .form-input:focus{outline:none;border-color:var(--green);background:#fff}
  </style>
</head>
<body>

<div class="lm-header">
  <div class="lm-brand">
    <svg width="20" height="20" viewBox="0 0 26 26" fill="none">
      <rect x="0" y="14" width="8" height="12" fill="#82C112"/>
      <rect x="9" y="7" width="8" height="19" fill="#82C112"/>
      <rect x="18" y="0" width="8" height="26" fill="#82C112"/>
    </svg>
    <span class="lm-brand-name">Listing Intel</span>
    <a class="lm-back" href="listing_intel.php">&larr; Back to dashboard</a>
  </div>
  <?php if ($hasDemoFarm): ?>
  <button class="btn btn-ghost btn-sm" onclick="clearDemoData()">Clear Sample Data</button>
  <?php else: ?>
  <button class="btn btn-primary btn-sm" onclick="seedDemoData()">+ Load Sample Data</button>
  <?php endif; ?>
  <span class="tnum" style="font-size:12px;color:#999" id="map-count"></span>
</div>

<div id="lm-map"></div>

<div class="lm-legend">
  <div><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--red);margin-right:5px"></span>Hot lead (75+)</div>
  <div><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--gold);margin-right:5px"></span>Warm lead (45–74)</div>
  <div><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--green);margin-right:5px"></span>Cooler lead (&lt;45)</div>
  <div>&#127968; Active MLS listing</div>
</div>

<!-- ── SKIP TRACE MODAL (same fields/flow as listing_intel.php) ────────────── -->
<div class="modal-overlay" id="skiptrace-overlay">
  <div class="modal">
    <button class="modal-close" onclick="closeSkipTraceModal()">×</button>
    <h3>Mark Skip Traced</h3>
    <div class="sub" id="skiptrace-modal-sub">Enter the contact info you found for this property owner.</div>
    <form id="skiptrace-form">
      <input type="hidden" id="st-prospect-id" value="">
      <div class="form-row">
        <label>Owner Name</label>
        <input type="text" id="st-owner" class="form-input" placeholder="John Smith">
      </div>
      <div class="form-row">
        <label>Phone</label>
        <input type="text" id="st-phone" class="form-input" placeholder="(843) 555-0100">
      </div>
      <div class="form-row">
        <label>Email</label>
        <input type="email" id="st-email" class="form-input" placeholder="owner@email.com">
      </div>
      <div class="btn-row">
        <button type="submit" class="btn btn-primary">Save &amp; Mark Traced</button>
        <button type="button" class="btn btn-ghost" onclick="closeSkipTraceModal()">Cancel</button>
      </div>
      <div id="skiptrace-error" style="color:var(--red);font-size:12px;margin-top:10px;display:none"></div>
    </form>
  </div>
</div>

<script src="assets/vendor/leaflet/leaflet.js"></script>
<script>
const MAP_PROSPECTS = <?= json_encode(array_map(fn($p) => [
    'id' => $p['id'], 'address' => $p['address'], 'city' => $p['city'], 'zip' => $p['zip'],
    'lat' => (float)$p['lat'], 'lon' => (float)$p['lon'], 'seller_score' => (int)$p['seller_score'],
    'owner_name' => $p['owner_name'], 'phone' => $p['phone'], 'email' => $p['email'],
    'skip_traced' => (int)$p['skip_traced'], 'absentee_owner' => (int)($p['absentee_owner'] ?? 0),
    'tax_delinquent' => (int)($p['tax_delinquent'] ?? 0), 'in_foreclosure' => (int)($p['in_foreclosure'] ?? 0),
    'is_vacant' => (int)($p['is_vacant'] ?? 0), 'est_value' => (int)$p['est_value'],
], array_filter($prospects, fn($p) => $p['lat'] && $p['lon']))) ?>;
const MAP_ZIPS = <?= json_encode(array_values(array_unique(array_merge(...array_map(fn($f) => json_decode($f['zip_codes'], true) ?: [], $farms))))) ?>;

function esc(s) { return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

(function(){
  const el = document.createElement('div');
  el.id = 'li-toast';
  el.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9000;display:flex;flex-direction:column;gap:8px;pointer-events:none';
  document.body.appendChild(el);
})();
function liToast(msg, type='info', duration=4000) {
    const t = document.createElement('div');
    const bg = {success:'#2d7a0e',error:'#c0392b',info:'#2255cc',warn:'#a07221'}[type] || '#333';
    t.style.cssText = `background:${bg};color:#fff;padding:12px 18px;border-radius:8px;font-size:13px;font-weight:600;max-width:340px;box-shadow:0 4px 14px rgba(0,0,0,.2);opacity:0;transition:opacity .2s;pointer-events:auto`;
    t.textContent = msg;
    document.getElementById('li-toast').appendChild(t);
    requestAnimationFrame(() => { t.style.opacity = '1'; });
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 220); }, duration);
}

// ── Skip trace modal ────────────────────────────────────────────────────────
function openSkipTraceModal(prospectId, address) {
    document.getElementById('st-prospect-id').value = prospectId;
    document.getElementById('skiptrace-modal-sub').textContent = address;
    document.getElementById('st-owner').value = '';
    document.getElementById('st-phone').value = '';
    document.getElementById('st-email').value = '';
    document.getElementById('skiptrace-error').style.display = 'none';
    document.getElementById('skiptrace-overlay').classList.add('open');
}
function closeSkipTraceModal() { document.getElementById('skiptrace-overlay').classList.remove('open'); }
document.getElementById('skiptrace-overlay').addEventListener('click', e => { if (e.target.id === 'skiptrace-overlay') closeSkipTraceModal(); });
document.getElementById('skiptrace-form').addEventListener('submit', async e => {
    e.preventDefault();
    const body = {
        action: 'mark_skip_traced',
        prospect_id: document.getElementById('st-prospect-id').value,
        owner_name: document.getElementById('st-owner').value.trim(),
        phone: document.getElementById('st-phone').value.trim(),
        email: document.getElementById('st-email').value.trim(),
    };
    try {
        const r = await fetch('api/listing_intel.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
        const d = await r.json();
        if (d.ok) { closeSkipTraceModal(); liToast('Marked as skip traced.', 'success'); setTimeout(() => location.reload(), 800); }
        else throw new Error(d.error || 'Save failed');
    } catch(err) {
        document.getElementById('skiptrace-error').textContent = err.message;
        document.getElementById('skiptrace-error').style.display = 'block';
    }
});

// ── Map ──────────────────────────────────────────────────────────────────────
function scoreColor(score) {
    return score >= 75 ? '#A40000' : (score >= 45 ? '#A07221' : '#5b8e0d');
}
function badgeRow(p) {
    const badges = [];
    if (p.tax_delinquent)  badges.push('<span class="chip" style="background:#FDE2E2;color:#A40000"><span class="chip-dot" style="background:#A40000"></span>Tax Delinquent</span>');
    if (p.in_foreclosure)  badges.push('<span class="chip" style="background:#FDE2E2;color:#A40000"><span class="chip-dot" style="background:#A40000"></span>Foreclosure</span>');
    if (p.is_vacant)       badges.push('<span class="chip" style="background:#F5EBD3;color:#7A5618"><span class="chip-dot" style="background:#A07221"></span>Vacant</span>');
    if (p.absentee_owner)  badges.push('<span class="chip" style="background:#F5EEFF;color:#7C3AED"><span class="chip-dot" style="background:#7C3AED"></span>Absentee</span>');
    return badges.length ? `<div style="margin:6px 0;display:flex;gap:4px;flex-wrap:wrap">${badges.join('')}</div>` : '';
}
function buildProspectPopup(p) {
    const contact = p.skip_traced
        ? `<div style="font-size:12px;margin-top:6px"><strong>${esc(p.owner_name || 'Unknown owner')}</strong>${p.phone ? '<br>'+esc(p.phone) : ''}${p.email ? '<br>'+esc(p.email) : ''}</div>`
        : `<button class="btn btn-ghost btn-sm" style="margin-top:6px;color:#333;background:#f0f0f0" onclick='openSkipTraceModal(${p.id}, ${JSON.stringify(p.address)})'>Skip Trace</button>`;
    return `
      <div style="min-width:200px;color:#111">
        <div style="font-weight:700">${esc(p.address)}</div>
        <div style="font-size:11px;color:var(--faint);margin-bottom:4px">${esc(p.city)} ${esc(p.zip)}</div>
        <div class="score-wrap" style="margin:6px 0">
          <div class="score-bar"><div class="score-fill" style="width:${p.seller_score}%;background:${scoreColor(p.seller_score)}"></div></div>
          <div class="score-num tnum">${p.seller_score}</div>
        </div>
        ${badgeRow(p)}
        ${contact}
      </div>`;
}
function buildListingPopup(l) {
    return `
      <div style="min-width:200px;color:#111">
        <div style="font-weight:700">${esc(l.address)}</div>
        <div style="font-size:11px;color:var(--faint);margin-bottom:4px">${esc(l.city)} ${esc(l.zip)}</div>
        <div class="tnum" style="font-weight:700;margin:4px 0">${l.list_price ? '$'+Number(l.list_price).toLocaleString() : '—'}</div>
        ${l.listing_agent_name ? `<div style="font-size:12px;color:var(--faint)">Agent: ${esc(l.listing_agent_name)}</div>` : ''}
        <div style="font-size:11px;color:var(--faint);margin-top:4px">MLS #${esc(l.mls_number)}</div>
      </div>`;
}

let liMap = null;
function initLiMap() {
    let lat = 33.460, lon = -79.130;
    if (MAP_PROSPECTS.length) { lat = MAP_PROSPECTS[0].lat; lon = MAP_PROSPECTS[0].lon; }
    liMap = L.map('lm-map').setView([lat, lon], 12);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; OpenStreetMap contributors &copy; CARTO', maxZoom: 19,
    }).addTo(liMap);

    MAP_PROSPECTS.forEach(p => {
        L.circleMarker([p.lat, p.lon], {
            radius: 9, weight: 2, color: '#fff', fillColor: scoreColor(p.seller_score), fillOpacity: 0.9,
        }).addTo(liMap).bindPopup(buildProspectPopup(p));
    });

    document.getElementById('map-count').textContent = MAP_PROSPECTS.length + ' prospect' + (MAP_PROSPECTS.length !== 1 ? 's' : '') + ' on map';
    loadActiveListingsOnMap();
}
async function loadActiveListingsOnMap() {
    if (!liMap || !MAP_ZIPS.length) return;
    let total = 0;
    for (const zip of MAP_ZIPS) {
        try {
            const r = await fetch('api/listing_intel.php?action=get_active_listings&zip=' + encodeURIComponent(zip), {credentials:'same-origin'});
            const d = await r.json();
            if (!d.ok || !d.listings) continue;
            d.listings.forEach(l => {
                const icon = L.icon({
                    iconUrl: 'assets/vendor/leaflet/images/marker-icon.png',
                    iconRetinaUrl: 'assets/vendor/leaflet/images/marker-icon-2x.png',
                    shadowUrl: 'assets/vendor/leaflet/images/marker-shadow.png',
                    iconSize: [25,41], iconAnchor: [12,41], popupAnchor: [1,-34], shadowSize: [41,41],
                });
                L.marker([l.lat, l.lon], {icon}).addTo(liMap).bindPopup(buildListingPopup(l));
                total++;
            });
        } catch(e) { /* MLS overlay is best-effort; prospect pins already rendered */ }
    }
    if (total) document.getElementById('map-count').textContent += ` · ${total} active MLS listing${total !== 1 ? 's' : ''}`;
}
async function seedDemoData() {
    try {
        const r = await fetch('api/listing_intel.php', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'seed_demo_data'})});
        const d = await r.json();
        if (d.ok) { liToast('Sample data loaded — 40 demo prospects added.', 'success'); setTimeout(() => location.reload(), 1000); }
        else throw new Error(d.error || 'Failed');
    } catch(e) { liToast('Error: ' + e.message, 'error'); }
}
async function clearDemoData() {
    if (!confirm('Remove all sample/demo data?')) return;
    try {
        const r = await fetch('api/listing_intel.php', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'clear_demo_data'})});
        const d = await r.json();
        if (d.ok) { liToast('Sample data cleared.', 'success'); setTimeout(() => location.reload(), 800); }
        else throw new Error(d.error || 'Failed');
    } catch(e) { liToast('Error: ' + e.message, 'error'); }
}

initLiMap();
</script>
</body>
</html>
