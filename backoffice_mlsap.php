<?php
/**
 * AgentEdge Back Office: MLS & Boards Payables.
 * Accounts payable for MLS, REALTOR board, and MLS data feed bills only —
 * not a general AP system. Finance-only (Super Admin + Michele Chalk).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/lib/mlsap_lib.php';

$agent = require_login();
if (!can_access_mlsap()) { http_response_code(403); exit('Not authorized.'); }

$db = local_db();
$mlsIntegrations = $db->query("SELECT id, mls_name FROM mls_integrations ORDER BY mls_name")->fetchAll(PDO::FETCH_ASSOC);
$marketCenters = $db->query("SELECT slug, name, state_code FROM market_centers WHERE enabled=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MLS &amp; Boards Payables — AgentEdge</title>
<link rel="stylesheet" href="assets/app.css">
<style>
.ap{--bg:#f6f7f9;--card:#fff;--ink:#16181d;--muted:#5d6470;--line:#e3e6eb;--accent:#1f5eff;--accent-ink:#fff;
  --green:#16794c;--green-bg:#e6f5ee;--amber:#9a5b00;--amber-bg:#fff4e0;--red:#b42318;--red-bg:#fdecea;--gray-bg:#eef0f3;
  font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:var(--ink);background:var(--bg);padding:24px 16px 48px}
.ap *{box-sizing:border-box}
.ap-wrap{max-width:1360px;margin:0 auto}
.ap h1{font-size:24px;margin:0 0 2px;letter-spacing:-.01em}
.ap .sub{color:var(--muted);margin:0 0 20px}
.ap .tabs{display:flex;gap:4px;border-bottom:1px solid var(--line);margin-bottom:20px}
.ap .tab{font:inherit;font-weight:700;font-size:13px;background:none;border:none;border-bottom:2px solid transparent;color:var(--muted);padding:10px 14px;margin-bottom:-1px;cursor:pointer}
.ap .tab:hover{color:var(--ink)}
.ap .tab.active{color:var(--accent);border-bottom-color:var(--accent)}
.ap .panel.hide{display:none}
.ap .tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px}
.ap .tile{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px 16px}
.ap .tile b{display:block;font-size:22px;line-height:1.1;font-variant-numeric:tabular-nums}
.ap .tile span{color:var(--muted);font-size:12px}
.ap .tile.warn b{color:var(--amber)} .ap .tile.bad b{color:var(--red)}
.ap .card{background:var(--card);border:1px solid var(--line);border-radius:10px;margin-bottom:20px}
.ap .card-h{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--line)}
.ap .card-h h2{font-size:16px;margin:0}
.ap .tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.ap select,.ap input,.ap textarea{font:inherit;color:var(--ink);background:var(--card);border:1px solid var(--line);border-radius:7px;padding:7px 9px}
.ap textarea{width:100%;min-height:56px;resize:vertical}
.ap .btn{display:inline-block;font:inherit;font-weight:600;border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:7px;padding:7px 12px;cursor:pointer;text-decoration:none;white-space:nowrap}
.ap .btn.primary{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}
.ap .btn.danger{color:var(--red);border-color:#f3c8c3}
.ap table{width:100%;border-collapse:collapse;font-size:13px}
.ap th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:600;padding:9px 10px;border-bottom:1px solid var(--line);white-space:nowrap}
.ap td{padding:9px 10px;border-bottom:1px solid var(--line);vertical-align:top}
.ap tr.row{cursor:pointer} .ap tr.row:hover td{background:var(--bg)}
.ap th.sortable{cursor:pointer;user-select:none} .ap th.sortable:hover{color:var(--ink)}
.ap th.sortable.sorted{color:var(--accent)}
.ap th.sortable.sorted::after{content:' \25B2';font-size:9px} .ap th.sortable.sorted.desc::after{content:' \25BC'}
.ap .btn.sm{padding:3px 8px;font-size:12px}
.ap .num{font-variant-numeric:tabular-nums;white-space:nowrap}
.ap .muted{color:var(--muted);font-size:12px}
.ap .ent{font-weight:600}
.ap .pill{display:inline-block;border-radius:999px;padding:2px 9px;font-size:11px;font-weight:600;white-space:nowrap}
.ap .p-green{background:var(--green-bg);color:var(--green)}
.ap .p-yellow{background:var(--amber-bg);color:var(--amber)}
.ap .p-red{background:var(--red-bg);color:var(--red)}
.ap .p-gray{background:var(--gray-bg);color:var(--ink)}
.ap .empty{padding:30px;text-align:center;color:var(--muted)}
.ap .detail{padding:16px;background:var(--bg)}
.ap .form-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:10px}
.ap .form-grid label{display:flex;flex-direction:column;gap:4px;font-size:11px;color:var(--muted);font-weight:600}
.ap .form-grid .full{grid-column:1/-1}
.ap .flash{border-radius:8px;padding:8px 12px;margin-bottom:12px;font-size:13px;font-weight:600}
.ap .flash.ok{background:var(--green-bg);color:var(--green)} .ap .flash.err{background:var(--red-bg);color:var(--red)}
@media (max-width:900px){ .ap .form-grid{grid-template-columns:1fr 1fr} }
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('bo_mlsap', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="bo-eyebrow">Back Office</div>
      <div class="content-title">MLS &amp; Boards Payables</div>
    </div>
  </div>
  <div class="wrap">
<div class="ap"><div class="ap-wrap">
  <h1>MLS &amp; Boards Payables</h1>
  <p class="sub">Accounts payable for MLS, board, and data feed bills only.</p>

  <div id="ap-flash"></div>

  <div class="tabs">
    <button class="tab active" data-panel="dashboard">Dashboard</button>
    <button class="tab" data-panel="accounts">Accounts</button>
    <button class="tab" data-panel="bills">Bills</button>
    <button class="tab" data-panel="darwin">Darwin Queue</button>
  </div>

  <div class="panel" id="panel-dashboard">
    <div class="tiles" id="dash-tiles"></div>
    <div class="card">
      <div class="card-h"><h2>Overdue</h2></div>
      <table><thead><tr><th>Account</th><th>Invoice</th><th>Amount</th><th>Due</th></tr></thead><tbody id="dash-overdue"></tbody></table>
    </div>
    <div class="card">
      <div class="card-h"><h2>Renewals — next 30 days</h2></div>
      <table><thead><tr><th>Account</th><th>State</th><th>Type</th><th>Renewal Month</th></tr></thead><tbody id="dash-renewals"></tbody></table>
    </div>
    <div class="card">
      <div class="card-h"><h2>Not yet entered in Darwin</h2></div>
      <table><thead><tr><th>Code</th><th>Account</th><th>Amount</th><th>Paid</th></tr></thead><tbody id="dash-notentered"></tbody></table>
    </div>
  </div>

  <div class="panel hide" id="panel-accounts">
    <div class="card">
      <div class="card-h">
        <h2>Accounts</h2>
        <div class="tools">
          <select id="f-state"><option value="">All states</option></select>
          <select id="f-type"><option value="">All types</option><?php foreach (MLSAP_ACCOUNT_TYPES as $t): ?><option><?= mlsap_h($t) ?></option><?php endforeach; ?></select>
          <select id="f-decision"><option value="">All decisions</option><?php foreach (MLSAP_DECISIONS as $d): ?><option><?= mlsap_h($d) ?></option><?php endforeach; ?></select>
          <select id="f-code"><option value="">All codes</option><?php foreach (MLSAP_EXPENSE_CODES as $code => $label): ?><option value="<?= mlsap_h($code) ?>"><?= mlsap_h($code) ?></option><?php endforeach; ?></select>
          <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--muted)"><input type="checkbox" id="f-all">Include inactive</label>
          <button class="btn primary" id="btn-new-account" type="button">+ New Account</button>
        </div>
      </div>
      <table>
        <thead><tr id="accounts-head">
          <th class="sortable" data-sort="name">Name</th><th class="sortable" data-sort="state">State</th>
          <th class="sortable" data-sort="account_type">Type</th><th class="sortable" data-sort="annualized_cost">Annualized</th>
          <th class="sortable" data-sort="agent_count">Agents</th><th class="sortable" data-sort="cost_per_agent">$/Agent</th>
          <th class="sortable" data-sort="suggested_decision">Suggested</th><th class="sortable" data-sort="saved_decision">Saved</th>
          <th class="sortable" data-sort="last_paid">Last Paid</th><th class="sortable" data-sort="next_due">Next Due</th>
          <th class="sortable" data-sort="owner_email">Owner</th><th></th>
        </tr></thead>
        <tbody id="accounts-list"></tbody>
      </table>
    </div>
    <div class="card hide" id="account-detail-card"></div>
  </div>

  <div class="panel hide" id="panel-bills">
    <div class="card">
      <div class="card-h"><h2 id="bill-form-heading">Add Bill</h2></div>
      <div style="padding:16px">
        <input type="hidden" id="bill-id">
        <div class="form-grid">
          <label class="full">Account *<select id="bill-account"></select></label>
          <label>Invoice #<input id="bill-invoice-number"></label>
          <label>Invoice Date<input type="date" id="bill-invoice-date"></label>
          <label>Due Date<input type="date" id="bill-due-date"></label>
          <label>Amount *<input id="bill-amount" inputmode="decimal"></label>
          <label>Expense Code *<select id="bill-code"><?php foreach (MLSAP_EXPENSE_CODES as $code => $label): ?><option value="<?= mlsap_h($code) ?>"><?= mlsap_h($code) ?> — <?= mlsap_h($label) ?></option><?php endforeach; ?></select></label>
          <label>Period Start<input type="date" id="bill-period-start"></label>
          <label>Period End<input type="date" id="bill-period-end"></label>
          <label>Status<select id="bill-status"><?php foreach (MLSAP_BILL_STATUSES as $s): ?><option><?= mlsap_h($s) ?></option><?php endforeach; ?></select></label>
          <label class="full">Notes<textarea id="bill-notes"></textarea></label>
        </div>
        <button class="btn primary" id="btn-save-bill" type="button">Save Bill</button>
        <button class="btn hide" id="btn-cancel-bill" type="button">Cancel</button>
        <span id="bill-msg" class="muted"></span>
      </div>
    </div>
    <div class="card">
      <div class="card-h">
        <h2>All Bills</h2>
        <div class="tools">
          <select id="f-bill-status"><option value="">All statuses</option><?php foreach (MLSAP_BILL_STATUSES as $s): ?><option><?= mlsap_h($s) ?></option><?php endforeach; ?></select>
          <button class="btn" id="btn-reload-bills" type="button">Refresh</button>
        </div>
      </div>
      <table>
        <thead><tr><th>Account</th><th>Invoice</th><th>Code</th><th>Amount</th><th>Due</th><th>Status</th><th>Darwin</th><th>Receipt</th><th></th></tr></thead>
        <tbody id="bills-list"></tbody>
      </table>
    </div>
  </div>

  <div class="panel hide" id="panel-darwin">
    <div class="card">
      <div class="card-h">
        <h2>Darwin Queue — paid, not yet entered</h2>
        <div class="tools">
          <button class="btn" id="btn-darwin-csv" type="button">Export CSV</button>
          <button class="btn primary" id="btn-darwin-mark" type="button">Mark Selected Entered</button>
        </div>
      </div>
      <table>
        <thead><tr><th><input type="checkbox" id="darwin-select-all"></th><th>Code</th><th>Account</th><th>Invoice</th><th>Amount</th><th>Paid Date</th></tr></thead>
        <tbody id="darwin-list"></tbody>
      </table>
    </div>
  </div>

</div></div>
  </div>
</div>
</div>

<script>
const EXPENSE_CODES = <?= json_encode(MLSAP_EXPENSE_CODES) ?>;
const MC_OPTIONS = <?= json_encode($marketCenters) ?>;
const MLS_INTEGRATIONS = <?= json_encode($mlsIntegrations) ?>;
function h(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function money(v){ return v==null || v==='' ? '—' : '$' + Number(v).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }
function flash(msg, kind){
  const el = document.getElementById('ap-flash');
  el.innerHTML = `<div class="flash ${kind}">${h(msg)}</div>`;
  setTimeout(() => { el.innerHTML=''; }, 5000);
}
function api(body){
  return fetch('api/mlsap_action.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)}).then(r=>r.json());
}
function apiGet(qs){
  return fetch('api/mlsap_action.php?' + qs, {credentials:'same-origin'}).then(r=>r.json());
}

// ── Tabs ─────────────────────────────────────────────────────────────────
const PANELS = { dashboard: loadDashboard, accounts: loadAccounts, bills: loadBillsTab, darwin: loadDarwin };
document.querySelectorAll('.ap .tab').forEach(t => t.addEventListener('click', () => {
  document.querySelectorAll('.ap .tab').forEach(x => x.classList.toggle('active', x === t));
  document.querySelectorAll('.ap .panel').forEach(p => p.classList.toggle('hide', p.id !== 'panel-' + t.dataset.panel));
  PANELS[t.dataset.panel]();
}));

// ── Dashboard ────────────────────────────────────────────────────────────
function loadDashboard(){
  apiGet('resource=dashboard').then(d => {
    if (!d.ok) return;
    const s = d.summary;
    document.getElementById('dash-tiles').innerHTML = `
      <div class="tile"><b>${s.open_count}</b><span>Open bills (${money(s.open_amount)})</span></div>
      <div class="tile warn"><b>${s.due_soon_count}</b><span>Due in 30 days (${money(s.due_soon_amount)})</span></div>
      <div class="tile bad"><b>${s.overdue_count}</b><span>Overdue (${money(s.overdue_amount)})</span></div>
      <div class="tile"><b>${s.paid_this_month_count}</b><span>Paid this month (${money(s.paid_this_month_amount)})</span></div>
      <div class="tile"><b>${money(s.annual_spend)}</b><span>Known annual MLS/board spend</span></div>
      <div class="tile warn"><b>${money(s.review_cut_spend)}</b><span>Spend on Review/Cut accounts</span></div>
    `;
    document.getElementById('dash-overdue').innerHTML = d.overdue.length ? d.overdue.map(b => `
      <tr><td>${h(b.account_name)}</td><td>${h(b.invoice_number)}</td><td class="num">${money(b.amount)}</td><td>${h(b.due_date)}</td></tr>
    `).join('') : '<tr><td colspan="4" class="empty">None.</td></tr>';
    document.getElementById('dash-renewals').innerHTML = d.renewing_soon.length ? d.renewing_soon.map(a => `
      <tr><td>${h(a.name)}</td><td>${h(a.state)}</td><td>${h(a.account_type)}</td><td>${h(a.renewal_month)}</td></tr>
    `).join('') : '<tr><td colspan="4" class="empty">None.</td></tr>';
    const rows = [];
    Object.keys(d.not_entered).forEach(code => d.not_entered[code].forEach(b => rows.push(`
      <tr><td>${h(code)}</td><td>${h(b.account_name)}</td><td class="num">${money(b.amount)}</td><td>${h(b.paid_date)}</td></tr>
    `)));
    document.getElementById('dash-notentered').innerHTML = rows.length ? rows.join('') : '<tr><td colspan="4" class="empty">All caught up.</td></tr>';
  });
}

// ── Accounts ─────────────────────────────────────────────────────────────
let ACCOUNTS_CACHE = [];
function loadAccounts(){
  const params = new URLSearchParams();
  if (document.getElementById('f-all').checked) params.set('all', '1');
  if (document.getElementById('f-state').value) params.set('state', document.getElementById('f-state').value);
  if (document.getElementById('f-type').value) params.set('account_type', document.getElementById('f-type').value);
  if (document.getElementById('f-decision').value) params.set('decision', document.getElementById('f-decision').value);
  if (document.getElementById('f-code').value) params.set('code', document.getElementById('f-code').value);
  apiGet('resource=accounts&' + params.toString()).then(d => {
    if (!d.ok) return;
    ACCOUNTS_CACHE = d.rows;
    const states = [...new Set(d.rows.map(r => r.state).filter(Boolean))].sort();
    const stateSel = document.getElementById('f-state');
    const cur = stateSel.value;
    stateSel.innerHTML = '<option value="">All states</option>' + states.map(s => `<option ${s===cur?'selected':''}>${h(s)}</option>`).join('');
    renderAccounts(d.rows);
  });
}
// Click a column header to sort; click again to reverse. Blanks always sort last.
let ACCOUNT_SORT = { key: null, dir: 1 };
try { const s = JSON.parse(localStorage.getItem('mlsapAccountSort') || 'null'); if (s && s.key) ACCOUNT_SORT = s; } catch (e) {}
const NUMERIC_SORT = ['annualized_cost', 'agent_count', 'cost_per_agent'];
function sortAccounts(rows){
  const k = ACCOUNT_SORT.key;
  if (!k) return rows;
  const blank = v => v === null || v === undefined || v === '';
  return rows.slice().sort((a, b) => {
    const va = a[k], vb = b[k];
    if (blank(va) && blank(vb)) return 0;
    if (blank(va)) return 1;
    if (blank(vb)) return -1;
    const c = NUMERIC_SORT.includes(k) ? Number(va) - Number(vb)
      : String(va).localeCompare(String(vb), undefined, {sensitivity: 'base', numeric: true});
    return c * ACCOUNT_SORT.dir;
  });
}
document.querySelectorAll('#accounts-head th.sortable').forEach(th => th.addEventListener('click', () => {
  const k = th.dataset.sort;
  ACCOUNT_SORT = ACCOUNT_SORT.key === k ? { key: k, dir: -ACCOUNT_SORT.dir } : { key: k, dir: NUMERIC_SORT.includes(k) ? -1 : 1 };
  try { localStorage.setItem('mlsapAccountSort', JSON.stringify(ACCOUNT_SORT)); } catch (e) {}
  renderAccounts(ACCOUNTS_CACHE);
}));
function deleteAccount(id){
  const a = ACCOUNTS_CACHE.find(x => x.id == id) || {};
  const n = a.bill_count || 0;
  const msg = `Permanently delete "${a.name || 'this account'}"?` +
    (n ? `\n\nThis also deletes its ${n} bill${n === 1 ? '' : 's'} and decision history.` : '') +
    `\n\nThis cannot be undone. To hide it but keep its history, open the account and use Deactivate instead.`;
  if (!confirm(msg)) return;
  api({action: 'delete_account', id}).then(d => {
    if (!d.ok) { flash(d.error, 'err'); return; }
    flash(`Deleted "${a.name || 'account'}"` + (d.bills_deleted ? ` and ${d.bills_deleted} bill(s).` : '.'), 'ok');
    document.getElementById('account-detail-card').classList.add('hide');
    loadAccounts();
    loadDashboard();
  });
}
function deactivateAccount(id){
  const a = ACCOUNTS_CACHE.find(x => x.id == id) || {};
  if (!confirm(`Deactivate "${a.name || 'this account'}"? It's hidden from the list (tick "Include inactive" to see it) but its bills and history are kept.`)) return;
  api({action: 'deactivate_account', id}).then(d => {
    if (!d.ok) { flash(d.error, 'err'); return; }
    flash('Account deactivated.', 'ok');
    document.getElementById('account-detail-card').classList.add('hide');
    loadAccounts();
  });
}
function decisionPill(dec){
  if (!dec) return '<span class="muted">—</span>';
  const cls = dec === 'Keep' ? 'p-green' : dec === 'Keep lean' ? 'p-gray' : dec === 'Review' ? 'p-yellow' : 'p-red';
  return `<span class="pill ${cls}">${h(dec)}</span>`;
}
function renderAccounts(rows){
  const tbody = document.getElementById('accounts-list');
  document.querySelectorAll('#accounts-head th.sortable').forEach(th => {
    th.classList.toggle('sorted', th.dataset.sort === ACCOUNT_SORT.key);
    th.classList.toggle('desc', th.dataset.sort === ACCOUNT_SORT.key && ACCOUNT_SORT.dir < 0);
  });
  if (!rows.length) { tbody.innerHTML = '<tr><td colspan="12" class="empty">No accounts match.</td></tr>'; return; }
  rows = sortAccounts(rows);
  tbody.innerHTML = rows.map(a => `
    <tr class="row" data-id="${a.id}">
      <td><div class="ent">${h(a.name)}</div><div class="muted">${h(a.market_center || 'Not mapped')}</div></td>
      <td>${h(a.state)}</td>
      <td>${h(a.account_type)}</td>
      <td class="num">${money(a.annualized_cost)}</td>
      <td class="num">${a.agent_count === null ? 'Not mapped' : a.agent_count}</td>
      <td class="num ${a.cost_per_agent_color ? 'pill p-'+a.cost_per_agent_color : ''}">${a.cost_per_agent === null ? '—' : money(a.cost_per_agent)}</td>
      <td>${decisionPill(a.suggested_decision)}</td>
      <td>${decisionPill(a.saved_decision)}</td>
      <td>${h(a.last_paid || '—')}</td>
      <td>${h(a.next_due || '—')}</td>
      <td class="muted">${h(a.owner_email || '—')}</td>
      <td><button class="btn danger sm js-del" type="button" title="Delete account">Delete</button></td>
    </tr>
  `).join('');
  tbody.querySelectorAll('tr.row').forEach(tr => tr.addEventListener('click', (e) => {
    if (e.target.closest('.js-del')) { e.stopPropagation(); deleteAccount(parseInt(tr.dataset.id)); return; }
    openAccountDetail(parseInt(tr.dataset.id));
  }));
}
['f-state','f-type','f-decision','f-code','f-all'].forEach(id => document.getElementById(id).addEventListener('change', loadAccounts));

function accountFormHtml(a){
  a = a || {};
  const mcOpts = MC_OPTIONS.map(mc => `<option value="${h(mc.slug)}" ${a.market_center===mc.slug?'selected':''}>${h(mc.name)}${mc.state_code?' ('+h(mc.state_code)+')':''}</option>`).join('');
  const mlsOpts = MLS_INTEGRATIONS.map(m => `<option value="${m.id}" ${a.mls_integration_id==m.id?'selected':''}>${h(m.mls_name)}</option>`).join('');
  const typeOpts = <?= json_encode(MLSAP_ACCOUNT_TYPES) ?>.map(t => `<option ${a.account_type===t?'selected':''}>${h(t)}</option>`).join('');
  const freqOpts = <?= json_encode(MLSAP_BILLING_FREQUENCIES) ?>.map(f => `<option ${a.billing_frequency===f?'selected':''}>${h(f)}</option>`).join('');
  return `
    <input type="hidden" id="acc-id" value="${a.id||''}">
    <div class="form-grid">
      <label class="full">Name *<input id="acc-name" value="${h(a.name)}"></label>
      <label>Type *<select id="acc-type">${typeOpts}</select></label>
      <label>State<input id="acc-state" maxlength="2" value="${h(a.state)}" style="text-transform:uppercase"></label>
      <label>Market Center<select id="acc-mc"><option value="">— Not mapped —</option>${mcOpts}</select></label>
      <label>MLS Integration<select id="acc-mls-int"><option value="">— None —</option>${mlsOpts}</select></label>
      <label>DR / BIC<input id="acc-dr" value="${h(a.designated_realtor_or_bic)}"></label>
      <label>Office ID / NRDS<input id="acc-nrds" value="${h(a.office_id_or_nrds)}"></label>
      <label class="full">Billing Site URL<input id="acc-url" value="${h(a.billing_site_url)}"></label>
      <label>Billing Frequency<select id="acc-freq">${freqOpts}</select></label>
      <label>Renewal Month (1-12)<input id="acc-renewal" type="number" min="1" max="12" value="${h(a.renewal_month)}"></label>
      <label style="display:flex;flex-direction:row;align-items:center;gap:6px;margin-top:16px"><input type="checkbox" id="acc-autopay" ${a.autopay?'checked':''}> Autopay</label>
      <label>Owner (email)<input id="acc-owner" value="${h(a.owner_email)}"></label>
      <label>Prior Year Volume<input id="acc-volume" inputmode="decimal" value="${h(a.prior_year_volume)}"></label>
      <label class="full">Notes<textarea id="acc-notes">${h(a.notes)}</textarea></label>
    </div>
    <button class="btn primary" id="btn-save-account-inline" type="button">Save Account</button>
    <span id="account-save-msg" class="muted"></span>
  `;
}
function bindAccountForm(afterSaveCb){
  document.getElementById('btn-save-account-inline').addEventListener('click', () => {
    const payload = {
      action: 'save_account',
      id: document.getElementById('acc-id').value || undefined,
      name: document.getElementById('acc-name').value.trim(),
      account_type: document.getElementById('acc-type').value,
      state: document.getElementById('acc-state').value.trim(),
      market_center: document.getElementById('acc-mc').value,
      mls_integration_id: document.getElementById('acc-mls-int').value,
      designated_realtor_or_bic: document.getElementById('acc-dr').value.trim(),
      office_id_or_nrds: document.getElementById('acc-nrds').value.trim(),
      billing_site_url: document.getElementById('acc-url').value.trim(),
      billing_frequency: document.getElementById('acc-freq').value,
      renewal_month: document.getElementById('acc-renewal').value,
      autopay: document.getElementById('acc-autopay').checked ? 1 : 0,
      owner_email: document.getElementById('acc-owner').value.trim(),
      prior_year_volume: document.getElementById('acc-volume').value,
      notes: document.getElementById('acc-notes').value.trim(),
    };
    api(payload).then(d => {
      if (!d.ok) { document.getElementById('account-save-msg').textContent = d.error; return; }
      flash('Account saved.', 'ok');
      loadAccounts();
      if (afterSaveCb) afterSaveCb(d.id);
    });
  });
}
document.getElementById('btn-new-account').addEventListener('click', () => {
  const card = document.getElementById('account-detail-card');
  card.classList.remove('hide');
  card.innerHTML = `<div class="card-h"><h2>New Account</h2></div><div class="detail">${accountFormHtml({})}</div>`;
  bindAccountForm(() => { card.classList.add('hide'); });
  card.scrollIntoView({behavior:'smooth'});
});

function openAccountDetail(id){
  apiGet('resource=account&id=' + id).then(d => {
    if (!d.ok) return;
    const a = d.account;
    const card = document.getElementById('account-detail-card');
    card.classList.remove('hide');
    const decOpts = <?= json_encode(MLSAP_DECISIONS) ?>.map(x => `<option ${a.saved_decision===x?'selected':''}>${h(x)}</option>`).join('');
    const billRows = d.bills.map(b => `
      <tr><td>${h(b.invoice_number||'—')}</td><td>${h(b.expense_code)}</td><td class="num">${money(b.amount)}</td>
      <td>${h(b.due_date||'—')}</td><td><span class="pill p-gray">${h(b.status)}</span></td><td>${h(b.paid_date||'—')}</td>
      <td>${h(b.darwin_status)}</td></tr>
    `).join('') || '<tr><td colspan="7" class="empty">No bills yet.</td></tr>';
    const decRows = d.decisions.map(x => `<tr><td>${h(x.decision)}</td><td>${h(x.reason)}</td><td>${h(x.decided_by)}</td><td>${h(x.decided_at)}</td></tr>`).join('')
      || '<tr><td colspan="4" class="empty">No decisions recorded.</td></tr>';
    card.innerHTML = `
      <div class="card-h"><h2>${h(a.name)}</h2><div class="tools">
        ${a.active == 1 ? '<button class="btn" id="btn-deactivate-account" type="button">Deactivate</button>' : '<span class="pill p-gray">Inactive</span>'}
        <button class="btn danger" id="btn-delete-account" type="button">Delete</button>
        <button class="btn" id="btn-close-detail" type="button">Close</button></div></div>
      <div class="detail">
        ${accountFormHtml(a)}
        <div style="margin-top:20px;border-top:1px solid var(--line);padding-top:16px">
          <h3 style="font-size:13px">Decision</h3>
          <div class="tools" style="margin-bottom:10px">
            <select id="dec-value">${decOpts}</select>
            <input id="dec-reason" placeholder="Reason" style="flex:1;min-width:200px">
            <button class="btn primary" id="btn-save-decision" type="button">Save Decision</button>
          </div>
          <table><thead><tr><th>Decision</th><th>Reason</th><th>By</th><th>When</th></tr></thead><tbody>${decRows}</tbody></table>
        </div>
        <div style="margin-top:20px;border-top:1px solid var(--line);padding-top:16px">
          <h3 style="font-size:13px">Bill History</h3>
          <table><thead><tr><th>Invoice</th><th>Code</th><th>Amount</th><th>Due</th><th>Status</th><th>Paid</th><th>Darwin</th></tr></thead><tbody>${billRows}</tbody></table>
        </div>
      </div>
    `;
    bindAccountForm(() => openAccountDetail(id));
    document.getElementById('btn-close-detail').addEventListener('click', () => card.classList.add('hide'));
    document.getElementById('btn-delete-account').addEventListener('click', () => deleteAccount(id));
    const deact = document.getElementById('btn-deactivate-account');
    if (deact) deact.addEventListener('click', () => deactivateAccount(id));
    document.getElementById('btn-save-decision').addEventListener('click', () => {
      api({action:'save_decision', account_id: id, decision: document.getElementById('dec-value').value, reason: document.getElementById('dec-reason').value})
        .then(r => { if (r.ok) { flash('Decision saved.', 'ok'); openAccountDetail(id); loadAccounts(); } else flash(r.error, 'err'); });
    });
    card.scrollIntoView({behavior:'smooth'});
  });
}

// ── Bills tab ────────────────────────────────────────────────────────────
function loadBillsTab(){
  const sel = document.getElementById('bill-account');
  sel.innerHTML = ACCOUNTS_CACHE.length ? ACCOUNTS_CACHE.map(a => `<option value="${a.id}">${h(a.name)}</option>`).join('')
    : '<option value="">(load Accounts tab first)</option>';
  reloadBillsList();
}
function reloadBillsList(){
  const status = document.getElementById('f-bill-status').value;
  apiGet('resource=bills_open_disputed_credit').then(d => {
    // bills_open_disputed_credit only covers non-Paid/Void bills; for a full
    // list including Paid/Void, Accounts > click an account shows full history.
    if (!d.ok) return;
    let rows = d.rows;
    if (status) rows = rows.filter(b => b.status === status);
    renderBillsList(rows);
  });
}
function renderBillsList(rows){
  const tbody = document.getElementById('bills-list');
  if (!rows.length) { tbody.innerHTML = '<tr><td colspan="9" class="empty">Nothing open, disputed, or credit-requested.</td></tr>'; return; }
  tbody.innerHTML = rows.map(b => `
    <tr>
      <td>${h(b.account_name)}</td><td>${h(b.invoice_number||'—')}</td><td>${h(b.expense_code)}</td>
      <td class="num">${money(b.amount)}</td><td>${h(b.due_date||'—')}</td>
      <td><span class="pill p-gray">${h(b.status)}</span></td><td>${h(b.darwin_status)}</td>
      <td>${b.receipt_name ? `<a href="#" onclick="downloadReceipt(${b.id});return false;">${h(b.receipt_name)}</a>` : `<input type="file" onchange="uploadReceipt(${b.id}, this.files[0])">`}</td>
      <td style="white-space:nowrap">
        <button class="btn" onclick="editBill(${b.id})" type="button">Edit</button>
        ${b.status !== 'Paid' ? `<button class="btn primary" onclick="markPaid(${b.id})" type="button">Mark Paid</button>` : ''}
      </td>
    </tr>
  `).join('');
}
document.getElementById('f-bill-status').addEventListener('change', reloadBillsList);
document.getElementById('btn-reload-bills').addEventListener('click', reloadBillsList);

function clearBillForm(){
  document.getElementById('bill-id').value = '';
  ['invoice-number','invoice-date','due-date','amount','period-start','period-end','notes'].forEach(f => document.getElementById('bill-'+f).value = '');
  document.getElementById('bill-status').value = 'Open';
  document.getElementById('bill-form-heading').textContent = 'Add Bill';
  document.getElementById('btn-cancel-bill').classList.add('hide');
}
document.getElementById('btn-cancel-bill').addEventListener('click', clearBillForm);
document.getElementById('btn-save-bill').addEventListener('click', () => {
  const payload = {
    action: 'save_bill',
    id: document.getElementById('bill-id').value || undefined,
    account_id: document.getElementById('bill-account').value,
    invoice_number: document.getElementById('bill-invoice-number').value,
    invoice_date: document.getElementById('bill-invoice-date').value,
    due_date: document.getElementById('bill-due-date').value,
    amount: document.getElementById('bill-amount').value,
    expense_code: document.getElementById('bill-code').value,
    period_start: document.getElementById('bill-period-start').value,
    period_end: document.getElementById('bill-period-end').value,
    status: document.getElementById('bill-status').value,
    notes: document.getElementById('bill-notes').value,
  };
  api(payload).then(d => {
    if (!d.ok) { document.getElementById('bill-msg').textContent = d.error; return; }
    flash('Bill saved.', 'ok');
    clearBillForm();
    reloadBillsList();
  });
});
window.editBill = function(id){
  apiGet('resource=bills_open_disputed_credit').then(d => {
    const b = d.rows.find(x => x.id === id);
    if (!b) return;
    document.getElementById('bill-id').value = b.id;
    document.getElementById('bill-account').value = b.account_id;
    document.getElementById('bill-invoice-number').value = b.invoice_number;
    document.getElementById('bill-invoice-date').value = b.invoice_date;
    document.getElementById('bill-due-date').value = b.due_date;
    document.getElementById('bill-amount').value = b.amount;
    document.getElementById('bill-code').value = b.expense_code;
    document.getElementById('bill-period-start').value = b.period_start;
    document.getElementById('bill-period-end').value = b.period_end;
    document.getElementById('bill-status').value = b.status;
    document.getElementById('bill-notes').value = b.notes;
    document.getElementById('bill-form-heading').textContent = 'Edit Bill';
    document.getElementById('btn-cancel-bill').classList.remove('hide');
    window.scrollTo({top:0, behavior:'smooth'});
  });
};
window.markPaid = function(id){
  const paidDate = prompt('Paid date (YYYY-MM-DD)?', new Date().toISOString().slice(0,10));
  if (!paidDate) return;
  api({action:'mark_paid', id, paid_date: paidDate}).then(d => {
    if (d.ok) { flash('Marked paid.', 'ok'); reloadBillsList(); loadDashboard(); } else flash(d.error, 'err');
  });
};
window.uploadReceipt = function(billId, file){
  if (!file) return;
  const fd = new FormData();
  fd.append('bill_id', billId);
  fd.append('file', file);
  fetch('api/mlsap_receipt_upload.php', {method:'POST', credentials:'same-origin', body: fd})
    .then(r=>r.json()).then(d => { if (d.ok) { flash('Receipt uploaded.', 'ok'); reloadBillsList(); } else flash(d.error, 'err'); });
};
window.downloadReceipt = function(billId){
  fetch('api/mlsap_receipt_download.php?bill_id=' + billId, {credentials:'same-origin'})
    .then(r=>r.json()).then(d => { if (d.url) window.open(d.url, '_blank'); else flash(d.error, 'err'); });
};

// ── Darwin queue ─────────────────────────────────────────────────────────
function loadDarwin(){
  apiGet('resource=darwin_queue').then(d => {
    if (!d.ok) return;
    const tbody = document.getElementById('darwin-list');
    const rows = [];
    Object.keys(d.queue).forEach(code => d.queue[code].forEach(b => rows.push({...b, code})));
    tbody.innerHTML = rows.length ? rows.map(b => `
      <tr><td><input type="checkbox" class="darwin-check" value="${b.id}"></td><td>${h(b.code)}</td>
      <td>${h(b.account_name)}</td><td>${h(b.invoice_number||'—')}</td><td class="num">${money(b.amount)}</td><td>${h(b.paid_date)}</td></tr>
    `).join('') : '<tr><td colspan="6" class="empty">Nothing waiting.</td></tr>';
  });
}
document.getElementById('darwin-select-all').addEventListener('change', (e) => {
  document.querySelectorAll('.darwin-check').forEach(cb => cb.checked = e.target.checked);
});
document.getElementById('btn-darwin-csv').addEventListener('click', () => { window.open('api/mlsap_action.php?resource=darwin_queue_csv', '_blank'); });
document.getElementById('btn-darwin-mark').addEventListener('click', () => {
  const ids = Array.from(document.querySelectorAll('.darwin-check:checked')).map(cb => parseInt(cb.value));
  if (!ids.length) { flash('Select at least one bill.', 'err'); return; }
  api({action:'mark_darwin_entered', ids}).then(d => {
    if (d.ok) { flash(`Marked ${d.count} bill(s) entered.`, 'ok'); loadDarwin(); } else flash(d.error, 'err');
  });
});

loadDashboard();
</script>
</body>
</html>
