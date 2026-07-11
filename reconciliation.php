<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/nav.php';

$agent = require_login();

$ALLOWED = ['darren@darrenwoodard.com', 'darren@innovateonline.com', 'michele@innovateonline.com'];
if (!in_array(strtolower($agent['email']), $ALLOWED, true)) {
    header('Location: index.php'); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bank Reconciliation — AgentEdge</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
<style>
.recon-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--faint)}
.upload-zone{border:2px dashed var(--border);border-radius:12px;padding:28px;text-align:center;cursor:pointer;
  transition:border-color .2s,background .2s;background:var(--surface)}
.upload-zone:hover,.upload-zone.drag{border-color:var(--green);background:#f9fdf5}
.upload-zone input[type=file]{display:none}
.upload-zone .uz-icon{font-size:28px;margin-bottom:8px}
.upload-zone .uz-label{font-weight:700;font-size:14px;color:var(--ink)}
.upload-zone .uz-sub{font-size:12px;color:var(--faint);margin-top:3px}
.upload-zone .uz-file{font-size:12px;font-weight:700;color:var(--green-d);margin-top:6px}
.btn-analyze{padding:12px 28px;background:var(--green);color:#111;border:0;border-radius:8px;
  font-size:15px;font-weight:800;cursor:pointer;display:flex;align-items:center;gap:8px}
.btn-analyze:hover{background:var(--green-d);color:#fff}
.btn-analyze:disabled{background:var(--border);color:var(--faint);cursor:not-allowed}
.btn-sm{padding:6px 14px;border:1px solid var(--border);background:#fff;border-radius:6px;
  font-size:12px;font-weight:700;cursor:pointer;color:var(--muted)}
.btn-sm:hover{border-color:var(--green);color:var(--green-d)}
.stat-band{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}
@media(max-width:700px){.stat-band{grid-template-columns:repeat(2,1fr)}}
.stat-chip{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px 16px}
.stat-chip .sc-val{font-size:22px;font-weight:800}
.stat-chip .sc-lbl{font-size:11px;color:var(--faint);margin-top:2px;text-transform:uppercase;letter-spacing:.05em}
.stat-chip.s-red .sc-val{color:var(--red)}
.stat-chip.s-green .sc-val{color:var(--green-d)}
.stat-chip.s-amber .sc-val{color:var(--amber)}
.tab-bar{display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:16px;overflow-x:auto}
.tab-btn{padding:9px 18px;border:0;background:none;font-size:13px;font-weight:700;
  color:var(--faint);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap}
.tab-btn:hover{color:var(--ink)}
.tab-btn.active{color:var(--green-d);border-bottom-color:var(--green)}
.tab-badge{display:inline-flex;align-items:center;justify-content:center;
  min-width:18px;height:18px;border-radius:9px;font-size:10px;font-weight:800;
  padding:0 5px;margin-left:6px;background:var(--border);color:var(--muted)}
.tab-badge.red{background:#fdecea;color:var(--red)}
.tab-badge.green{background:#eaf5e2;color:var(--green-d)}
.tab-badge.amber{background:#fdf5e0;color:var(--amber)}
.tab-panel{display:none}.tab-panel.active{display:block}
.tx-table{width:100%;border-collapse:collapse;font-size:13px}
.tx-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
  color:var(--faint);border-bottom:1px solid var(--border);padding:7px 10px;text-align:left}
.tx-table td{padding:9px 10px;border-bottom:1px solid #f2f2f2;vertical-align:middle}
.tx-table tr:last-child td{border-bottom:none}
.tx-table tr:hover td{background:#fafbfa}
.tx-table tr.tx-done td{background:#f5fbf0}
.tx-table tr.tx-done .tx-desc{text-decoration:line-through;color:var(--faint)}
.tx-date{font-size:11px;color:var(--faint);font-family:monospace;white-space:nowrap}
.tx-amt{font-family:monospace;font-weight:700;white-space:nowrap}
.tx-amt.out{color:var(--red)}.tx-amt.in{color:var(--green-d)}
.tx-check{width:22px;height:22px;accent-color:var(--green);cursor:pointer}
.conf-high{color:var(--green-d);font-weight:700;font-size:11px}
.conf-med{color:var(--amber);font-weight:700;font-size:11px}
.conf-low{color:var(--faint);font-size:11px}
.bal-panel{background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px}
.bal-row{display:flex;justify-content:space-between;align-items:center;
  padding:10px 0;border-bottom:1px solid #f2f2f2;font-size:14px}
.bal-row:last-child{border-bottom:none}
.bal-lbl{color:var(--muted)}
.bal-val{font-family:monospace;font-weight:700}
.bal-target{font-size:18px;color:var(--green-d)}
.bal-bad{font-size:18px;color:var(--red)}
.warn-box{background:#fffbeb;border:1px solid #f0d9a8;border-radius:8px;padding:10px 14px;
  font-size:12px;color:#8a6d1f;display:flex;gap:8px;align-items:flex-start;margin-top:10px}
.step-list{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:8px}
.step-item{display:grid;grid-template-columns:32px 1fr;gap:10px;align-items:flex-start;
  background:#fff;border:1px solid var(--border);border-radius:8px;padding:12px;cursor:pointer;
  user-select:none}
.step-item.done{background:#f5fbf0;border-color:#c6e9a0}
.step-num{width:28px;height:28px;border-radius:50%;background:#111;color:#fff;
  font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.step-item.done .step-num{background:var(--green-d)}
.step-title{font-size:13px;font-weight:700;line-height:1.3}
.step-item.done .step-title{text-decoration:line-through;color:var(--faint)}
.step-sub{font-size:12px;color:var(--faint);margin-top:2px}
.spinner{width:18px;height:18px;border:2px solid rgba(0,0,0,.1);border-top-color:var(--ink);
  border-radius:50%;animation:spin .7s linear infinite;display:none}
.spinner.active{display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}
.empty-note{text-align:center;padding:32px;color:var(--faint);font-style:italic;font-size:14px}
.progress-bar{height:4px;background:var(--border);border-radius:2px;margin:6px 0}
.progress-fill{height:100%;background:var(--green);border-radius:2px;transition:width .4s}
.darwin-path{display:inline-flex;align-items:center;background:#111;border-radius:4px;
  padding:4px 10px;gap:0;margin:6px 0 2px;flex-wrap:wrap}
.darwin-path .seg{font-family:monospace;font-size:11px;color:#93c5fd;font-weight:700}
.darwin-path .arr{font-size:10px;color:rgba(255,255,255,.25);padding:0 5px}
.enter-box{display:flex;align-items:center;gap:8px;background:#ebf5ff;border:1px solid #bbd6f5;
  border-radius:4px;padding:7px 12px;margin:5px 0 2px}
.enter-box .el{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
  color:#0369a1;flex-shrink:0;min-width:120px}
.enter-box .ev{font-family:monospace;font-size:13px;font-weight:700}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('reconciliation', $agent); ?>
<div class="content">

<div class="content-top">
  <div>
    <div class="recon-eyebrow">Back Office</div>
    <div class="content-title">Bank Reconciliation</div>
  </div>
  <div style="font-size:12px;color:var(--faint)">Darwin CSV ↔ CCNB Bank Statement</div>
</div>

<div class="wrap">

<!-- PREVIOUS REPORTS -->
<div class="card" style="margin-bottom:16px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
    <h2 style="margin:0">Previous Reports</h2>
    <span style="font-size:11px;color:var(--faint)">Click a report to open the saved analysis</span>
  </div>
  <a href="recon_report_202602.php" style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:var(--bg);border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--ink);transition:border-color .15s" onmouseover="this.style.borderColor='var(--green)'" onmouseout="this.style.borderColor='var(--border)'">
    <div style="display:flex;align-items:center;gap:12px">
      <div style="width:36px;height:36px;border-radius:8px;background:#fff;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:18px">🏦</div>
      <div>
        <div style="font-size:14px;font-weight:700">February 2026</div>
        <div style="font-size:12px;color:var(--faint);margin-top:1px">CCNB xxxxxx8788 · 1/31–2/27/2026 · 13 action items · 50 matched</div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <span style="font-size:11px;font-weight:700;background:#fdecea;color:var(--red);padding:2px 8px;border-radius:4px">13 to add</span>
      <span style="font-size:11px;font-weight:700;background:#eaf5e2;color:var(--green-d);padding:2px 8px;border-radius:4px">50 matched</span>
      <span style="color:var(--faint);font-size:16px">›</span>
    </div>
  </a>
</div>

<!-- UPLOAD CARD -->
<div class="card" id="uploadCard" style="margin-bottom:16px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
    <div>
      <h2 style="margin:0 0 3px">Upload Files</h2>
      <p style="font-size:12px;color:var(--faint);margin:0">Darwin CSV export + CCNB bank statement PDF. Files are processed in your browser — nothing is uploaded to the server.</p>
    </div>
    <div id="progressWrap" style="display:none;min-width:180px">
      <div style="font-size:11px;color:var(--faint);margin-bottom:3px" id="progressLabel">Analyzing…</div>
      <div class="progress-bar"><div class="progress-fill" id="progressFill" style="width:0%"></div></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px">
    <div class="upload-zone" id="zDarwin" onclick="document.getElementById('fDarwin').click()"
         ondragover="dragOver(event,'zDarwin')" ondragleave="dragLeave('zDarwin')" ondrop="dropFile(event,'fDarwin','zDarwin')">
      <input type="file" id="fDarwin" accept=".csv,text/csv" onchange="fileChosen('fDarwin','fDarwinName','zDarwin')">
      <div class="uz-icon">📊</div>
      <div class="uz-label">Darwin CSV Export</div>
      <div class="uz-sub">Reports → Reconciliation → Export CSV</div>
      <div class="uz-file" id="fDarwinName"></div>
    </div>
    <div class="upload-zone" id="zBank" onclick="document.getElementById('fBank').click()"
         ondragover="dragOver(event,'zBank')" ondragleave="dragLeave('zBank')" ondrop="dropFile(event,'fBank','zBank')">
      <input type="file" id="fBank" accept=".pdf,application/pdf" onchange="fileChosen('fBank','fBankName','zBank')">
      <div class="uz-icon">🏦</div>
      <div class="uz-label">CCNB Bank Statement PDF</div>
      <div class="uz-sub">CCNB Online Banking → Statements → Download PDF</div>
      <div class="uz-file" id="fBankName"></div>
    </div>
  </div>

  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <button class="btn-analyze" id="btnAnalyze" onclick="runAnalysis()" disabled>
      <span class="spinner" id="spinner"></span>
      <span id="btnLabel">Analyze</span>
    </button>
    <button class="btn-sm" id="btnReset" onclick="resetAll()" style="display:none">Clear &amp; Start Over</button>
    <div id="errorMsg" style="color:var(--red);font-size:13px;font-weight:600"></div>
  </div>
</div>

<!-- RESULTS -->
<div id="results" style="display:none">
  <div class="stat-band" id="statBand"></div>

  <div class="card" style="padding:0;overflow:hidden">
    <div class="tab-bar" style="padding:0 16px">
      <button class="tab-btn active" onclick="showTab('tabAction',this)">
        Action Required <span class="tab-badge red" id="badgeAction">0</span>
      </button>
      <button class="tab-btn" onclick="showTab('tabClear',this)">
        Clear in Darwin <span class="tab-badge green" id="badgeClear">0</span>
      </button>
      <button class="tab-btn" onclick="showTab('tabOut',this)">
        Outstanding <span class="tab-badge amber" id="badgeOut">0</span>
      </button>
      <button class="tab-btn" onclick="showTab('tabBalance',this)">Balance Check</button>
      <button class="tab-btn" onclick="showTab('tabWalk',this)">Walkthrough</button>
    </div>

    <div style="padding:16px">
      <div class="tab-panel active" id="tabAction">
        <p style="font-size:13px;color:var(--faint);margin:0 0 12px">These transactions hit the bank but have no matching Darwin entry. <strong>Add them to Darwin before opening the reconciliation screen.</strong></p>
        <div id="actionContent"></div>
      </div>
      <div class="tab-panel" id="tabClear">
        <p style="font-size:13px;color:var(--faint);margin:0 0 12px">These Darwin entries match the bank statement. Check each off as you mark it cleared in Darwin. Your progress is saved.</p>
        <div id="clearContent"></div>
      </div>
      <div class="tab-panel" id="tabOut">
        <p style="font-size:13px;color:var(--faint);margin:0 0 12px">In Darwin but not on the bank statement — checks outstanding, deposits in transit. <strong>Do not clear these.</strong> Darwin carries them forward.</p>
        <div id="outContent"></div>
      </div>
      <div class="tab-panel" id="tabBalance">
        <div id="balContent"></div>
      </div>
      <div class="tab-panel" id="tabWalk">
        <p style="font-size:13px;color:var(--faint);margin:0 0 14px">Follow these steps in Darwin after entering all missing transactions. Check each off as you go.</p>
        <div id="walkContent"></div>
      </div>
    </div>
  </div>
</div>

</div><!-- /wrap -->
</div><!-- /content -->
</div><!-- /layout -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" crossorigin="anonymous"></script>
<script>
if (typeof pdfjsLib !== 'undefined') {
  pdfjsLib.GlobalWorkerOptions.workerSrc =
    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
}

const ST = {};
const CHECKED = JSON.parse(localStorage.getItem('recon-checked') || '{}');
let darwinFile = null, bankFile = null;

// ── File helpers ───────────────────────────────────────────────
function dragOver(e, zid) { e.preventDefault(); document.getElementById(zid).classList.add('drag'); }
function dragLeave(zid) { document.getElementById(zid).classList.remove('drag'); }
function dropFile(e, fid, zid) {
  e.preventDefault(); dragLeave(zid);
  const f = e.dataTransfer.files[0]; if (!f) return;
  const input = document.getElementById(fid);
  const dt = new DataTransfer(); dt.items.add(f); input.files = dt.files;
  fileChosen(fid, fid + 'Name', zid);
}
function fileChosen(fid, nameid, zid) {
  const f = document.getElementById(fid).files[0]; if (!f) return;
  if (fid === 'fDarwin') darwinFile = f; else bankFile = f;
  document.getElementById(nameid).textContent = f.name;
  document.getElementById(zid).style.borderColor = 'var(--green)';
  updateAnalyzeBtn();
}
function updateAnalyzeBtn() {
  document.getElementById('btnAnalyze').disabled = !(darwinFile && bankFile);
}

// ── CSV parser ─────────────────────────────────────────────────
function parseCSV(text) {
  const rows = []; let row = [], field = '', inQ = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (inQ) {
      if (c === '"' && text[i+1] === '"') { field += '"'; i++; }
      else if (c === '"') inQ = false;
      else field += c;
    } else if (c === '"') { inQ = true; }
    else if (c === ',') { row.push(field.trim()); field = ''; }
    else if (c === '\n' || c === '\r') {
      if (field || row.length) { row.push(field.trim()); rows.push(row); row = []; field = ''; }
      if (c === '\r' && text[i+1] === '\n') i++;
    } else field += c;
  }
  if (field || row.length) { row.push(field.trim()); rows.push(row); }
  return rows;
}

function parseDate(s) {
  if (!s) return null;
  s = s.trim();
  let m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
  if (m) return new Date(+m[3], +m[1]-1, +m[2]);
  m = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (m) return new Date(+m[1], +m[2]-1, +m[3]);
  return null;
}
function fmtDate(d) {
  if (!d) return '';
  return (d.getMonth()+1).toString().padStart(2,'0') + '/' +
         d.getDate().toString().padStart(2,'0') + '/' + d.getFullYear();
}
function daysDiff(a, b) { if (!a || !b) return 999; return Math.abs((a - b) / 86400000); }

function parseDarwin(text) {
  const rows = parseCSV(text);
  const txs = [];
  for (const cols of rows) {
    if (cols.length < 6) continue;
    const id   = cols[0] || '';
    const type = (cols[1] || '').toLowerCase().trim();
    const dateStr = cols[2] || '';
    const ref  = cols[3] || '';
    const debitRaw  = (cols[4] || '').replace(/,/g,'');
    const creditRaw = (cols[5] || '').replace(/,/g,'');
    const descParts = cols.slice(9).filter(s => s.trim());
    const description = descParts.length ? descParts.join(' ').trim() :
                        cols.slice(6).filter(s => s.trim()).join(' ').trim();
    if (!dateStr || (!debitRaw && !creditRaw)) continue;
    const debit  = parseFloat(debitRaw)  || 0;
    const credit = parseFloat(creditRaw) || 0;
    const amount = debit || credit;
    if (!amount) continue;
    const isDebit = debit > 0;
    const date = parseDate(dateStr);
    if (!date) continue;
    txs.push({ id, type, date, ref, amount, isDebit, description, source:'darwin', matched:false });
  }
  return txs;
}

// ── CCNB PDF text parser ───────────────────────────────────────
function parseCCNB(text) {
  const lines = text.split('\n').map(l => l.trim());
  const txs = [];
  let section = '';

  const CREDIT_RE = /remote deposit|electronic credit|other credit|incoming wire|merchant card|woocommerce/i;
  const DEBIT_RE  = /electronic debit|other debit|service charge|online bill pay/i;
  const CHECK_RE  = /checks cleared|check paid/i;
  const END_RE    = /daily balance|interest earned|average balance|beginning balance|ending balance|account summary/i;
  const AMT_RE    = /([\d,]+\.\d{2})\s*$/;
  const DATE_RE   = /^(\d{1,2}\/\d{1,2}(?:\/\d{4})?)\s+/;

  let statYear = new Date().getFullYear();
  const ym = text.match(/\d{1,2}\/\d{1,2}\/(\d{4})/);
  if (ym) statYear = parseInt(ym[1]);

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    if (!line) continue;
    if (CREDIT_RE.test(line)) { section = 'credit'; continue; }
    if (DEBIT_RE.test(line))  { section = 'debit';  continue; }
    if (CHECK_RE.test(line))  { section = 'check';  continue; }
    if (END_RE.test(line))    { section = '';        continue; }
    if (!section) continue;

    const amtM = line.match(AMT_RE);
    if (!amtM) continue;
    const amount = parseFloat(amtM[1].replace(/,/g,''));
    if (!amount || amount > 9999999) continue;

    const lineNoAmt = line.slice(0, line.lastIndexOf(amtM[1])).trim().replace(/\$$/, '').trim();
    const dateM = lineNoAmt.match(DATE_RE);
    let date = null, description = lineNoAmt;

    if (dateM) {
      let ds = dateM[1];
      if (!ds.match(/\/\d{4}$/)) ds += '/' + statYear;
      date = parseDate(ds);
      description = lineNoAmt.slice(dateM[0].length).trim();
    }

    if (!description || description.length < 3) continue;
    if (/^(date|description|amount|balance|total|number)/i.test(description)) continue;
    const numAmts = (line.match(/[\d,]+\.\d{2}/g) || []).length;
    if (numAmts >= 3) continue;  // running balance line

    const isDebit = section === 'debit' || section === 'check';
    txs.push({ date, description: description || 'Bank transaction', amount, isDebit, source:'bank', matched:false });
  }
  return txs;
}

function parseBalances(text) {
  const r = { opening:null, closing:null, totalCredits:null, totalDebits:null };
  let m;
  m = text.match(/beginning balance[^$\d]*([\d,]+\.\d{2})/i); if (m) r.opening = parseFloat(m[1].replace(/,/g,''));
  m = text.match(/ending balance[^$\d]*([\d,]+\.\d{2})/i);    if (m) r.closing = parseFloat(m[1].replace(/,/g,''));
  m = text.match(/total.*?credits?[^$\d]*([\d,]+\.\d{2})/i);  if (m) r.totalCredits = parseFloat(m[1].replace(/,/g,''));
  m = text.match(/total.*?debits?[^$\d]*([\d,]+\.\d{2})/i);   if (m) r.totalDebits  = parseFloat(m[1].replace(/,/g,''));
  return r;
}

// ── Matching ───────────────────────────────────────────────────
function matchTransactions(darwin, bank) {
  const darwinCopy = darwin.map(t => ({...t, matched:false}));
  const bankCopy   = bank.map(t => ({...t, matched:false}));
  const matched = [];

  function kw(s) { return (s||'').toLowerCase().replace(/[^a-z0-9\s]/g,'').split(/\s+/).filter(w=>w.length>2); }
  function overlap(a, b) {
    const wa = new Set(kw(a)); let n = 0;
    kw(b).forEach(w => { if (wa.has(w)) n++; });
    return n;
  }

  for (const bTx of bankCopy) {
    if (bTx.matched) continue;
    let best = null, bestScore = -1;
    for (const dTx of darwinCopy) {
      if (dTx.matched) continue;
      if (dTx.isDebit !== bTx.isDebit) continue;
      if (Math.abs(dTx.amount - bTx.amount) > 0.02) continue;
      const days = daysDiff(dTx.date, bTx.date);
      if (days > 7) continue;
      const score = 100 - (days * 8) + (overlap(dTx.description, bTx.description) * 10);
      if (score > bestScore) { best = dTx; bestScore = score; }
    }
    if (best) {
      const days = daysDiff(best.date, bTx.date);
      const conf = days <= 1 ? 'HIGH' : days <= 4 ? 'MED' : 'LOW';
      matched.push({ bank:bTx, darwin:best, confidence:conf, days });
      bTx.matched = true;
      best.matched = true;
    }
  }
  return { matched, unrecorded: bankCopy.filter(t=>!t.matched), outstanding: darwinCopy.filter(t=>!t.matched) };
}

// ── File readers ───────────────────────────────────────────────
function readFileAsText(file) {
  return new Promise((res,rej) => { const fr = new FileReader(); fr.onload=e=>res(e.target.result); fr.onerror=rej; fr.readAsText(file); });
}
async function extractPDFText(file) {
  if (typeof pdfjsLib === 'undefined') throw new Error('PDF.js failed to load. Check your internet connection.');
  const ab = await file.arrayBuffer();
  const pdf = await pdfjsLib.getDocument({data:ab}).promise;
  let text = '';
  for (let p = 1; p <= pdf.numPages; p++) {
    const page = await pdf.getPage(p);
    const tc = await page.getTextContent();
    const items = tc.items.sort((a,b) => {
      const dy = Math.round(b.transform[5]) - Math.round(a.transform[5]);
      return dy !== 0 ? dy : a.transform[4] - b.transform[4];
    });
    let lastY = null;
    for (const item of items) {
      const y = Math.round(item.transform[5]);
      if (lastY !== null && Math.abs(y - lastY) > 3) text += '\n';
      text += item.str + ' ';
      lastY = y;
    }
    text += '\n';
  }
  return text;
}

// ── Main runner ────────────────────────────────────────────────
async function runAnalysis() {
  const btn = document.getElementById('btnAnalyze');
  const errEl = document.getElementById('errorMsg');
  errEl.textContent = '';
  btn.disabled = true;
  document.getElementById('spinner').classList.add('active');
  document.getElementById('btnLabel').textContent = 'Analyzing…';

  try {
    setProgress(10, 'Reading Darwin CSV…');
    const darwinText = await readFileAsText(darwinFile);
    const darwin = parseDarwin(darwinText);
    if (!darwin.length) throw new Error('No transactions found in Darwin CSV. Check that you exported the right file.');

    setProgress(35, 'Extracting PDF text…');
    const bankText = await extractPDFText(bankFile);

    setProgress(60, 'Parsing bank transactions…');
    const bank = parseCCNB(bankText);
    const balances = parseBalances(bankText);

    setProgress(80, 'Matching…');
    const { matched, unrecorded, outstanding } = matchTransactions(darwin, bank);

    setProgress(95, 'Building report…');
    Object.assign(ST, { darwin, bank, matched, unrecorded, outstanding, balances });

    renderResults();
    setProgress(100, 'Done');
    document.getElementById('results').style.display = 'block';
    document.getElementById('btnReset').style.display = 'inline-block';
    document.getElementById('results').scrollIntoView({behavior:'smooth', block:'start'});

    if (!bank.length) {
      errEl.textContent = 'Warning: no transactions were parsed from the bank PDF. The CCNB PDF format may have changed. Contact support or check the PDF is a text-based (not scanned) statement.';
    }

  } catch(e) {
    errEl.textContent = e.message || 'Error processing files.';
    console.error(e);
  } finally {
    document.getElementById('spinner').classList.remove('active');
    document.getElementById('btnLabel').textContent = 'Re-analyze';
    btn.disabled = false;
    setTimeout(() => document.getElementById('progressWrap').style.display='none', 2000);
  }
}

function setProgress(pct, label) {
  document.getElementById('progressWrap').style.display = 'block';
  document.getElementById('progressLabel').textContent = label;
  document.getElementById('progressFill').style.width = pct + '%';
}

// ── Render ─────────────────────────────────────────────────────
function fmt(n) { return n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function renderResults() {
  const {matched, unrecorded, outstanding} = ST;
  document.getElementById('statBand').innerHTML = `
    <div class="stat-chip s-red"><div class="sc-val">${unrecorded.length}</div><div class="sc-lbl">Add to Darwin</div></div>
    <div class="stat-chip s-green"><div class="sc-val">${matched.length}</div><div class="sc-lbl">Matched</div></div>
    <div class="stat-chip s-amber"><div class="sc-val">${outstanding.length}</div><div class="sc-lbl">Outstanding</div></div>
    <div class="stat-chip"><div class="sc-val">${ST.darwin.length + ST.bank.length}</div><div class="sc-lbl">Total Lines</div></div>`;
  document.getElementById('badgeAction').textContent = unrecorded.length;
  document.getElementById('badgeClear').textContent  = matched.length;
  document.getElementById('badgeOut').textContent    = outstanding.length;
  renderActionTab();
  renderClearTab();
  renderOutTab();
  renderBalanceTab();
  renderWalkTab();
}

function renderActionTab() {
  const el = document.getElementById('actionContent');
  if (!ST.unrecorded.length) { el.innerHTML='<div class="empty-note">No unrecorded bank transactions. All bank items are in Darwin.</div>'; return; }
  const sorted = [...ST.unrecorded].sort((a,b)=> a.isDebit===b.isDebit ? 0 : (a.isDebit?-1:1));
  let html = '<div style="overflow-x:auto"><table class="tx-table"><thead><tr><th>Bank Date</th><th>Description</th><th>Amount</th><th>Action</th></tr></thead><tbody>';
  for (const t of sorted) {
    const ac = t.isDebit?'out':'in', sign=t.isDebit?'−':'+';
    const action = t.isDebit ? 'Add as expense in Darwin' : 'Add as deposit in Darwin';
    html += `<tr><td class="tx-date">${t.date?fmtDate(t.date):'—'}</td><td class="tx-desc" style="font-size:12px">${esc(t.description)}</td><td class="tx-amt ${ac}">${sign}$${fmt(t.amount)}</td><td style="font-size:11px;color:var(--muted)">${action}</td></tr>`;
  }
  html += '</tbody></table></div>';
  el.innerHTML = html;
}

function clrKey(m) { return (m.darwin.id||'') + '-' + fmt(m.darwin.amount).replace('.',''); }

function renderClearTab() {
  const el = document.getElementById('clearContent');
  if (!ST.matched.length) { el.innerHTML='<div class="empty-note">No matched transactions found. Make sure both files cover the same period.</div>'; return; }
  const sorted = [...ST.matched].sort((a,b) => !a.bank.date||!b.bank.date ? 0 : a.bank.date-b.bank.date);
  const doneCount = sorted.filter(m => CHECKED['clr-'+clrKey(m)]).length;
  const pct = Math.round((doneCount/sorted.length)*100);
  let html = `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px;flex-wrap:wrap">
    <div style="font-size:12px;color:var(--faint)">${doneCount} of ${sorted.length} marked cleared</div>
    <div style="width:140px"><div class="progress-bar"><div class="progress-fill" style="width:${pct}%"></div></div></div>
  </div><div style="overflow-x:auto"><table class="tx-table"><thead><tr>
    <th style="width:36px">Done</th><th>Bank Date</th><th>Darwin Date</th><th>Description</th><th>Amount</th><th>Match</th>
  </tr></thead><tbody>`;
  for (const m of sorted) {
    const key = 'clr-'+clrKey(m);
    const done = !!CHECKED[key];
    const confCls = m.confidence==='HIGH'?'conf-high':m.confidence==='MED'?'conf-med':'conf-low';
    const confLbl = m.confidence==='HIGH'?'✓ High':m.confidence==='MED'?'~ Medium':'? Low';
    const ac = m.bank.isDebit?'out':'in', sign=m.bank.isDebit?'−':'+';
    html += `<tr class="${done?'tx-done':''}" id="row-${key}"><td style="text-align:center"><input type="checkbox" class="tx-check" id="chk-${key}" ${done?'checked':''} onchange="toggleClear('${key}')"></td><td class="tx-date">${m.bank.date?fmtDate(m.bank.date):'—'}</td><td class="tx-date">${m.darwin.date?fmtDate(m.darwin.date):'—'}</td><td class="tx-desc" style="font-size:12px;max-width:240px">${esc(m.darwin.description||m.bank.description)}</td><td class="tx-amt ${ac}">${sign}$${fmt(m.bank.amount)}</td><td class="${confCls}">${confLbl}</td></tr>`;
  }
  html += '</tbody></table></div>';
  el.innerHTML = html;
}

function toggleClear(key) {
  const cb = document.getElementById('chk-'+key);
  const row = document.getElementById('row-'+key);
  CHECKED[key] = cb.checked;
  localStorage.setItem('recon-checked', JSON.stringify(CHECKED));
  cb.checked ? row.classList.add('tx-done') : row.classList.remove('tx-done');
  renderClearTab();
}

function renderOutTab() {
  const el = document.getElementById('outContent');
  if (!ST.outstanding.length) { el.innerHTML='<div class="empty-note">All Darwin transactions matched to the bank statement.</div>'; return; }
  let html = '<div style="overflow-x:auto"><table class="tx-table"><thead><tr><th>Darwin Date</th><th>Type</th><th>Description</th><th>Amount</th><th>Status</th></tr></thead><tbody>';
  for (const t of ST.outstanding) {
    const ac = t.isDebit?'out':'in', sign=t.isDebit?'−':'+';
    const note = t.isDebit ? 'Check not yet cleared' : 'Deposit in transit';
    html += `<tr><td class="tx-date">${t.date?fmtDate(t.date):'—'}</td><td style="font-size:11px;color:var(--faint)">${esc(t.type)}</td><td class="tx-desc" style="font-size:12px">${esc(t.description)}</td><td class="tx-amt ${ac}">${sign}$${fmt(t.amount)}</td><td style="font-size:11px;color:var(--muted)">${note}</td></tr>`;
  }
  html += '</tbody></table></div>';
  el.innerHTML = html;
}

function renderBalanceTab() {
  const {balances, outstanding, unrecorded} = ST;
  const el = document.getElementById('balContent');
  const outDebits = outstanding.filter(t=>t.isDebit).reduce((s,t)=>s+t.amount, 0);
  const hasBal = balances.opening !== null && balances.closing !== null;

  let html = '<div class="bal-panel">';
  if (hasBal) {
    html += `<div class="bal-row"><span class="bal-lbl">Statement Beginning Balance</span><span class="bal-val">$${fmt(balances.opening)}</span></div>`;
    html += `<div class="bal-row"><span class="bal-lbl">Statement Ending Balance</span><span class="bal-val">$${fmt(balances.closing)}</span></div>`;
    if (balances.totalCredits) html += `<div class="bal-row"><span class="bal-lbl">Total Credits</span><span class="bal-val" style="color:var(--green-d)">+$${fmt(balances.totalCredits)}</span></div>`;
    if (balances.totalDebits)  html += `<div class="bal-row"><span class="bal-lbl">Total Debits</span><span class="bal-val" style="color:var(--red)">−$${fmt(balances.totalDebits)}</span></div>`;
  } else {
    html += '<div class="bal-row"><span class="bal-lbl" style="color:var(--amber)">⚠ Could not extract balances automatically — enter manually:</span></div>';
    html += `<div style="display:flex;gap:10px;flex-wrap:wrap;margin:12px 0">
      <label style="font-size:12px;font-weight:700;color:var(--faint);display:flex;flex-direction:column;gap:4px">Beginning Balance
        <input type="number" id="manualOpen" step="0.01" style="padding:8px;border:1px solid var(--border);border-radius:6px;font-size:14px;width:160px"></label>
      <label style="font-size:12px;font-weight:700;color:var(--faint);display:flex;flex-direction:column;gap:4px">Ending Balance
        <input type="number" id="manualClose" step="0.01" style="padding:8px;border:1px solid var(--border);border-radius:6px;font-size:14px;width:160px"></label>
      <div style="display:flex;align-items:flex-end"><button class="btn-sm" onclick="applyManualBal()">Apply</button></div>
    </div>`;
  }
  html += `<div class="bal-row" style="margin-top:8px;border-top:2px solid var(--border);padding-top:12px"><span class="bal-lbl">Outstanding checks (will carry forward)</span><span class="bal-val" style="color:var(--amber)">−$${fmt(outDebits)}</span></div>`;
  html += `<div class="bal-row"><span class="bal-lbl">Unrecorded bank items (add to Darwin first)</span><span class="bal-val" style="color:var(--red)">${unrecorded.length} items</span></div>`;

  if (hasBal) {
    const diff = balances.closing - balances.opening - (balances.totalCredits||0) + (balances.totalDebits||0);
    const isDiff = Math.abs(diff) > 0.02;
    html += `<div class="bal-row" style="margin-top:4px"><span class="bal-lbl" style="font-weight:700">Reconciliation Difference (target: $0.00)</span><span class="bal-val ${isDiff?'bal-bad':'bal-target'}">${isDiff?'⚠ ':'✓ '}$${fmt(Math.abs(diff))}</span></div>`;
    if (isDiff) html += `<div class="warn-box">⚠ Non-zero difference usually means Darwin is missing entries. Add all "Action Required" items, then re-analyze.</div>`;
  }
  html += '</div>';
  el.innerHTML = html;
}

function applyManualBal() {
  const o = parseFloat(document.getElementById('manualOpen').value);
  const c = parseFloat(document.getElementById('manualClose').value);
  if (!isNaN(o)) ST.balances.opening = o;
  if (!isNaN(c)) ST.balances.closing = c;
  renderBalanceTab();
}

function renderWalkTab() {
  const {balances, unrecorded, outstanding, matched} = ST;
  const steps = []; let n = 1;

  if (unrecorded.length) steps.push({n:n++, title:`Add ${unrecorded.length} missing transaction${unrecorded.length>1?'s':''} to Darwin`, sub:'See the Action Required tab. Enter each one before opening the reconciliation screen.', key:'walk-missing'});
  if (outstanding.filter(t=>t.isDebit).length) steps.push({n:n++, title:'Confirm outstanding checks are intentional', sub:'Any check older than 60 days should be followed up. Void and reissue if the payee hasn\'t deposited it.', key:'walk-outstanding'});
  steps.push({n:n++, title:'Open Bank Reconciliation in Darwin', path:['Banking','Bank Accounts','Select Operating Account','Reconcile'], key:'walk-open'});
  steps.push({n:n++, title:'Enter the statement date and ending balance',
    enters:[
      {label:'Statement Date', value: balances.closing !== null ? 'From your bank statement header' : 'MM/DD/YYYY'},
      {label:'Ending Balance', value: balances.closing !== null ? '$'+fmt(balances.closing) : 'From your bank statement'},
      {label:'Beginning Balance', value: balances.opening !== null ? '$'+fmt(balances.opening) : 'From your bank statement'},
    ], key:'walk-enter'});
  steps.push({n:n++, title:`Mark ${matched.length} matched transactions as cleared`, sub:'Use the "Clear in Darwin" tab to track progress. Find each row in Darwin\'s uncleared list and check it off.', key:'walk-clear'});
  if (outstanding.length) steps.push({n:n++, title:`Leave ${outstanding.length} outstanding transaction${outstanding.length>1?'s':''} unchecked`, sub:'Do not mark these cleared — they are not on this bank statement.', key:'walk-skip'});
  steps.push({n:n++, title:'Verify the difference shows $0.00', sub:'If not zero: a Darwin entry is missing or has the wrong amount. Go back to Action Required.', key:'walk-diff'});
  steps.push({n:n++, title:'Click Post / Finish to complete the reconciliation', path:['Reconciliation screen','Post Reconciliation'], key:'walk-post'});
  steps.push({n:n++, title:'Save and file the reconciliation report', sub:'Print or export as PDF. File it with the bank statement for the month.', key:'walk-save'});

  let html = '<ul class="step-list">';
  for (const s of steps) {
    const done = !!CHECKED['ws-'+s.key];
    html += `<li class="step-item ${done?'done':''}" id="ws-${s.key}" onclick="toggleWalk('ws-${s.key}')">
      <div class="step-num" data-n="${s.n}">${done?'✓':s.n}</div>
      <div>
        <div class="step-title">${esc(s.title)}</div>
        ${s.sub?`<div class="step-sub">${esc(s.sub)}</div>`:''}
        ${s.path?`<div class="darwin-path">${s.path.map((p,i)=>`<span class="seg">${esc(p)}</span>${i<s.path.length-1?'<span class="arr">›</span>':''}`).join('')}</div>`:''}
        ${s.enters?s.enters.map(e=>`<div class="enter-box"><span class="el">${esc(e.label)}</span><span class="ev">${esc(e.value)}</span></div>`).join(''):''}
      </div>
    </li>`;
  }
  html += '</ul>';
  document.getElementById('walkContent').innerHTML = html;
}

function toggleWalk(id) {
  const el = document.getElementById(id);
  el.classList.toggle('done');
  const numEl = el.querySelector('.step-num');
  const key = id.replace('ws-','');
  CHECKED['ws-'+key] = el.classList.contains('done');
  localStorage.setItem('recon-checked', JSON.stringify(CHECKED));
  numEl.textContent = el.classList.contains('done') ? '✓' : (numEl.dataset.n || '');
}

function showTab(panelId, btn) {
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById(panelId).classList.add('active');
  btn.classList.add('active');
}

function resetAll() {
  darwinFile = null; bankFile = null;
  ['fDarwin','fBank'].forEach(id => document.getElementById(id).value='');
  ['fDarwinName','fBankName'].forEach(id => document.getElementById(id).textContent='');
  ['zDarwin','zBank'].forEach(id => document.getElementById(id).style.borderColor='');
  document.getElementById('results').style.display = 'none';
  document.getElementById('btnReset').style.display = 'none';
  document.getElementById('btnLabel').textContent = 'Analyze';
  document.getElementById('errorMsg').textContent = '';
  updateAnalyzeBtn();
}
</script>
</body>
</html>
