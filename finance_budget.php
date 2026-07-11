<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
$agent = require_login();
$perms = current_perms();
if (empty($perms['isAdmin'])) { header('Location: index.php'); exit; }

$db = local_db();
$periods = $db->query("SELECT * FROM budget_periods ORDER BY start_date DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

$selectedId = (int)($_GET['period'] ?? ($periods[0]['id'] ?? 0));
$selectedPeriod = null;
foreach ($periods as $p) { if ($p['id'] == $selectedId) { $selectedPeriod = $p; break; } }

$lines = [];
if ($selectedId) {
    $st = $db->prepare("SELECT * FROM budget_lines WHERE period_id=? ORDER BY department, category, id");
    $st->execute([$selectedId]);
    $lines = $st->fetchAll(PDO::FETCH_ASSOC);
}

$byDept = [];
foreach ($lines as $l) { $byDept[$l['department']][] = $l; }

$depts = ['Operations','Finance','Broker Files','Events','Agent Development','Technology','Human Resources'];
$budgetCategories = [
    'Payroll','Office Rent','Software / Technology','Marketing / Advertising',
    'Professional Development','Insurance','Utilities','Professional Fees',
    'Recruiting','Events / Activities','Office Supplies','Travel','Other',
];

$hasKey = !empty(cfg()['anthropic_api_key']);

// Status badge helper — based on % of budget used
function line_status(float $budgeted, float $actual): array {
    if ($budgeted <= 0) return ['label'=>'—',        'cls'=>'st-none'];
    $pct = $actual / $budgeted * 100;
    if ($pct > 100) return ['label'=>'Over Budget',  'cls'=>'st-over',    'pct'=>round($pct)];
    if ($pct >= 85)  return ['label'=>'Near Limit',   'cls'=>'st-warn',    'pct'=>round($pct)];
    if ($pct >= 60)  return ['label'=>'On Track',     'cls'=>'st-ok',      'pct'=>round($pct)];
    return              ['label'=>'Under Budget', 'cls'=>'st-under',   'pct'=>round($pct)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Department Budget — AgentEdge</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
<style>
.fin-eyebrow { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--faint); }

/* ── period bar ── */
.period-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:20px; }
.period-select { padding:7px 12px; border:1px solid var(--border); border-radius:8px; font-size:14px; background:#fff; cursor:pointer; }
.period-select:focus { outline:2px solid var(--green); }
.btn-new-period { padding:7px 16px; background:var(--green); color:#111; border:0; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer; }
.btn-new-period:hover { background:var(--green-d); color:#fff; }
.btn-ai-insights { padding:7px 16px; background:#111; color:#fff; border:0; border-radius:8px; font-weight:700;
                   font-size:13px; cursor:pointer; display:flex; align-items:center; gap:6px; }
.btn-ai-insights:hover { background:#333; }
.btn-ai-insights:disabled { opacity:.45; cursor:not-allowed; }
.ai-spinner { width:14px; height:14px; border:2px solid rgba(255,255,255,.25); border-top-color:#fff;
              border-radius:50%; animation:spin .6s linear infinite; display:none; }
.btn-ai-insights.loading .ai-spinner { display:block; }
@keyframes spin { to { transform:rotate(360deg); } }

/* ── summary cards ── */
.summary-row { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; margin-bottom:20px; }
.sum-card { background:#fff; border:1px solid var(--border); border-radius:10px; padding:14px 16px; }
.sum-card-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--faint); margin-bottom:4px; }
.sum-card-val { font-size:22px; font-weight:800; color:var(--ink); }
.sum-card-val.over { color:#d73a49; }
.sum-card-val.under { color:#2e7d32; }

/* ── AI insights panel ── */
.insights-panel { background:#fff; border:1px solid var(--border); border-radius:10px; margin-bottom:20px; overflow:hidden; }
.insights-panel-hdr { display:flex; align-items:center; gap:10px; padding:14px 16px; border-bottom:1px solid var(--border); background:#f8fef2; }
.insights-panel-hdr h3 { margin:0; font-size:15px; font-weight:700; flex:1; }
.health-badge { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.06em;
                padding:3px 10px; border-radius:20px; }
.health-badge.good     { background:#e8f5d0; color:#3a6b00; }
.health-badge.warning  { background:#fff3cd; color:#856404; }
.health-badge.critical { background:#fdecea; color:#c0392b; }
.health-score { font-size:20px; font-weight:900; }
.health-score.good     { color:#3a6b00; }
.health-score.warning  { color:#856404; }
.health-score.critical { color:#c0392b; }
.insights-summary { padding:14px 16px; font-size:13px; color:var(--muted); line-height:1.6; border-bottom:1px solid var(--border); }
.insights-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:0; }
.insight-card { padding:14px 16px; border-right:1px solid var(--border); border-bottom:1px solid var(--border); }
.insight-card:nth-child(3n) { border-right:none; }
.insight-type-icon { font-size:16px; margin-right:5px; }
.insight-card-dept { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--faint); margin-bottom:5px; }
.insight-card-title { font-size:13px; font-weight:700; margin-bottom:5px; }
.insight-card-detail { font-size:12px; color:var(--muted); margin-bottom:8px; line-height:1.5; }
.insight-card-action { font-size:12px; font-weight:600; color:var(--green-d); padding:5px 10px; background:#f4f9ec; border-radius:5px; display:inline-block; }
.insight-type-success  .insight-card-title { color:#2e7d32; }
.insight-type-warning  .insight-card-title { color:#856404; }
.insight-type-critical .insight-card-title { color:#c0392b; }
.quick-wins { padding:14px 16px; background:#f8fef2; border-top:1px solid var(--border); }
.quick-wins h4 { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--faint); margin-bottom:10px; }
.qw-list { display:flex; gap:8px; flex-wrap:wrap; }
.qw-chip { font-size:12px; background:#fff; border:1px solid var(--border); border-radius:6px; padding:5px 10px; color:var(--ink); line-height:1.4; }

/* ── status badges ── */
.st-badge { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.04em;
            padding:2px 7px; border-radius:4px; white-space:nowrap; }
.st-over  { background:#fdecea; color:#c0392b; }
.st-warn  { background:#fff3cd; color:#856404; }
.st-ok    { background:#e8f5d0; color:#2e7d32; }
.st-under { background:#e8f0ff; color:#3a4a9a; }
.st-none  { background:#f5f5f5; color:#aaa; }

/* ── dept cards ── */
.dept-card { background:#fff; border:1px solid var(--border); border-radius:10px; margin-bottom:16px; overflow:hidden; }
.dept-hdr { display:flex; align-items:center; gap:10px; padding:14px 16px; cursor:pointer; user-select:none; background:#fafbfa; border-bottom:1px solid var(--border); }
.dept-hdr:hover { background:#f4f9ec; }
.dept-hdr-name { font-size:15px; font-weight:700; flex:1; }
.dept-hdr-nums { display:flex; gap:18px; font-size:13px; }
.dept-hdr-nums span { color:var(--muted); }
.dept-hdr-nums strong { color:var(--ink); }
.dept-progress { width:100px; height:8px; background:#e5e7eb; border-radius:4px; overflow:hidden; display:inline-block; vertical-align:middle; margin-left:8px; }
.dept-progress-fill { height:100%; background:var(--green); border-radius:4px; transition:width .3s; }
.dept-progress-fill.over { background:#d73a49; }
.dept-arrow { font-size:11px; color:var(--faint); transition:transform .2s; }
.dept-arrow.open { transform:rotate(90deg); }
.dept-body.hidden { display:none; }

/* ── line items table ── */
.bl-table { width:100%; border-collapse:collapse; font-size:13px; }
.bl-table th { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--faint);
               padding:8px 12px; border-bottom:1px solid var(--border); text-align:left; white-space:nowrap; }
.bl-table td { padding:9px 12px; border-bottom:1px solid var(--border); vertical-align:middle; }
.bl-table tr:last-child td { border-bottom:none; }
.bl-table tr:hover td { background:#fafbfa; }
.bl-var-pos { color:#2e7d32; font-weight:600; }
.bl-var-neg { color:#d73a49; font-weight:600; }
.bl-progress { width:70px; height:6px; background:#e5e7eb; border-radius:3px; overflow:hidden; display:inline-block; vertical-align:middle; margin-right:4px; }
.bl-progress-fill { height:100%; background:var(--green); border-radius:3px; }
.bl-progress-fill.over { background:#d73a49; }
.btn-icon { background:none; border:none; cursor:pointer; padding:3px 6px; border-radius:4px; color:var(--faint); font-size:14px; line-height:1; }
.btn-icon:hover { background:#f0f0f0; color:var(--ink); }
.btn-icon.del:hover { background:#fdecea; color:#d73a49; }
.add-line-btn { display:flex; align-items:center; gap:6px; padding:8px 12px; font-size:13px; color:var(--green-d); font-weight:600; cursor:pointer; border:none; background:none; }
.add-line-btn:hover { color:var(--green); }

/* ── modals ── */
.modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:200; display:none; align-items:center; justify-content:center; }
.modal-backdrop.open { display:flex; }
.modal { background:#fff; border-radius:12px; padding:24px; width:460px; max-width:calc(100vw - 32px); box-shadow:0 8px 32px rgba(0,0,0,.18); }
.modal h3 { font-size:17px; font-weight:700; margin-bottom:16px; }
.modal .field { margin-bottom:14px; }
.modal .field label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--faint); margin-bottom:4px; }
.modal input, .modal select, .modal textarea { width:100%; padding:8px 10px; border:1px solid var(--border); border-radius:8px; font-size:14px; box-sizing:border-box; }
.modal input:focus, .modal select:focus, .modal textarea:focus { outline:2px solid var(--green); }
.modal textarea { resize:vertical; min-height:60px; }
.modal-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.modal-actions { display:flex; gap:8px; justify-content:flex-end; margin-top:20px; }
.btn-cancel { padding:8px 18px; border:1px solid var(--border); border-radius:8px; background:#fff; font-size:14px; cursor:pointer; }
.btn-cancel:hover { background:#f5f5f5; }
.btn-save { padding:8px 18px; background:var(--green); color:#111; border:0; border-radius:8px; font-weight:700; font-size:14px; cursor:pointer; }
.btn-save:hover { background:var(--green-d); color:#fff; }

/* ── category tip box (inside modal) ── */
.cat-tip-box { background:#f4f9ec; border:1px solid #c8e6a0; border-radius:8px; padding:10px 12px; margin-top:-6px; margin-bottom:14px; font-size:12px; color:#3a6b00; line-height:1.5; display:none; }
.cat-tip-box .ct-icon { font-size:15px; margin-right:4px; vertical-align:middle; }
.cat-tip-box strong { font-weight:700; display:block; margin-bottom:2px; }

/* ── general tips card ── */
.tips-card { background:#fff; border:1px solid var(--border); border-radius:10px; margin-top:24px; overflow:hidden; }
.tips-card-hdr { display:flex; align-items:center; gap:8px; padding:14px 16px; cursor:pointer; user-select:none; border-bottom:1px solid var(--border); }
.tips-card-hdr h3 { margin:0; font-size:15px; font-weight:700; flex:1; }
.tips-card-hdr .tips-arrow { font-size:11px; color:var(--faint); transition:transform .2s; }
.tips-card-hdr.open .tips-arrow { transform:rotate(180deg); }
.tips-body { display:none; padding:16px; }
.tips-body.open { display:block; }
.tips-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:12px; }
.tip-item { background:#f8fef2; border:1px solid #c8e6a0; border-radius:8px; padding:12px 14px; }
.tip-item-icon { font-size:18px; margin-bottom:6px; }
.tip-item-title { font-size:13px; font-weight:700; margin-bottom:4px; color:var(--ink); }
.tip-item-body  { font-size:12px; color:var(--muted); line-height:1.5; }

/* ── add dept buttons ── */
.add-dept-grid { display:flex; flex-wrap:wrap; gap:8px; }
.add-dept-btn { border:1px solid var(--border); padding:6px 12px; font-size:12px; border-radius:6px; color:var(--ink); background:#fff; cursor:pointer; }
.add-dept-btn:hover { background:var(--green); color:#111; border-color:var(--green); }

/* ── empty state ── */
.budget-empty { text-align:center; padding:60px 24px; color:var(--faint); }
.budget-empty h3 { font-size:17px; font-weight:700; margin-bottom:6px; color:var(--ink); }

.no-key-warn { background:#fff8e1; border:1px solid #f5c842; border-radius:8px; padding:10px 14px; font-size:12px; color:#7a5700; margin-bottom:12px; }
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('finance_budget', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="fin-eyebrow">Back Office / Finance</div>
      <div class="content-title">Department Budget</div>
    </div>
    <div class="content-hello">Track budgeted vs. actual spending by department</div>
  </div>
  <div class="wrap">

    <!-- Period selector -->
    <div class="period-bar">
      <?php if (!empty($periods)): ?>
      <form method="get" style="display:flex;align-items:center;gap:8px">
        <label style="font-size:13px;font-weight:600;color:var(--muted)">Period:</label>
        <select class="period-select" name="period" onchange="this.form.submit()">
          <?php foreach ($periods as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $p['id']==$selectedId?'selected':'' ?>>
            <?= htmlspecialchars($p['name']) ?>
            <?php if ($p['start_date']): ?>(<?= $p['start_date'] ?> – <?= $p['end_date'] ?: '…' ?>)<?php endif; ?>
          </option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php else: ?>
      <span style="font-size:13px;color:var(--muted)">No periods yet — create one to get started.</span>
      <?php endif; ?>
      <button class="btn-new-period" onclick="openPeriodModal()">+ New Period</button>
      <?php if ($selectedPeriod && !empty($lines)): ?>
      <button class="btn-ai-insights" id="aiBtn"
              onclick="getInsights(<?= $selectedId ?>)"
              <?= !$hasKey ? 'disabled title="Add anthropic_api_key to config.php first"' : '' ?>>
        <div class="ai-spinner" id="aiSpinner"></div>
        <span>✦ AI Insights</span>
      </button>
      <?php endif; ?>
      <?php if ($selectedPeriod): ?>
      <button class="btn-icon del" style="margin-left:auto;font-size:12px;padding:5px 10px;border:1px solid #ffd7d7;color:#d73a49;border-radius:6px"
              onclick="deletePeriod(<?= $selectedId ?>, <?= htmlspecialchars(json_encode($selectedPeriod['name'])) ?>)">
        Delete Period
      </button>
      <?php endif; ?>
    </div>

    <?php if (!$hasKey && $selectedPeriod && !empty($lines)): ?>
    <div class="no-key-warn">
      <strong>AI Insights disabled.</strong> Add <code>anthropic_api_key</code> to <code>config.php</code> to enable Claude-powered budget analysis.
    </div>
    <?php endif; ?>

    <!-- AI Insights panel (hidden until fetched) -->
    <div id="insightsPanel" class="insights-panel" style="display:none"></div>

    <?php if ($selectedPeriod): ?>

    <!-- Summary cards -->
    <?php
    $totalBudget = array_sum(array_column($lines, 'budgeted_amt'));
    $totalActual = array_sum(array_column($lines, 'actual_amt'));
    $totalVar    = $totalBudget - $totalActual;
    $pctUsed     = $totalBudget > 0 ? round($totalActual / $totalBudget * 100) : 0;
    $overCount   = 0;
    $warnCount   = 0;
    foreach ($lines as $l) {
        $s = line_status($l['budgeted_amt'], $l['actual_amt']);
        if ($s['cls'] === 'st-over') $overCount++;
        elseif ($s['cls'] === 'st-warn') $warnCount++;
    }
    ?>
    <div class="summary-row">
      <div class="sum-card">
        <div class="sum-card-label">Total Budget</div>
        <div class="sum-card-val">$<?= number_format($totalBudget, 0) ?></div>
      </div>
      <div class="sum-card">
        <div class="sum-card-label">Total Actual</div>
        <div class="sum-card-val <?= $totalActual > $totalBudget ? 'over' : '' ?>">$<?= number_format($totalActual, 0) ?></div>
      </div>
      <div class="sum-card">
        <div class="sum-card-label">Remaining</div>
        <div class="sum-card-val <?= $totalVar < 0 ? 'over' : 'under' ?>">
          <?= $totalVar < 0 ? '-' : '' ?>$<?= number_format(abs($totalVar), 0) ?>
        </div>
      </div>
      <div class="sum-card">
        <div class="sum-card-label">% Used</div>
        <div class="sum-card-val <?= $pctUsed > 100 ? 'over' : '' ?>"><?= $pctUsed ?>%</div>
      </div>
      <?php if ($overCount > 0): ?>
      <div class="sum-card" style="border-color:#fdd;background:#fef8f8">
        <div class="sum-card-label" style="color:#c0392b">Over Budget</div>
        <div class="sum-card-val over"><?= $overCount ?> line<?= $overCount !== 1 ? 's' : '' ?></div>
      </div>
      <?php endif; ?>
      <?php if ($warnCount > 0): ?>
      <div class="sum-card" style="border-color:#fde68a;background:#fffdf0">
        <div class="sum-card-label" style="color:#856404">Near Limit</div>
        <div class="sum-card-val" style="color:#856404"><?= $warnCount ?> line<?= $warnCount !== 1 ? 's' : '' ?></div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Dept cards -->
    <?php foreach ($depts as $deptName):
      $deptLines = $byDept[$deptName] ?? [];
      if (empty($deptLines)) continue;
      $dBudget = array_sum(array_column($deptLines, 'budgeted_amt'));
      $dActual = array_sum(array_column($deptLines, 'actual_amt'));
      $dVar    = $dBudget - $dActual;
      $dPct    = $dBudget > 0 ? min(round($dActual / $dBudget * 100), 200) : 0;
      $dStatus = line_status($dBudget, $dActual);
    ?>
    <div class="dept-card" id="dc-<?= htmlspecialchars($deptName) ?>">
      <div class="dept-hdr" onclick="toggleDept(this)">
        <span class="dept-arrow open">▶</span>
        <span class="dept-hdr-name"><?= htmlspecialchars($deptName) ?></span>
        <div class="dept-hdr-nums">
          <span>Budget: <strong>$<?= number_format($dBudget,0) ?></strong></span>
          <span>Actual: <strong>$<?= number_format($dActual,0) ?></strong></span>
          <span>Remaining: <strong class="<?= $dVar<0?'bl-var-neg':'bl-var-pos' ?>"><?= $dVar<0?'-':'' ?>$<?= number_format(abs($dVar),0) ?></strong></span>
        </div>
        <span class="st-badge <?= $dStatus['cls'] ?>"><?= $dStatus['label'] ?></span>
        <div class="dept-progress">
          <div class="dept-progress-fill <?= $dPct>100?'over':'' ?>" style="width:<?= min($dPct,100) ?>%"></div>
        </div>
        <span style="font-size:11px;color:var(--faint);margin-left:2px"><?= $dPct ?>%</span>
      </div>
      <div class="dept-body">
        <table class="bl-table">
          <thead>
            <tr>
              <th>Category</th>
              <th>Description</th>
              <th>Budgeted</th>
              <th>Actual</th>
              <th>Variance</th>
              <th style="width:100px">Progress</th>
              <th style="width:90px">Status</th>
              <th>Notes</th>
              <th style="width:70px"></th>
            </tr>
          </thead>
          <tbody id="tbl-<?= htmlspecialchars($deptName) ?>">
          <?php foreach ($deptLines as $l):
            $var  = $l['budgeted_amt'] - $l['actual_amt'];
            $pct  = $l['budgeted_amt'] > 0 ? min(round($l['actual_amt']/$l['budgeted_amt']*100),200) : 0;
            $st   = line_status($l['budgeted_amt'], $l['actual_amt']);
          ?>
            <tr id="row-<?= $l['id'] ?>">
              <td><strong><?= htmlspecialchars($l['category']) ?></strong></td>
              <td style="color:var(--muted);font-size:12px"><?= htmlspecialchars($l['description']) ?></td>
              <td>$<?= number_format($l['budgeted_amt'],2) ?></td>
              <td>$<?= number_format($l['actual_amt'],2) ?></td>
              <td class="<?= $var<0?'bl-var-neg':'bl-var-pos' ?>"><?= $var<0?'-':'' ?>$<?= number_format(abs($var),2) ?></td>
              <td>
                <div class="bl-progress">
                  <div class="bl-progress-fill <?= $pct>100?'over':'' ?>" style="width:<?= min($pct,100) ?>%"></div>
                </div>
                <span style="font-size:11px;color:var(--muted)"><?= $pct ?>%</span>
              </td>
              <td><span class="st-badge <?= $st['cls'] ?>"><?= $st['label'] ?></span></td>
              <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($l['notes']) ?></td>
              <td>
                <button class="btn-icon" onclick="editLine(<?= htmlspecialchars(json_encode($l)) ?>)" title="Edit">✏️</button>
                <button class="btn-icon del" onclick="deleteLine(<?= $l['id'] ?>, this)" title="Delete">✕</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="background:#fafbfa">
              <td colspan="9">
                <button class="add-line-btn" onclick="openAddLine(<?= htmlspecialchars(json_encode($deptName)) ?>)">
                  + Add line item
                </button>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Add items to depts with no lines yet -->
    <div class="card" style="padding:16px">
      <div style="font-size:13px;font-weight:600;margin-bottom:10px;color:var(--muted)">Add a line item to a department:</div>
      <div class="add-dept-grid">
        <?php foreach ($depts as $deptName): ?>
        <button class="add-dept-btn" onclick="openAddLine(<?= htmlspecialchars(json_encode($deptName)) ?>)">
          <?= htmlspecialchars($deptName) ?>
        </button>
        <?php endforeach; ?>
      </div>
    </div>

    <?php else: ?>
    <div class="card budget-empty">
      <h3>No period selected</h3>
      <p>Create a budget period above to start tracking departmental spending.</p>
    </div>
    <?php endif; ?>

    <!-- General Budgeting Tips (always visible) -->
    <div class="tips-card">
      <div class="tips-card-hdr" onclick="toggleTips(this)">
        <span style="font-size:16px">💡</span>
        <h3>Budgeting Tips for Real Estate Brokerages</h3>
        <span class="tips-arrow">▾</span>
      </div>
      <div class="tips-body" id="tipsBody">
        <div class="tips-grid">
          <div class="tip-item">
            <div class="tip-item-icon">🎯</div>
            <div class="tip-item-title">Set budgets at 90% of expected spend</div>
            <div class="tip-item-body">Keep a built-in 10% buffer. It absorbs surprises without forcing overages and trains teams to spend thoughtfully.</div>
          </div>
          <div class="tip-item">
            <div class="tip-item-icon">💻</div>
            <div class="tip-item-title">Audit Software / SaaS quarterly</div>
            <div class="tip-item-body">Unused seats and overlapping tools are the #1 source of silent waste. Annual billing saves 15–25% vs. monthly. Cancel before renewal cycles.</div>
          </div>
          <div class="tip-item">
            <div class="tip-item-icon">📣</div>
            <div class="tip-item-title">Marketing: benchmark against GCI</div>
            <div class="tip-item-body">Industry norm is 5–8% of GCI. Tracking cost-per-lead by channel lets you cut spend on what isn't converting and double down on what is.</div>
          </div>
          <div class="tip-item">
            <div class="tip-item-icon">🛡️</div>
            <div class="tip-item-title">Lock in insurance before everything else</div>
            <div class="tip-item-body">E&O and liability premiums are non-negotiable and rise with agent count. Review annually — volume discounts kick in at key headcount thresholds.</div>
          </div>
          <div class="tip-item">
            <div class="tip-item-icon">🎓</div>
            <div class="tip-item-title">Under-investing in training increases attrition</div>
            <div class="tip-item-body">Budget $500–$1,500 per agent/year for development. The cost of losing a producing agent (recruiting + ramp time) far exceeds the training investment.</div>
          </div>
          <div class="tip-item">
            <div class="tip-item-icon">🤝</div>
            <div class="tip-item-title">Know your cost-per-hire</div>
            <div class="tip-item-body">Top-producing agents typically cost $2,000–$5,000 to recruit when you factor in events, materials, and staff time. Track it to justify the Recruiting line.</div>
          </div>
          <div class="tip-item">
            <div class="tip-item-icon">📈</div>
            <div class="tip-item-title">Compare to prior period, not just to budget</div>
            <div class="tip-item-body">Year-over-year comparisons catch slow spending creep. Expenses that look fine vs. budget can still mask a 15% cost increase from last year.</div>
          </div>
          <div class="tip-item">
            <div class="tip-item-icon">🏢</div>
            <div class="tip-item-title">Review leases when headcount changes ±20%</div>
            <div class="tip-item-body">Multi-year leases save 10–15% but lock you in. Negotiate early-termination clauses and build headcount projections into renewal decisions.</div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /wrap -->
</div><!-- /content -->
</div><!-- /layout -->

<!-- New Period Modal -->
<div class="modal-backdrop" id="periodModal">
  <div class="modal">
    <h3>New Budget Period</h3>
    <div class="field">
      <label>Period Name</label>
      <input type="text" id="pName" placeholder="e.g. FY 2026, Q3 2026, July 2026">
    </div>
    <div class="field">
      <label>Type</label>
      <select id="pType">
        <option value="annual">Annual</option>
        <option value="quarterly">Quarterly</option>
        <option value="monthly">Monthly</option>
        <option value="custom">Custom</option>
      </select>
    </div>
    <div class="modal-row">
      <div class="field">
        <label>Start Date</label>
        <input type="date" id="pStart">
      </div>
      <div class="field">
        <label>End Date</label>
        <input type="date" id="pEnd">
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closePeriodModal()">Cancel</button>
      <button class="btn-save" onclick="savePeriod()">Create Period</button>
    </div>
  </div>
</div>

<!-- Add / Edit Line Modal -->
<div class="modal-backdrop" id="lineModal">
  <div class="modal">
    <h3 id="lineModalTitle">Add Line Item</h3>
    <input type="hidden" id="lineId" value="">
    <input type="hidden" id="linePeriodId" value="<?= $selectedId ?>">
    <div class="field">
      <label>Department</label>
      <select id="lineDept">
        <?php foreach ($depts as $d): ?>
        <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="modal-row">
      <div class="field">
        <label>Category</label>
        <input type="text" id="lineCat" list="catOptions" placeholder="e.g. Software / Technology" oninput="showCatTip(this.value)">
        <datalist id="catOptions">
          <?php foreach ($budgetCategories as $c): ?>
          <option value="<?= htmlspecialchars($c) ?>">
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="field">
        <label>Description</label>
        <input type="text" id="lineDesc" placeholder="Optional detail">
      </div>
    </div>
    <!-- Category tip box (shown based on selected category) -->
    <div class="cat-tip-box" id="catTipBox"></div>
    <div class="modal-row">
      <div class="field">
        <label>Budgeted Amount ($)</label>
        <input type="number" id="lineBudget" min="0" step="0.01" placeholder="0.00">
      </div>
      <div class="field">
        <label>Actual Amount ($)</label>
        <input type="number" id="lineActual" min="0" step="0.01" placeholder="0.00">
      </div>
    </div>
    <div class="field">
      <label>Notes</label>
      <textarea id="lineNotes" placeholder="Optional notes"></textarea>
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeLineModal()">Cancel</button>
      <button class="btn-save" onclick="saveLine()">Save</button>
    </div>
  </div>
</div>

<script>
// ── Category tips data ────────────────────────────────────────────────────────
const CAT_TIPS = {
    'Payroll': {
        icon: '👥',
        title: 'Typically 40–60% of total expenses',
        body: 'Align with cap collections and ensure compensation plans are documented. Track per-headcount cost to spot creep as agent count grows.'
    },
    'Office Rent': {
        icon: '🏢',
        title: 'Multi-year leases save 10–15%',
        body: 'Negotiate early-termination clauses before signing. Review square footage whenever headcount changes by more than 20%.'
    },
    'Software / Technology': {
        icon: '💻',
        title: 'Annual billing saves 15–25% vs. monthly',
        body: 'Audit seats quarterly — unused licenses add up fast. Watch for overlapping tools doing the same job (CRM, email, scheduling).'
    },
    'Marketing / Advertising': {
        icon: '📣',
        title: 'Benchmark: 5–8% of GCI for brokerages',
        body: 'Track cost-per-lead by channel so you can cut what isn\'t converting. Digital ads and social recruiting each warrant their own sub-line.'
    },
    'Professional Development': {
        icon: '🎓',
        title: '$500–$1,500 per agent/year is the industry norm',
        body: 'Higher training investment consistently correlates with lower attrition. Under-cutting this line often costs more in recruiting replacements.'
    },
    'Insurance': {
        icon: '🛡️',
        title: 'Volume discounts kick in at key agent counts',
        body: 'Review E&O annually as your headcount changes. Shop at renewal — carriers compete aggressively for brokerage accounts.'
    },
    'Utilities': {
        icon: '⚡',
        title: 'Bundle contracts for 10–20% savings',
        body: 'Negotiate internet, phone, and security together. LED lighting upgrades typically pay back in 12–18 months through energy savings.'
    },
    'Professional Fees': {
        icon: '⚖️',
        title: 'Target 1–3% of revenue for legal/accounting',
        body: 'Retainer agreements with your attorney and CPA smooth costs and ensure availability. Reactive (hourly) billing always costs more.'
    },
    'Recruiting': {
        icon: '🤝',
        title: 'Cost-per-hire: $2,000–$5,000 for producing agents',
        body: 'Factor in events, materials, staff time, and onboarding. Track this number — it justifies the spend and benchmarks your efficiency.'
    },
    'Events / Activities': {
        icon: '🎉',
        title: 'Retention events yield 3–5× ROI vs. equivalent recruiting spend',
        body: 'Agent appreciation events, milestone celebrations, and team gatherings measurably reduce attrition. Don\'t cut this line in tight periods.'
    },
    'Office Supplies': {
        icon: '📎',
        title: 'Set a per-person monthly allowance',
        body: 'Bulk ordering through Staples Business Advantage or Amazon Business nets 15–30% savings. A $50–75/person/month cap keeps this under control.'
    },
    'Travel': {
        icon: '✈️',
        title: 'Book 3+ weeks ahead for 20–30% savings',
        body: 'Set per-diem policies for meals. Requiring manager approval above a threshold catches one-off overages before they stack up.'
    },
    'Other': {
        icon: '📌',
        title: 'Consider reclassifying to a named category',
        body: 'Accurate categorization helps spot trends over time. If "Other" is consistently large, it likely deserves its own line item.'
    },
};

function showCatTip(val) {
    const box = document.getElementById('catTipBox');
    // Fuzzy match: check if typed value starts with or matches a key
    const key = Object.keys(CAT_TIPS).find(k => k.toLowerCase() === val.toLowerCase())
             || Object.keys(CAT_TIPS).find(k => k.toLowerCase().startsWith(val.toLowerCase().split('/')[0].trim()));
    if (key && CAT_TIPS[key]) {
        const t = CAT_TIPS[key];
        box.innerHTML = '<span class="ct-icon">' + t.icon + '</span>'
                      + '<strong>' + t.title + '</strong>' + t.body;
        box.style.display = 'block';
    } else {
        box.style.display = 'none';
    }
}

// ── Dept accordion ───────────────────────────────────────────────────────────
function toggleDept(hdr) {
    const body  = hdr.nextElementSibling;
    const arrow = hdr.querySelector('.dept-arrow');
    body.classList.toggle('hidden');
    arrow.classList.toggle('open');
}

// ── Period modal ─────────────────────────────────────────────────────────────
function openPeriodModal() { document.getElementById('periodModal').classList.add('open'); }
function closePeriodModal() { document.getElementById('periodModal').classList.remove('open'); }
function savePeriod() {
    const name  = document.getElementById('pName').value.trim();
    const type  = document.getElementById('pType').value;
    const start = document.getElementById('pStart').value;
    const end   = document.getElementById('pEnd').value;
    if (!name) { alert('Period name is required.'); return; }
    fetch('api/finance_budget.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'create_period', name, period_type:type, start_date:start, end_date:end})
    }).then(r=>r.json()).then(d=>{
        if (d.ok) location.href = 'finance_budget.php?period=' + d.id;
    });
}

// ── Line modal ───────────────────────────────────────────────────────────────
function openAddLine(dept) {
    document.getElementById('lineId').value = '';
    document.getElementById('lineModalTitle').textContent = 'Add Line Item';
    document.getElementById('lineDept').value = dept;
    document.getElementById('lineCat').value = '';
    document.getElementById('lineDesc').value = '';
    document.getElementById('lineBudget').value = '';
    document.getElementById('lineActual').value = '';
    document.getElementById('lineNotes').value = '';
    document.getElementById('catTipBox').style.display = 'none';
    document.getElementById('lineModal').classList.add('open');
}
function editLine(line) {
    document.getElementById('lineId').value = line.id;
    document.getElementById('lineModalTitle').textContent = 'Edit Line Item';
    document.getElementById('lineDept').value = line.department;
    document.getElementById('lineCat').value = line.category;
    document.getElementById('lineDesc').value = line.description;
    document.getElementById('lineBudget').value = line.budgeted_amt;
    document.getElementById('lineActual').value = line.actual_amt;
    document.getElementById('lineNotes').value = line.notes;
    showCatTip(line.category);
    document.getElementById('lineModal').classList.add('open');
}
function closeLineModal() {
    document.getElementById('lineModal').classList.remove('open');
}
function saveLine() {
    const id       = document.getElementById('lineId').value;
    const periodId = document.getElementById('linePeriodId').value;
    const dept     = document.getElementById('lineDept').value;
    const cat      = document.getElementById('lineCat').value.trim();
    const desc     = document.getElementById('lineDesc').value.trim();
    const budget   = parseFloat(document.getElementById('lineBudget').value) || 0;
    const actual   = parseFloat(document.getElementById('lineActual').value) || 0;
    const notes    = document.getElementById('lineNotes').value.trim();
    if (!cat) { alert('Category is required.'); return; }
    fetch('api/finance_budget.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action: id ? 'update_line' : 'add_line', id:id||null,
                              period_id:parseInt(periodId), department:dept, category:cat,
                              description:desc, budgeted_amt:budget, actual_amt:actual, notes})
    }).then(r=>r.json()).then(d=>{ if (d.ok) location.reload(); });
}

function deleteLine(id, btn) {
    if (!confirm('Remove this line item?')) return;
    fetch('api/finance_budget.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'delete_line', id})
    }).then(r=>r.json()).then(d=>{ if (d.ok) document.getElementById('row-'+id)?.remove(); });
}
function deletePeriod(id, name) {
    if (!confirm('Delete period "' + name + '" and all its line items? This cannot be undone.')) return;
    fetch('api/finance_budget.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'delete_period', id})
    }).then(r=>r.json()).then(d=>{ if (d.ok) location.href = 'finance_budget.php'; });
}

// ── AI Insights ───────────────────────────────────────────────────────────────
function getInsights(periodId) {
    const btn = document.getElementById('aiBtn');
    btn.classList.add('loading');
    btn.disabled = true;
    fetch('api/finance_insights.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'analyze', period_id: periodId})
    })
    .then(r => r.json())
    .then(d => {
        btn.classList.remove('loading');
        btn.disabled = false;
        if (!d.ok) { alert(d.error || 'Analysis failed.'); return; }
        renderInsights(d.insights);
    })
    .catch(() => { btn.classList.remove('loading'); btn.disabled = false; alert('Network error.'); });
}

function renderInsights(ins) {
    const panel = document.getElementById('insightsPanel');
    if (!ins) { panel.style.display = 'none'; return; }

    const typeIcon = { success:'✅', warning:'⚠️', critical:'🔴', tip:'💡', info:'ℹ️' };
    const healthCls = ins.overall_health || 'good';
    const score = ins.health_score ?? '';

    let html = '<div class="insights-panel-hdr">';
    html += '<span style="font-size:18px">✦</span>';
    html += '<h3>AI Budget Analysis</h3>';
    if (score !== '') html += '<span class="health-score ' + healthCls + '">' + score + '</span>';
    html += '<span class="health-badge ' + healthCls + '">' + (healthCls.charAt(0).toUpperCase() + healthCls.slice(1)) + '</span>';
    html += '</div>';

    if (ins.summary) html += '<div class="insights-summary">' + esc(ins.summary) + '</div>';

    const insights = ins.insights || [];
    if (insights.length) {
        html += '<div class="insights-grid">';
        insights.forEach(i => {
            const t = i.type || 'info';
            html += '<div class="insight-card insight-type-' + t + '">';
            html += '<div class="insight-card-dept">' + (typeIcon[t] || '') + ' ' + esc(i.dept || '') + '</div>';
            html += '<div class="insight-card-title">' + esc(i.title || '') + '</div>';
            html += '<div class="insight-card-detail">' + esc(i.detail || '') + '</div>';
            if (i.action) html += '<div class="insight-card-action">→ ' + esc(i.action) + '</div>';
            html += '</div>';
        });
        html += '</div>';
    }

    const qw = ins.quick_wins || [];
    if (qw.length) {
        html += '<div class="quick-wins"><h4>Quick Wins</h4><div class="qw-list">';
        qw.forEach(w => { html += '<div class="qw-chip">⚡ ' + esc(w) + '</div>'; });
        html += '</div></div>';
    }

    panel.innerHTML = html;
    panel.style.display = 'block';
    panel.scrollIntoView({ behavior:'smooth', block:'nearest' });
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── General tips accordion ───────────────────────────────────────────────────
function toggleTips(hdr) {
    hdr.classList.toggle('open');
    document.getElementById('tipsBody').classList.toggle('open');
}

// Close modals on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(bd => {
    bd.addEventListener('click', e => { if (e.target === bd) bd.classList.remove('open'); });
});
</script>
</body>
</html>
