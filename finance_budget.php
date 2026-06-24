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

// Selected period — default to most recent, or first in list.
$selectedId = (int)($_GET['period'] ?? ($periods[0]['id'] ?? 0));
$selectedPeriod = null;
foreach ($periods as $p) { if ($p['id'] == $selectedId) { $selectedPeriod = $p; break; } }

$lines = [];
if ($selectedId) {
    $st = $db->prepare("SELECT * FROM budget_lines WHERE period_id=? ORDER BY department, category, id");
    $st->execute([$selectedId]);
    $lines = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Group lines by department.
$byDept = [];
foreach ($lines as $l) {
    $byDept[$l['department']][] = $l;
}

$depts = ['Operations','Finance','Broker Files','Events','Agent Development','Technology','Human Resources'];
$budgetCategories = [
    'Payroll','Office Rent','Software / Technology','Marketing / Advertising',
    'Professional Development','Insurance','Utilities','Professional Fees',
    'Recruiting','Events / Activities','Office Supplies','Travel','Other',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Department Budget — AgentEdge</title>
<link rel="stylesheet" href="assets/app.css">
<style>
/* ── page chrome ── */
.fin-eyebrow { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--faint); }

/* ── period bar ── */
.period-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:20px; }
.period-select { padding:7px 12px; border:1px solid var(--border); border-radius:8px; font-size:14px;
                 background:#fff; cursor:pointer; }
.period-select:focus { outline:2px solid var(--green); }
.btn-new-period { padding:7px 16px; background:var(--green); color:#111; border:0; border-radius:8px;
                  font-weight:700; font-size:13px; cursor:pointer; white-space:nowrap; }
.btn-new-period:hover { background:var(--green-d); color:#fff; }
.period-label { font-size:13px; color:var(--muted); }

/* ── summary cards ── */
.summary-row { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px; margin-bottom:24px; }
.sum-card { background:#fff; border:1px solid var(--border); border-radius:10px; padding:14px 16px; }
.sum-card-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--faint); margin-bottom:4px; }
.sum-card-val { font-size:22px; font-weight:800; color:var(--ink); }
.sum-card-val.over { color:#d73a49; }
.sum-card-val.under { color:#2e7d32; }

/* ── dept cards ── */
.dept-card { background:#fff; border:1px solid var(--border); border-radius:10px; margin-bottom:16px; overflow:hidden; }
.dept-hdr { display:flex; align-items:center; gap:10px; padding:14px 16px; cursor:pointer; user-select:none; background:#fafbfa; border-bottom:1px solid var(--border); }
.dept-hdr:hover { background:#f4f9ec; }
.dept-hdr-name { font-size:15px; font-weight:700; flex:1; }
.dept-hdr-nums { display:flex; gap:18px; font-size:13px; }
.dept-hdr-nums span { color:var(--muted); }
.dept-hdr-nums strong { color:var(--ink); }
.dept-progress { width:120px; height:8px; background:#e5e7eb; border-radius:4px; overflow:hidden; display:inline-block; vertical-align:middle; margin-left:8px; }
.dept-progress-fill { height:100%; background:var(--green); border-radius:4px; transition:width .3s; }
.dept-progress-fill.over { background:#d73a49; }
.dept-arrow { font-size:11px; color:var(--faint); transition:transform .2s; }
.dept-arrow.open { transform:rotate(90deg); }
.dept-body { padding:0; }
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
.bl-progress { width:80px; height:6px; background:#e5e7eb; border-radius:3px; overflow:hidden; display:inline-block; vertical-align:middle; }
.bl-progress-fill { height:100%; background:var(--green); border-radius:3px; }
.bl-progress-fill.over { background:#d73a49; }
.btn-icon { background:none; border:none; cursor:pointer; padding:3px 6px; border-radius:4px; color:var(--faint); font-size:14px; line-height:1; }
.btn-icon:hover { background:#f0f0f0; color:var(--ink); }
.btn-icon.del:hover { background:#fdecea; color:#d73a49; }
.add-line-row td { background:#f9fefb; }
.add-line-btn { display:flex; align-items:center; gap:6px; padding:8px 12px; font-size:13px; color:var(--green-d);
                font-weight:600; cursor:pointer; border:none; background:none; }
.add-line-btn:hover { color:var(--green); }

/* ── empty state ── */
.budget-empty { text-align:center; padding:60px 24px; color:var(--faint); }
.budget-empty h3 { font-size:17px; font-weight:700; margin-bottom:6px; color:var(--ink); }

/* ── modals ── */
.modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:200; display:none; align-items:center; justify-content:center; }
.modal-backdrop.open { display:flex; }
.modal { background:#fff; border-radius:12px; padding:24px; width:440px; max-width:calc(100vw - 32px); box-shadow:0 8px 32px rgba(0,0,0,.18); }
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
      <span class="period-label">No periods yet — create one to get started.</span>
      <?php endif; ?>
      <button class="btn-new-period" onclick="openPeriodModal()">+ New Period</button>
      <?php if ($selectedPeriod): ?>
      <button class="btn-icon del" style="margin-left:auto;font-size:12px;padding:5px 10px;border:1px solid #ffd7d7;color:#d73a49;border-radius:6px"
              onclick="deletePeriod(<?= $selectedId ?>, <?= htmlspecialchars(json_encode($selectedPeriod['name'])) ?>)">
        Delete Period
      </button>
      <?php endif; ?>
    </div>

    <?php if ($selectedPeriod): ?>

    <!-- Summary cards -->
    <?php
    $totalBudget = array_sum(array_column($lines, 'budgeted_amt'));
    $totalActual = array_sum(array_column($lines, 'actual_amt'));
    $totalVar    = $totalBudget - $totalActual;
    $pctUsed     = $totalBudget > 0 ? round($totalActual / $totalBudget * 100) : 0;
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
      <div class="sum-card">
        <div class="sum-card-label">Line Items</div>
        <div class="sum-card-val"><?= count($lines) ?></div>
      </div>
    </div>

    <!-- Dept cards -->
    <?php foreach ($depts as $deptName):
      $deptLines = $byDept[$deptName] ?? [];
      if (empty($deptLines)) continue;
      $dBudget = array_sum(array_column($deptLines, 'budgeted_amt'));
      $dActual = array_sum(array_column($deptLines, 'actual_amt'));
      $dVar    = $dBudget - $dActual;
      $dPct    = $dBudget > 0 ? min(round($dActual / $dBudget * 100), 200) : 0;
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
        <div class="dept-progress">
          <div class="dept-progress-fill <?= $dPct>100?'over':'' ?>" style="width:<?= min($dPct,100) ?>%"></div>
        </div>
        <span style="font-size:11px;color:var(--faint);margin-left:4px"><?= $dPct ?>%</span>
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
              <th style="width:90px">Usage</th>
              <th>Notes</th>
              <th style="width:70px"></th>
            </tr>
          </thead>
          <tbody id="tbl-<?= htmlspecialchars($deptName) ?>">
          <?php foreach ($deptLines as $l):
            $var = $l['budgeted_amt'] - $l['actual_amt'];
            $pct = $l['budgeted_amt'] > 0 ? min(round($l['actual_amt']/$l['budgeted_amt']*100),200) : 0;
          ?>
            <tr id="row-<?= $l['id'] ?>">
              <td><strong><?= htmlspecialchars($l['category']) ?></strong></td>
              <td style="color:var(--muted)"><?= htmlspecialchars($l['description']) ?></td>
              <td>$<?= number_format($l['budgeted_amt'],2) ?></td>
              <td>$<?= number_format($l['actual_amt'],2) ?></td>
              <td class="<?= $var<0?'bl-var-neg':'bl-var-pos' ?>"><?= $var<0?'-':'' ?>$<?= number_format(abs($var),2) ?></td>
              <td>
                <div class="bl-progress">
                  <div class="bl-progress-fill <?= $pct>100?'over':'' ?>" style="width:<?= min($pct,100) ?>%"></div>
                </div>
                <span style="font-size:11px;color:var(--muted);margin-left:4px"><?= $pct ?>%</span>
              </td>
              <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($l['notes']) ?></td>
              <td>
                <button class="btn-icon" onclick="editLine(<?= htmlspecialchars(json_encode($l)) ?>)" title="Edit">✏️</button>
                <button class="btn-icon del" onclick="deleteLine(<?= $l['id'] ?>, this)" title="Delete">✕</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="add-line-row">
              <td colspan="8">
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

    <!-- Add lines for depts with no lines yet -->
    <div class="card" style="padding:16px">
      <div style="font-size:13px;font-weight:600;margin-bottom:10px;color:var(--muted)">Add a line item to a department:</div>
      <div style="display:flex;flex-wrap:wrap;gap:8px">
        <?php foreach ($depts as $deptName): ?>
        <button class="btn-icon" style="border:1px solid var(--border);padding:6px 12px;font-size:12px;border-radius:6px;color:var(--ink)"
                onclick="openAddLine(<?= htmlspecialchars(json_encode($deptName)) ?>)">
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
        <input type="text" id="lineCat" list="catOptions" placeholder="e.g. Software / Technology">
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
function toggleDept(hdr) {
    const body = hdr.nextElementSibling;
    const arrow = hdr.querySelector('.dept-arrow');
    body.classList.toggle('hidden');
    arrow.classList.toggle('open');
}

function openPeriodModal() {
    document.getElementById('periodModal').classList.add('open');
}
function closePeriodModal() {
    document.getElementById('periodModal').classList.remove('open');
}
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

function openAddLine(dept) {
    document.getElementById('lineId').value = '';
    document.getElementById('lineModalTitle').textContent = 'Add Line Item';
    document.getElementById('lineDept').value = dept;
    document.getElementById('lineCat').value = '';
    document.getElementById('lineDesc').value = '';
    document.getElementById('lineBudget').value = '';
    document.getElementById('lineActual').value = '';
    document.getElementById('lineNotes').value = '';
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
    const action = id ? 'update_line' : 'add_line';
    fetch('api/finance_budget.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action, id:id||null, period_id:parseInt(periodId), department:dept,
                              category:cat, description:desc, budgeted_amt:budget, actual_amt:actual, notes})
    }).then(r=>r.json()).then(d=>{
        if (d.ok) location.reload();
    });
}

function deleteLine(id, btn) {
    if (!confirm('Remove this line item?')) return;
    fetch('api/finance_budget.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'delete_line', id})
    }).then(r=>r.json()).then(d=>{
        if (d.ok) { document.getElementById('row-'+id)?.remove(); }
    });
}

function deletePeriod(id, name) {
    if (!confirm('Delete period "' + name + '" and all its line items? This cannot be undone.')) return;
    fetch('api/finance_budget.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'delete_period', id})
    }).then(r=>r.json()).then(d=>{
        if (d.ok) location.href = 'finance_budget.php';
    });
}

// Close modals on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(bd=>{
    bd.addEventListener('click', e=>{ if(e.target===bd) bd.classList.remove('open'); });
});
</script>
</body>
</html>
