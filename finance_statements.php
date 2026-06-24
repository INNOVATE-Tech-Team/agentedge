<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
$agent = require_login();
$perms = current_perms();
if (empty($perms['isAdmin'])) { header('Location: index.php'); exit; }

$db = local_db();

// Load past scans
$scans = $db->query("SELECT id, account_label, scan_type, uploaded_by, uploaded_at, status
                     FROM statement_scans ORDER BY uploaded_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

// Load a single scan's full analysis if requested
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
<link rel="stylesheet" href="assets/app.css">
<style>
.fin-eyebrow { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--faint); }

/* ── upload card ── */
.upload-card { background:#fff; border:1px solid var(--border); border-radius:10px; padding:24px; margin-bottom:20px; }
.upload-card h2 { font-size:16px; font-weight:700; margin-bottom:4px; }
.upload-card p  { font-size:13px; color:var(--muted); margin-bottom:18px; }

.upload-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px; }
@media(max-width:600px) { .upload-row { grid-template-columns:1fr; } }

.field label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--faint); margin-bottom:4px; }
.field input, .field select { width:100%; padding:8px 10px; border:1px solid var(--border); border-radius:8px; font-size:14px; box-sizing:border-box; }
.field input:focus, .field select:focus { outline:2px solid var(--green); }

.paste-area { width:100%; min-height:140px; padding:10px 12px; border:1px solid var(--border); border-radius:8px;
              font-size:12px; font-family:monospace; box-sizing:border-box; resize:vertical; background:#fafafa; }
.paste-area:focus { outline:2px solid var(--green); background:#fff; }

.upload-note { font-size:11px; color:var(--muted); margin-top:6px; }

.btn-analyze { padding:10px 24px; background:var(--green); color:#111; border:0; border-radius:8px;
               font-weight:700; font-size:14px; cursor:pointer; display:flex; align-items:center; gap:8px; }
.btn-analyze:hover { background:var(--green-d); color:#fff; }
.btn-analyze:disabled { opacity:.5; cursor:not-allowed; }

.spinner { width:16px; height:16px; border:2px solid rgba(0,0,0,.2); border-top-color:#111; border-radius:50%; animation:spin .6s linear infinite; display:none; }
.btn-analyze.loading .spinner { display:block; }
.btn-analyze.loading .btn-label { opacity:.6; }
@keyframes spin { to { transform:rotate(360deg); } }

.no-key-warn { background:#fff8e1; border:1px solid #f5c842; border-radius:8px; padding:12px 16px; font-size:13px; color:#7a5700; margin-bottom:16px; }

/* ── results ── */
.results-section { margin-top:28px; }
.results-section h2 { font-size:16px; font-weight:700; margin-bottom:16px; }

.summary-chips { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px; }
.chip { background:#fff; border:1px solid var(--border); border-radius:8px; padding:10px 16px; min-width:120px; }
.chip-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--faint); }
.chip-val { font-size:20px; font-weight:800; color:var(--ink); }

.cat-table { width:100%; border-collapse:collapse; font-size:13px; margin-bottom:20px; }
.cat-table th { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--faint);
                padding:8px 12px; border-bottom:1px solid var(--border); text-align:left; }
.cat-table td { padding:9px 12px; border-bottom:1px solid var(--border); vertical-align:middle; }
.cat-table tr:last-child td { border-bottom:none; }
.cat-bar { height:8px; background:var(--green); border-radius:4px; min-width:4px; display:inline-block; vertical-align:middle; }

.recs-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:14px; margin-bottom:20px; }
.rec-card { background:#fff; border:1px solid var(--border); border-radius:10px; padding:16px; }
.rec-card-cat { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--green-d); margin-bottom:6px; }
.rec-card-title { font-size:14px; font-weight:700; margin-bottom:6px; }
.rec-card-detail { font-size:12px; color:var(--muted); margin-bottom:10px; line-height:1.5; }
.rec-card-savings { font-size:13px; font-weight:700; color:#2e7d32; }

.tx-toggle { font-size:13px; color:var(--green-d); cursor:pointer; font-weight:600; margin-bottom:12px; display:inline-block; }
.tx-toggle:hover { text-decoration:underline; }
.tx-list { display:none; max-height:300px; overflow-y:auto; }
.tx-list.open { display:block; }
.tx-list table { width:100%; border-collapse:collapse; font-size:12px; }
.tx-list th { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--faint); padding:6px 10px; border-bottom:1px solid var(--border); }
.tx-list td { padding:7px 10px; border-bottom:1px solid var(--border); }

/* ── history list ── */
.scan-history { margin-top:32px; }
.scan-history h3 { font-size:14px; font-weight:700; margin-bottom:12px; color:var(--muted); }
.scan-row { display:flex; align-items:center; gap:10px; padding:9px 12px; border:1px solid var(--border); border-radius:8px; margin-bottom:8px; background:#fff; font-size:13px; }
.scan-row-type { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; padding:2px 7px; border-radius:4px; background:#e8f5d0; color:#3a6b00; }
.scan-row-type.cc { background:#e8f0ff; color:#2c3e8a; }
.scan-row a { color:var(--green-d); text-decoration:none; font-weight:600; }
.scan-row a:hover { text-decoration:underline; }
.scan-status { font-size:11px; padding:2px 7px; border-radius:4px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
.scan-status.complete { background:#e8f5d0; color:#3a6b00; }
.scan-status.error    { background:#fdecea; color:#c0392b; }
.scan-status.pending  { background:#f5f5f5; color:#888; }
.scan-row-del { margin-left:auto; background:none; border:none; cursor:pointer; color:var(--faint); font-size:14px; padding:2px 6px; border-radius:4px; }
.scan-row-del:hover { background:#fdecea; color:#d73a49; }
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
    <div class="content-hello">Upload bank or credit card statements — AI finds savings opportunities</div>
  </div>
  <div class="wrap">

    <?php if (!$hasKey): ?>
    <div class="no-key-warn">
      <strong>Anthropic API key not configured.</strong>
      Add <code>anthropic_api_key</code> to your <code>config.php</code> to enable AI analysis.
      Get a key at <strong>console.anthropic.com</strong>.
    </div>
    <?php endif; ?>

    <div class="upload-card">
      <h2>Analyze a Statement</h2>
      <p>Paste CSV data or raw transaction text from your bank or credit card. The AI will categorize spending and suggest where to save.</p>

      <div class="upload-row">
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

      <div class="field" style="margin-bottom:10px">
        <label>Paste Statement Data</label>
        <textarea class="paste-area" id="stmtText"
          placeholder="Paste CSV rows or copied transaction data here, e.g.&#10;Date,Description,Amount&#10;2026-06-01,AWS,123.45&#10;2026-06-02,Adobe Creative Cloud,54.99&#10;..."></textarea>
        <div class="upload-note">Tip: From your bank's website, export as CSV or copy the transactions table directly.</div>
      </div>

      <button class="btn-analyze" id="analyzeBtn" onclick="analyzeStatement()" <?= !$hasKey ? 'disabled title="Add anthropic_api_key to config.php first"' : '' ?>>
        <div class="spinner" id="analyzeSpinner"></div>
        <span class="btn-label">Analyze Statement</span>
      </button>
    </div>

    <!-- Results appear here -->
    <div id="resultsArea"></div>

    <?php if ($viewScan && !empty($viewScan['analysis'])): ?>
    <?php renderAnalysis($viewScan['analysis'], $viewScan['account_label'], $viewScan['scan_type'], $viewScan['uploaded_at']); ?>
    <?php endif; ?>

    <!-- Scan history -->
    <?php if (!empty($scans)): ?>
    <div class="scan-history">
      <h3>Recent Scans</h3>
      <?php foreach ($scans as $s): ?>
      <div class="scan-row">
        <span class="scan-row-type <?= $s['scan_type']==='credit_card'?'cc':'' ?>">
          <?= $s['scan_type']==='credit_card'?'Credit Card':'Bank' ?>
        </span>
        <a href="finance_statements.php?scan=<?= $s['id'] ?>">
          <?= htmlspecialchars($s['account_label'] ?: 'Unnamed Account') ?>
        </a>
        <span style="color:var(--faint);font-size:12px"><?= htmlspecialchars($s['uploaded_at']) ?></span>
        <span class="scan-status <?= htmlspecialchars($s['status']) ?>"><?= htmlspecialchars($s['status']) ?></span>
        <button class="scan-row-del" onclick="deleteScan(<?= $s['id'] ?>, this)" title="Delete">✕</button>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div><!-- /wrap -->
</div><!-- /content -->
</div><!-- /layout -->

<?php
function renderAnalysis(array $a, string $label, string $type, string $date): void {
    $cats     = $a['categories']     ?? [];
    $recs     = $a['recommendations'] ?? [];
    $total    = $a['total_spending']  ?? 0;
    $summary  = $a['summary']         ?? '';
    $maxAmt   = max(array_column($cats, 'total') ?: [1]);
    $txCount  = array_sum(array_map(fn($c)=>count($c['transactions']??[]), $cats));

    echo '<div class="results-section">';
    echo '<h2>' . htmlspecialchars($label ?: 'Statement') . ' — Analysis</h2>';

    if ($summary) echo '<div class="card" style="margin-bottom:16px;font-size:13px;line-height:1.6;color:var(--muted)">' . htmlspecialchars($summary) . '</div>';

    echo '<div class="summary-chips">';
    echo '<div class="chip"><div class="chip-label">Total Spending</div><div class="chip-val">$' . number_format($total,2) . '</div></div>';
    echo '<div class="chip"><div class="chip-label">Categories</div><div class="chip-val">' . count($cats) . '</div></div>';
    echo '<div class="chip"><div class="chip-label">Transactions</div><div class="chip-val">' . $txCount . '</div></div>';
    echo '<div class="chip"><div class="chip-label">Savings Opps</div><div class="chip-val">' . count($recs) . '</div></div>';
    echo '</div>';

    if (!empty($cats)) {
        echo '<div class="card" style="padding:0;overflow:hidden;margin-bottom:20px">';
        echo '<div style="padding:12px 16px;border-bottom:1px solid var(--border)"><strong>Spending by Category</strong></div>';
        echo '<table class="cat-table"><thead><tr><th>Category</th><th>Total</th><th>% of Spend</th><th>Breakdown</th></tr></thead><tbody>';
        usort($cats, fn($a,$b)=>$b['total']<=>$a['total']);
        foreach ($cats as $c) {
            $pct  = $total > 0 ? round($c['total'] / $total * 100) : 0;
            $barW = $maxAmt > 0 ? round($c['total'] / $maxAmt * 180) : 4;
            echo '<tr>';
            echo '<td><strong>' . htmlspecialchars($c['name']) . '</strong></td>';
            echo '<td>$' . number_format($c['total'],2) . '</td>';
            echo '<td>' . $pct . '%</td>';
            echo '<td><div class="cat-bar" style="width:' . $barW . 'px"></div></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    if (!empty($recs)) {
        echo '<h3 style="font-size:14px;font-weight:700;margin-bottom:12px">Savings Recommendations</h3>';
        echo '<div class="recs-grid">';
        foreach ($recs as $r) {
            $savings = isset($r['estimated_savings']) ? '$' . number_format($r['estimated_savings'],0) . '/mo est. savings' : '';
            echo '<div class="rec-card">';
            echo '<div class="rec-card-cat">' . htmlspecialchars($r['category'] ?? '') . '</div>';
            echo '<div class="rec-card-title">' . htmlspecialchars($r['title'] ?? '') . '</div>';
            echo '<div class="rec-card-detail">' . htmlspecialchars($r['detail'] ?? '') . '</div>';
            if ($savings) echo '<div class="rec-card-savings">' . $savings . '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    // Full transaction list (collapsed by default)
    $allTx = [];
    foreach ($cats as $c) {
        foreach ($c['transactions'] ?? [] as $tx) {
            $allTx[] = array_merge($tx, ['_cat' => $c['name']]);
        }
    }
    if (!empty($allTx)) {
        echo '<span class="tx-toggle" onclick="toggleTx(this)">▶ Show all transactions (' . count($allTx) . ')</span>';
        echo '<div class="tx-list">';
        echo '<table><thead><tr><th>Date</th><th>Description</th><th>Category</th><th>Amount</th></tr></thead><tbody>';
        foreach ($allTx as $tx) {
            echo '<tr><td>' . htmlspecialchars($tx['date']??'') . '</td>'
               . '<td>' . htmlspecialchars($tx['desc']??'') . '</td>'
               . '<td>' . htmlspecialchars($tx['_cat']??'') . '</td>'
               . '<td>$' . number_format((float)($tx['amount']??0),2) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    echo '</div>';
}
?>

<script>
function analyzeStatement() {
    const text  = document.getElementById('stmtText').value.trim();
    const label = document.getElementById('acctLabel').value.trim();
    const type  = document.getElementById('scanType').value;
    if (!text) { alert('Please paste some statement data first.'); return; }

    const btn = document.getElementById('analyzeBtn');
    btn.classList.add('loading');
    btn.disabled = true;

    fetch('api/finance_statements.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'analyze', raw_text:text, account_label:label, scan_type:type})
    })
    .then(r => r.json())
    .then(d => {
        btn.classList.remove('loading');
        btn.disabled = false;
        if (!d.ok) { alert(d.error || 'Analysis failed.'); return; }
        // Redirect to view this scan's results
        location.href = 'finance_statements.php?scan=' + d.scan_id;
    })
    .catch(() => {
        btn.classList.remove('loading');
        btn.disabled = false;
        alert('Network error — please try again.');
    });
}

function toggleTx(el) {
    const list = el.nextElementSibling;
    const open = list.classList.toggle('open');
    el.textContent = (open ? '▼' : '▶') + el.textContent.slice(1);
}

function deleteScan(id, btn) {
    if (!confirm('Delete this scan?')) return;
    fetch('api/finance_statements.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'delete', id})
    }).then(r=>r.json()).then(d=>{
        if (d.ok) btn.closest('.scan-row').remove();
    });
}
</script>
</body>
</html>
