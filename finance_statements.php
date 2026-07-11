<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
$agent = require_login();
$perms = current_perms();
if (empty($perms['isAdmin'])) { header('Location: index.php'); exit; }

$db = local_db();
$scans = $db->query("SELECT id, account_label, scan_type, uploaded_by, uploaded_at, status, analysis_json
                     FROM statement_scans ORDER BY uploaded_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);

$viewScan = null;
if (!empty($_GET['scan'])) {
    $st = $db->prepare("SELECT * FROM statement_scans WHERE id=?");
    $st->execute([(int)$_GET['scan']]);
    $viewScan = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($viewScan && $viewScan['analysis_json']) {
        $viewScan['analysis'] = json_decode($viewScan['analysis_json'], true);
    }
}

$hasKey = !empty(cfg()['anthropic_api_key']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Statement Scanner — AgentEdge</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
<style>
.fin-eyebrow { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--faint); }

/* ── upload zone ── */
.upload-zone-wrap { background:#fff; border:1px solid var(--border); border-radius:12px; padding:20px; margin-bottom:20px; }
.drop-zone { border:2px dashed #c8e6a0; border-radius:10px; padding:40px 24px; text-align:center;
             cursor:pointer; transition:border-color .15s,background .15s; background:#fafdf5; position:relative; }
.drop-zone:hover, .drop-zone.drag-over { border-color:var(--green); background:#f4f9ec; }
.drop-zone input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
.drop-icon { font-size:36px; margin-bottom:10px; }
.drop-label { font-size:16px; font-weight:700; color:var(--ink); margin-bottom:4px; }
.drop-hint  { font-size:13px; color:var(--muted); }
.drop-formats { display:flex; gap:6px; justify-content:center; margin-top:10px; flex-wrap:wrap; }
.drop-fmt { font-size:11px; font-weight:700; padding:2px 8px; border-radius:4px; background:#e8f5d0; color:#3a6b00; }

.file-preview { display:none; align-items:center; gap:10px; padding:12px 16px; background:#f4f9ec;
                border:1px solid #c8e6a0; border-radius:8px; margin-top:12px; }
.file-preview.show { display:flex; }
.fp-icon  { font-size:24px; }
.fp-name  { font-size:14px; font-weight:600; flex:1; }
.fp-size  { font-size:12px; color:var(--muted); }
.fp-clear { background:none; border:none; cursor:pointer; color:var(--faint); font-size:16px; padding:2px 6px;
            border-radius:4px; line-height:1; }
.fp-clear:hover { background:#fdecea; color:#d73a49; }

.upload-fields { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:16px; }
@media(max-width:560px){ .upload-fields { grid-template-columns:1fr; } }
.field label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--faint); margin-bottom:4px; }
.field input, .field select { width:100%; padding:8px 10px; border:1px solid var(--border); border-radius:8px; font-size:14px; box-sizing:border-box; }
.field input:focus, .field select:focus { outline:2px solid var(--green); }

.paste-toggle { font-size:12px; color:var(--green-d); cursor:pointer; font-weight:600; margin-top:10px; display:inline-block; }
.paste-toggle:hover { text-decoration:underline; }
.paste-area { width:100%; min-height:100px; padding:10px 12px; border:1px solid var(--border); border-radius:8px;
              font-size:12px; font-family:monospace; box-sizing:border-box; resize:vertical; background:#fafafa;
              margin-top:8px; display:none; }
.paste-area.open { display:block; }
.paste-area:focus { outline:2px solid var(--green); background:#fff; }

.btn-analyze { margin-top:16px; padding:11px 28px; background:var(--green); color:#111; border:0; border-radius:8px;
               font-weight:800; font-size:14px; cursor:pointer; display:flex; align-items:center; gap:8px; }
.btn-analyze:hover { background:var(--green-d); color:#fff; }
.btn-analyze:disabled { opacity:.45; cursor:not-allowed; }
.spin { width:16px; height:16px; border:2px solid rgba(0,0,0,.18); border-top-color:#111;
        border-radius:50%; animation:spin .6s linear infinite; display:none; flex-shrink:0; }
.btn-analyze.loading .spin { display:block; }
.btn-analyze.loading .btn-label { opacity:.65; }
@keyframes spin { to { transform:rotate(360deg); } }

.no-key { background:#fff8e1; border:1px solid #f5c842; border-radius:8px; padding:10px 14px; font-size:12px; color:#7a5700; margin-bottom:12px; }

/* ── savings results ── */
.results-wrap { margin-bottom:28px; }

.savings-hero { background:linear-gradient(135deg,#2e7d32 0%,#5b8e0d 100%); color:#fff; border-radius:12px;
                padding:24px 28px; margin-bottom:20px; display:flex; align-items:center; gap:20px; flex-wrap:wrap; }
.savings-hero-main { flex:1; min-width:180px; }
.savings-hero-label { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; opacity:.8; margin-bottom:4px; }
.savings-hero-amount { font-size:42px; font-weight:900; line-height:1; }
.savings-hero-sub { font-size:13px; opacity:.8; margin-top:4px; }
.savings-hero-chips { display:flex; gap:10px; flex-wrap:wrap; }
.sh-chip { background:rgba(255,255,255,.2); border-radius:8px; padding:10px 16px; text-align:center; min-width:80px; }
.sh-chip-val { font-size:20px; font-weight:800; }
.sh-chip-label { font-size:11px; opacity:.8; margin-top:2px; }

.savings-section-title { font-size:14px; font-weight:800; text-transform:uppercase; letter-spacing:.06em;
                          color:var(--faint); margin-bottom:12px; margin-top:24px; }

.savings-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; margin-bottom:24px; }
.saving-card { background:#fff; border:1px solid var(--border); border-radius:10px; padding:18px; position:relative; overflow:hidden; }
.saving-card::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; }
.saving-card.priority-high::before   { background:#d73a49; }
.saving-card.priority-medium::before { background:#f5a623; }
.saving-card.priority-low::before    { background:var(--green); }
.saving-card-top { display:flex; align-items:flex-start; gap:10px; margin-bottom:10px; }
.saving-cat { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--faint); }
.saving-vendor { font-size:11px; font-weight:600; color:var(--green-d); margin-top:2px; }
.saving-amount-box { text-align:right; flex-shrink:0; }
.saving-amount { font-size:20px; font-weight:900; color:#2e7d32; white-space:nowrap; }
.saving-amount-label { font-size:10px; color:var(--faint); white-space:nowrap; }
.saving-title  { font-size:14px; font-weight:700; margin-bottom:6px; color:var(--ink); }
.saving-detail { font-size:12px; color:var(--muted); line-height:1.55; margin-bottom:10px; }
.saving-action { font-size:12px; font-weight:600; color:#111; background:#f0f9e4; border-radius:6px; padding:6px 10px;
                 border-left:3px solid var(--green); }
.saving-effort { display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em;
                 padding:2px 7px; border-radius:4px; margin-top:8px; }
.effort-low    { background:#e8f5d0; color:#2e7d32; }
.effort-medium { background:#fff3cd; color:#856404; }
.effort-high   { background:#fdecea; color:#c0392b; }

/* ── spending breakdown ── */
.breakdown-card { background:#fff; border:1px solid var(--border); border-radius:10px; overflow:hidden; margin-bottom:16px; }
.breakdown-hdr { padding:12px 16px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:8px; }
.breakdown-hdr h3 { margin:0; font-size:14px; font-weight:700; flex:1; }
.cat-table { width:100%; border-collapse:collapse; font-size:13px; }
.cat-table th { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--faint);
                padding:8px 14px; border-bottom:1px solid var(--border); text-align:left; }
.cat-table td { padding:9px 14px; border-bottom:1px solid var(--border); }
.cat-table tr:last-child td { border-bottom:none; }
.cat-bar-wrap { display:flex; align-items:center; gap:8px; }
.cat-bar { height:8px; background:var(--green); border-radius:4px; min-width:3px; }

/* ── transactions ── */
.tx-toggle { font-size:13px; color:var(--green-d); cursor:pointer; font-weight:600; display:inline-flex;
             align-items:center; gap:4px; padding:6px 0; }
.tx-toggle:hover { text-decoration:underline; }
.tx-table-wrap { display:none; max-height:320px; overflow-y:auto; border:1px solid var(--border); border-radius:8px; margin-top:8px; }
.tx-table-wrap.open { display:block; }
.tx-table { width:100%; border-collapse:collapse; font-size:12px; }
.tx-table th { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--faint);
               padding:7px 12px; border-bottom:1px solid var(--border); background:#fafafa; position:sticky; top:0; }
.tx-table td { padding:7px 12px; border-bottom:1px solid var(--border); }
.tx-table tr:last-child td { border-bottom:none; }

/* ── summary card ── */
.scan-summary-card { background:#f8fef2; border:1px solid #c8e6a0; border-radius:8px; padding:12px 16px;
                     font-size:13px; color:#3a6b00; line-height:1.6; margin-bottom:20px; }

/* ── history ── */
.scan-history h3 { font-size:13px; font-weight:700; color:var(--muted); margin-bottom:10px; text-transform:uppercase; letter-spacing:.05em; }
.scan-row { display:flex; align-items:center; gap:10px; padding:9px 12px; border:1px solid var(--border);
            border-radius:8px; margin-bottom:7px; background:#fff; font-size:13px; flex-wrap:wrap; }
.scan-badge { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em;
              padding:2px 7px; border-radius:4px; flex-shrink:0; }
.scan-badge.bank { background:#e8f5d0; color:#3a6b00; }
.scan-badge.credit_card { background:#e8f0ff; color:#2c3e8a; }
.scan-name { font-weight:600; color:var(--ink); flex:1; text-decoration:none; }
.scan-name:hover { text-decoration:underline; color:var(--green-d); }
.scan-savings { font-size:12px; font-weight:700; color:#2e7d32; }
.scan-date { font-size:11px; color:var(--faint); }
.scan-del { background:none; border:none; cursor:pointer; color:var(--faint); font-size:14px;
            padding:2px 6px; border-radius:4px; line-height:1; margin-left:auto; }
.scan-del:hover { background:#fdecea; color:#d73a49; }
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('finance_statements', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="fin-eyebrow">Back Office / Finance</div>
      <div class="content-title">Statement Scanner</div>
    </div>
    <div class="content-hello">Drop a bank or credit card statement — AI finds where you can save money</div>
  </div>
  <div class="wrap">

    <?php if (!$hasKey): ?>
    <div class="no-key">
      <strong>Anthropic API key not configured.</strong>
      Add <code>anthropic_api_key</code> to <code>config.php</code> on the server.
      Get a key at <strong>console.anthropic.com</strong>.
    </div>
    <?php endif; ?>

    <!-- Upload zone -->
    <div class="upload-zone-wrap">
      <div class="drop-zone" id="dropZone">
        <input type="file" id="fileInput" accept=".pdf,.png,.jpg,.jpeg,.csv,.txt" onchange="handleFile(this.files[0])">
        <div class="drop-icon">📄</div>
        <div class="drop-label">Drop your statement here</div>
        <div class="drop-hint">or click to browse</div>
        <div class="drop-formats">
          <span class="drop-fmt">PDF</span>
          <span class="drop-fmt">PNG / JPG</span>
          <span class="drop-fmt">CSV</span>
        </div>
      </div>

      <div class="file-preview" id="filePreview">
        <span class="fp-icon" id="fpIcon">📄</span>
        <span class="fp-name" id="fpName"></span>
        <span class="fp-size" id="fpSize"></span>
        <button class="fp-clear" onclick="clearFile()" title="Remove">✕</button>
      </div>

      <div class="upload-fields">
        <div class="field">
          <label>Account Label</label>
          <input type="text" id="acctLabel" placeholder="e.g. Chase Business Checking, Amex Platinum">
        </div>
        <div class="field">
          <label>Statement Type</label>
          <select id="scanType">
            <option value="bank">Bank Account</option>
            <option value="credit_card">Credit Card</option>
          </select>
        </div>
      </div>

      <span class="paste-toggle" onclick="togglePaste(this)">▶ Or paste raw text / CSV instead</span>
      <textarea class="paste-area" id="pasteText" placeholder="Date,Description,Amount&#10;2026-06-01,AWS,123.45&#10;2026-06-02,Adobe Creative Cloud,54.99&#10;..."></textarea>

      <button class="btn-analyze" id="analyzeBtn" onclick="analyze()"
              <?= !$hasKey ? 'disabled title="Add anthropic_api_key to config.php first"' : '' ?>>
        <div class="spin" id="analyzeSpin"></div>
        <span class="btn-label">Find Savings Opportunities</span>
      </button>
    </div>

    <!-- Results area -->
    <div id="resultsArea">
      <?php if ($viewScan && !empty($viewScan['analysis'])): ?>
      <?php renderResults($viewScan['analysis'], $viewScan['account_label'], $viewScan['scan_type']); ?>
      <?php endif; ?>
    </div>

    <!-- History -->
    <?php
    $historyScans = array_filter($scans, fn($s) => $s['status'] === 'complete');
    if (!empty($historyScans)):
    ?>
    <div class="scan-history" style="margin-top:32px">
      <h3>Past Scans</h3>
      <?php foreach ($historyScans as $s):
        $a = $s['analysis_json'] ? json_decode($s['analysis_json'], true) : null;
        $savingsTotal = 0;
        if ($a && !empty($a['savings_opportunities'])) {
            $savingsTotal = array_sum(array_column($a['savings_opportunities'], 'estimated_monthly_savings'));
        }
      ?>
      <div class="scan-row">
        <span class="scan-badge <?= htmlspecialchars($s['scan_type']) ?>">
          <?= $s['scan_type'] === 'credit_card' ? 'Credit Card' : 'Bank' ?>
        </span>
        <a class="scan-name" href="finance_statements.php?scan=<?= $s['id'] ?>">
          <?= htmlspecialchars($s['account_label'] ?: 'Unnamed Account') ?>
        </a>
        <?php if ($savingsTotal > 0): ?>
        <span class="scan-savings">💰 $<?= number_format($savingsTotal, 0) ?>/mo potential</span>
        <?php endif; ?>
        <span class="scan-date"><?= htmlspecialchars(substr($s['uploaded_at'], 0, 10)) ?></span>
        <button class="scan-del" onclick="deleteScan(<?= $s['id'] ?>, this)" title="Delete">✕</button>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>
</div>
</div>

<?php
function renderResults(array $a, string $label, string $type): void {
    $opps     = $a['savings_opportunities'] ?? [];
    $cats     = $a['categories']            ?? [];
    $txs      = $a['transactions']          ?? [];
    $total    = (float)($a['total_spending'] ?? 0);
    $savings  = (float)($a['total_potential_savings'] ?? array_sum(array_column($opps, 'estimated_monthly_savings')));
    $summary  = $a['summary'] ?? '';
    $period   = $a['statement_period'] ?? '';

    // Sort opps: high priority first, then by savings desc
    usort($opps, function($a, $b) {
        $po = ['high'=>0,'medium'=>1,'low'=>2];
        $pa = $po[$a['priority']??'low'] ?? 2;
        $pb = $po[$b['priority']??'low'] ?? 2;
        if ($pa !== $pb) return $pa - $pb;
        return ($b['estimated_monthly_savings']??0) <=> ($a['estimated_monthly_savings']??0);
    });

    echo '<div class="results-wrap">';

    // Savings hero
    echo '<div class="savings-hero">';
    echo '<div class="savings-hero-main">';
    echo '<div class="savings-hero-label">Potential Monthly Savings Found</div>';
    echo '<div class="savings-hero-amount">$' . number_format($savings, 0) . '</div>';
    echo '<div class="savings-hero-sub">across ' . count($opps) . ' opportunities' . ($period ? ' · ' . htmlspecialchars($period) : '') . '</div>';
    echo '</div>';
    echo '<div class="savings-hero-chips">';
    echo '<div class="sh-chip"><div class="sh-chip-val">$' . number_format($total, 0) . '</div><div class="sh-chip-label">Total Spend</div></div>';
    $highCount = count(array_filter($opps, fn($o)=>($o['priority']??'')=='high'));
    if ($highCount) echo '<div class="sh-chip"><div class="sh-chip-val">' . $highCount . '</div><div class="sh-chip-label">High Priority</div></div>';
    echo '<div class="sh-chip"><div class="sh-chip-val">' . count($cats) . '</div><div class="sh-chip-label">Categories</div></div>';
    echo '</div></div>';

    if ($summary) echo '<div class="scan-summary-card">' . htmlspecialchars($summary) . '</div>';

    // Savings opportunities
    if (!empty($opps)) {
        echo '<div class="savings-section-title">Where to Save Money</div>';
        echo '<div class="savings-grid">';
        foreach ($opps as $o) {
            $pri    = $o['priority'] ?? 'low';
            $mo     = (float)($o['estimated_monthly_savings'] ?? 0);
            $effort = $o['effort'] ?? 'medium';
            echo '<div class="saving-card priority-' . htmlspecialchars($pri) . '">';
            echo '<div class="saving-card-top">';
            echo '<div style="flex:1"><div class="saving-cat">' . htmlspecialchars($o['category'] ?? '') . '</div>';
            if (!empty($o['vendor'])) echo '<div class="saving-vendor">' . htmlspecialchars($o['vendor']) . '</div>';
            echo '</div>';
            if ($mo > 0) {
                echo '<div class="saving-amount-box"><div class="saving-amount">$' . number_format($mo, 0) . '</div><div class="saving-amount-label">/mo savings</div></div>';
            }
            echo '</div>';
            echo '<div class="saving-title">' . htmlspecialchars($o['title'] ?? '') . '</div>';
            echo '<div class="saving-detail">' . htmlspecialchars($o['detail'] ?? '') . '</div>';
            if (!empty($o['how_to_save'])) echo '<div class="saving-action">→ ' . htmlspecialchars($o['how_to_save']) . '</div>';
            echo '<div><span class="saving-effort effort-' . htmlspecialchars($effort) . '">' . ucfirst($effort) . ' effort</span></div>';
            echo '</div>';
        }
        echo '</div>';
    }

    // Spending breakdown
    if (!empty($cats)) {
        usort($cats, fn($a,$b)=>$b['total']<=>$a['total']);
        $maxAmt = max(array_column($cats, 'total') ?: [1]);
        echo '<div class="breakdown-card">';
        echo '<div class="breakdown-hdr"><h3>Spending Breakdown</h3><span style="font-size:13px;color:var(--muted)">$' . number_format($total,2) . ' total</span></div>';
        echo '<table class="cat-table"><thead><tr><th>Category</th><th>Amount</th><th>% of Total</th><th style="width:160px">Share</th></tr></thead><tbody>';
        foreach ($cats as $c) {
            $pct = $total > 0 ? round($c['total']/$total*100,1) : 0;
            $barW = $maxAmt > 0 ? round($c['total']/$maxAmt*140) : 3;
            echo '<tr><td><strong>' . htmlspecialchars($c['name']) . '</strong></td>';
            echo '<td>$' . number_format($c['total'],2) . '</td>';
            echo '<td>' . $pct . '%</td>';
            echo '<td><div class="cat-bar-wrap"><div class="cat-bar" style="width:' . $barW . 'px"></div></div></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    // Transaction list (collapsed)
    if (!empty($txs)) {
        echo '<span class="tx-toggle" onclick="toggleTx(this)">▶ Show all transactions (' . count($txs) . ')</span>';
        echo '<div class="tx-table-wrap">';
        echo '<table class="tx-table"><thead><tr><th>Date</th><th>Description</th><th>Category</th><th>Amount</th></tr></thead><tbody>';
        foreach ($txs as $tx) {
            echo '<tr><td>' . htmlspecialchars($tx['date']??'') . '</td>'
               . '<td>' . htmlspecialchars($tx['description']??$tx['desc']??'') . '</td>'
               . '<td>' . htmlspecialchars($tx['category']??'') . '</td>'
               . '<td>$' . number_format((float)($tx['amount']??0),2) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}
?>

<script>
let selectedFile  = null;
let selectedB64   = null;
let selectedMime  = null;

// ── Drag & drop ──────────────────────────────────────────────────────────────
const dropZone = document.getElementById('dropZone');
dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', e => { dropZone.classList.remove('drag-over'); });
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    const f = e.dataTransfer.files[0];
    if (f) handleFile(f);
});

function handleFile(f) {
    if (!f) return;
    const allowed = ['application/pdf','image/png','image/jpeg','image/jpg','text/csv','text/plain','application/vnd.ms-excel'];
    if (!allowed.includes(f.type) && !f.name.match(/\.(pdf|png|jpg|jpeg|csv|txt)$/i)) {
        alert('Please drop a PDF, image (PNG/JPG), or CSV file.');
        return;
    }
    selectedFile = f;
    selectedMime = f.type || (f.name.endsWith('.pdf') ? 'application/pdf' : 'text/plain');

    // Show preview
    const icons = {'application/pdf':'📄','image/png':'🖼️','image/jpeg':'🖼️','text/csv':'📊','text/plain':'📋'};
    document.getElementById('fpIcon').textContent = icons[selectedMime] || '📄';
    document.getElementById('fpName').textContent = f.name;
    document.getElementById('fpSize').textContent = formatBytes(f.size);
    document.getElementById('filePreview').classList.add('show');

    // Read as base64 for PDF/image, or text for CSV/txt
    const reader = new FileReader();
    if (selectedMime === 'application/pdf' || selectedMime.startsWith('image/')) {
        reader.onload = e => {
            // Strip data-URI prefix, keep only the base64 part
            selectedB64 = e.target.result.split(',')[1];
        };
        reader.readAsDataURL(f);
    } else {
        reader.onload = e => { selectedB64 = null; document.getElementById('pasteText').value = e.target.result; openPasteArea(); };
        reader.readAsText(f);
    }
}

function clearFile() {
    selectedFile = selectedB64 = selectedMime = null;
    document.getElementById('filePreview').classList.remove('show');
    document.getElementById('fileInput').value = '';
}

function togglePaste(btn) {
    const ta = document.getElementById('pasteText');
    ta.classList.toggle('open');
    btn.textContent = ta.classList.contains('open') ? '▼ Hide text paste area' : '▶ Or paste raw text / CSV instead';
}
function openPasteArea() {
    const ta = document.getElementById('pasteText');
    if (!ta.classList.contains('open')) {
        ta.classList.add('open');
        document.querySelector('.paste-toggle').textContent = '▼ Hide text paste area';
    }
}

function formatBytes(b) {
    if (b < 1024) return b + ' B';
    if (b < 1024*1024) return (b/1024).toFixed(1) + ' KB';
    return (b/1024/1024).toFixed(1) + ' MB';
}

// ── Analyze ──────────────────────────────────────────────────────────────────
function analyze() {
    const label    = document.getElementById('acctLabel').value.trim();
    const scanType = document.getElementById('scanType').value;
    const pasteVal = document.getElementById('pasteText').value.trim();

    if (!selectedB64 && !pasteVal) {
        alert('Drop a statement file or paste transaction data first.');
        return;
    }

    const btn = document.getElementById('analyzeBtn');
    btn.classList.add('loading'); btn.disabled = true;

    const payload = { action:'analyze', account_label:label, scan_type:scanType };
    if (selectedB64) {
        payload.file_data = selectedB64;
        payload.file_mime = selectedMime;
    } else {
        payload.raw_text = pasteVal;
    }

    fetch('api/finance_statements.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(d => {
        btn.classList.remove('loading'); btn.disabled = false;
        if (!d.ok) { alert(d.error || 'Analysis failed.'); return; }
        location.href = 'finance_statements.php?scan=' + d.scan_id;
    })
    .catch(() => { btn.classList.remove('loading'); btn.disabled = false; alert('Network error — please try again.'); });
}

function toggleTx(el) {
    const wrap = el.nextElementSibling;
    const open = wrap.classList.toggle('open');
    el.textContent = (open ? '▼' : '▶') + el.textContent.slice(1);
}

function deleteScan(id, btn) {
    if (!confirm('Delete this scan?')) return;
    fetch('api/finance_statements.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'delete', id})
    }).then(r=>r.json()).then(d=>{ if (d.ok) btn.closest('.scan-row').remove(); });
}
</script>
</body>
</html>
