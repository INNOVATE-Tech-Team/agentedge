<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }

$db = local_db();
$rows = $db->query("SELECT * FROM exchange_milestones ORDER BY phase, sort_order, id")->fetchAll(PDO::FETCH_ASSOC);

$byPhase = [1 => [], 2 => [], 3 => []];
foreach ($rows as $r) { $byPhase[(int)$r['phase']][] = $r; }

$phaseLabels = [1 => 'Year 1 — Foundation', 2 => 'Year 2 — Build', 3 => 'Year 3 — Uplift'];
$phaseDates  = [1 => 'Jul 2026 – Jun 2027', 2 => 'Jul 2027 – Jun 2028', 3 => 'Jul 2030 – Jun 2031'];
$phaseGoals  = [
    1 => 'Become audit-ready and governance-ready. Hire a PCAOB auditor, securities attorney, and fractional CFO. Nothing else matters until these are in place.',
    2 => 'Complete 2 years of PCAOB audits, build your investor story, and list on OTCQB (OTC Markets).',
    3 => 'Uplift from OTCQB to NASDAQ Capital Market via a Regulation A+ Tier 2 offering.',
];
$statusColors = ['pending' => '#9ca3af', 'in_progress' => '#f59e0b', 'complete' => '#22c55e'];
$statusLabels = ['pending' => 'Pending', 'in_progress' => 'In Progress', 'complete' => 'Complete'];

function er_progress(array $milestones): array {
    $total = count($milestones);
    $done  = count(array_filter($milestones, fn($m) => $m['status'] === 'complete'));
    return ['total' => $total, 'done' => $done, 'pct' => $total ? (int)round($done / $total * 100) : 0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Exchange Readiness — AgentEdge</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/app.css">
<style>
.er-wrap { max-width: 860px; margin: 0 auto; }
.er-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 32px; }
.er-kpi { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; }
.er-kpi-label { display: flex; align-items: center; gap: 6px; color: var(--faint); font-size: 12px; margin-bottom: 6px; }
.er-kpi-value { font-size: 24px; font-weight: 700; line-height: 1; }
.er-kpi-target { font-size: 11px; color: var(--faint); margin-top: 4px; }

.er-phase { margin-bottom: 24px; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.er-phase-hdr { padding: 16px 20px; border-bottom: 1px solid var(--border); }
.er-phase-hdr.complete { background: #f0fdf4; }
.er-phase-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
.er-phase-name { font-weight: 700; font-size: 15px; }
.er-phase-dates { font-size: 12px; color: var(--faint); margin-top: 2px; }
.er-phase-pct { font-weight: 700; font-size: 16px; }
.er-phase-pct.complete { color: #16a34a; }
.er-phase-count { font-size: 11px; color: var(--faint); }
.er-bar-track { height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; }
.er-bar-fill { height: 100%; border-radius: 3px; background: var(--green); }
.er-bar-fill.complete { background: #22c55e; }
.er-phase-goal { font-size: 12px; color: var(--faint); margin-top: 8px; }

.er-cat-label { padding: 8px 20px 4px; font-size: 11px; font-weight: 600; color: var(--faint); text-transform: uppercase; letter-spacing: .05em; background: var(--bg); }
.er-row { border-top: 1px solid var(--border); }
.er-row-main { display: flex; align-items: flex-start; gap: 12px; padding: 12px 20px; cursor: pointer; }
.er-status-dot { margin-top: 2px; flex-shrink: 0; width: 18px; height: 18px; border-radius: 50%; border: 2px solid; cursor: pointer; }
.er-row-body { flex: 1; min-width: 0; }
.er-row-title { font-weight: 500; font-size: 14px; }
.er-row-title.complete { color: var(--faint); text-decoration: line-through; }
.er-row-date { font-size: 11px; color: var(--faint); margin-top: 2px; }
.er-row-done { color: #22c55e; margin-left: 8px; }
.er-row-note-preview { font-size: 12px; color: var(--faint); margin-top: 4px; font-style: italic; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 520px; }
.er-chevron { flex-shrink: 0; margin-top: 3px; color: var(--faint); transition: transform .15s; font-size: 13px; }
.er-chevron.open { transform: rotate(90deg); }

.er-detail { padding: 0 20px 16px 50px; display: none; }
.er-detail.open { display: block; }
.er-detail-desc { font-size: 13px; color: var(--muted); margin-bottom: 12px; line-height: 1.5; }
.er-field-label { font-size: 11px; font-weight: 600; color: var(--faint); margin-bottom: 4px; }
.er-status-btns { display: flex; gap: 6px; margin-bottom: 14px; }
.er-status-btn { padding: 4px 10px; border-radius: 6px; border: 1px solid; font-size: 12px; cursor: pointer; background: transparent; }
.er-notes { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; font-family: inherit; resize: vertical; background: var(--bg); color: var(--ink); }
.er-hint { font-size: 12px; color: var(--faint); text-align: center; margin-top: 8px; }
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('finance_exchange_readiness', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="fin-eyebrow">Back Office / Finance</div>
      <div class="content-title">Exchange Readiness</div>
    </div>
    <div class="content-hello">3-year roadmap to OTCQB (2028) → NASDAQ Capital Market (2031). Comparable: eXp World Holdings.</div>
  </div>
  <div class="wrap">
    <div class="er-wrap">

      <div class="er-kpi-grid">
        <div class="er-kpi">
          <div class="er-kpi-label">PCAOB Audits Done</div>
          <div class="er-kpi-value">0</div>
          <div class="er-kpi-target">Target: 3 needed</div>
        </div>
        <div class="er-kpi">
          <div class="er-kpi-label">Target Listing</div>
          <div class="er-kpi-value">NASDAQ</div>
          <div class="er-kpi-target">Target: Q3–Q4 2031</div>
        </div>
      </div>

      <?php foreach ([1, 2, 3] as $phase):
        $milestones = $byPhase[$phase];
        $prog = er_progress($milestones);
        $isComplete = $prog['pct'] === 100;
      ?>
      <div class="er-phase">
        <div class="er-phase-hdr<?= $isComplete ? ' complete' : '' ?>">
          <div class="er-phase-top">
            <div>
              <div class="er-phase-name"><?= htmlspecialchars($phaseLabels[$phase]) ?></div>
              <div class="er-phase-dates"><?= htmlspecialchars($phaseDates[$phase]) ?></div>
            </div>
            <div style="text-align:right">
              <div class="er-phase-pct<?= $isComplete ? ' complete' : '' ?>"><?= $prog['pct'] ?>%</div>
              <div class="er-phase-count"><?= $prog['done'] ?>/<?= $prog['total'] ?> complete</div>
            </div>
          </div>
          <div class="er-bar-track"><div class="er-bar-fill<?= $isComplete ? ' complete' : '' ?>" style="width:<?= $prog['pct'] ?>%"></div></div>
          <div class="er-phase-goal"><?= htmlspecialchars($phaseGoals[$phase]) ?></div>
        </div>

        <?php
        $cats = [];
        foreach ($milestones as $m) { if (!in_array($m['category'], $cats, true)) $cats[] = $m['category']; }
        foreach ($cats as $cat):
        ?>
        <div class="er-cat-label"><?= htmlspecialchars($cat) ?></div>
        <?php foreach ($milestones as $m): if ($m['category'] !== $cat) continue;
          $id = (int)$m['id'];
          $color = $statusColors[$m['status']] ?? '#9ca3af';
          $targetLabel = $m['target_date'] ? date('M Y', strtotime($m['target_date'])) : '';
          $doneLabel = $m['completed_date'] ? date('M Y', strtotime($m['completed_date'])) : '';
        ?>
        <div class="er-row" id="row-<?= $id ?>">
          <div class="er-row-main" onclick="erToggleExpand(<?= $id ?>)">
            <div class="er-status-dot" style="border-color:<?= $color ?>;background:<?= $m['status'] === 'complete' ? $color : 'transparent' ?>"
                 onclick="event.stopPropagation();erCycleStatus(<?= $id ?>,'<?= $m['status'] ?>')"
                 title="Status: <?= $statusLabels[$m['status']] ?> — click to cycle"></div>
            <div class="er-row-body">
              <div class="er-row-title<?= $m['status'] === 'complete' ? ' complete' : '' ?>"><?= htmlspecialchars($m['title']) ?></div>
              <?php if ($targetLabel): ?>
              <div class="er-row-date">Target: <?= $targetLabel ?><?php if ($doneLabel): ?><span class="er-row-done">✓ Done <?= $doneLabel ?></span><?php endif; ?></div>
              <?php endif; ?>
              <?php if ($m['notes']): ?>
              <div class="er-row-note-preview" id="note-preview-<?= $id ?>">Note: <?= htmlspecialchars($m['notes']) ?></div>
              <?php endif; ?>
            </div>
            <div class="er-chevron" id="chevron-<?= $id ?>">▶</div>
          </div>
          <div class="er-detail" id="detail-<?= $id ?>">
            <?php if ($m['description']): ?><div class="er-detail-desc"><?= htmlspecialchars($m['description']) ?></div><?php endif; ?>
            <div class="er-field-label">STATUS</div>
            <div class="er-status-btns">
              <?php foreach (['pending', 'in_progress', 'complete'] as $s): $active = $m['status'] === $s; ?>
              <button class="er-status-btn" style="border-color:<?= $statusColors[$s] ?>;color:<?= $active ? '#fff' : $statusColors[$s] ?>;background:<?= $active ? $statusColors[$s] : 'transparent' ?>;font-weight:<?= $active ? 700 : 400 ?>"
                      onclick="erSetStatus(<?= $id ?>,'<?= $s ?>')"><?= $statusLabels[$s] ?></button>
              <?php endforeach; ?>
            </div>
            <div class="er-field-label">NOTES</div>
            <textarea class="er-notes" id="notes-<?= $id ?>" rows="3" placeholder="Add notes, contacts, links, or context for this milestone…"
                      onblur="erSaveNotes(<?= $id ?>)"><?= htmlspecialchars($m['notes'] ?? '') ?></textarea>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>

      <div class="er-hint">Click the circle icon on any milestone to cycle its status. Expand a row to add notes.</div>
    </div>
  </div>
</div>
</div>
<script>
function erToggleExpand(id) {
  document.getElementById('detail-' + id).classList.toggle('open');
  document.getElementById('chevron-' + id).classList.toggle('open');
}
function erPost(payload) {
  return fetch('api/finance_exchange_readiness.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  }).then(r => r.json());
}
function erCycleStatus(id, current) {
  const next = current === 'complete' ? 'pending' : current === 'pending' ? 'in_progress' : 'complete';
  erSetStatus(id, next);
}
function erSetStatus(id, status) {
  erPost({ action: 'update', id: id, status: status }).then(d => { if (d.ok) location.reload(); });
}
function erSaveNotes(id) {
  const notes = document.getElementById('notes-' + id).value;
  erPost({ action: 'update', id: id, notes: notes }).then(d => { if (d.ok) location.reload(); });
}
</script>
</body>
</html>
