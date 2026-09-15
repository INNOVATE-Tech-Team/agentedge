<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/agent_profile.php';
require_once __DIR__ . '/lib/performance_client.php';
require_once __DIR__ . '/lib/coach_production.php';

// V1: super-admin-only, same gate as coach_dashboard.php. This is a
// Coach-Dashboard-specific detail view — it does not replace or modify
// agent_profile.php, it just reuses the same data-loading helpers.
$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }

$targetEmail = strtolower(trim($_GET['email'] ?? ''));

$rosterRow = null;
$profile   = null;
$headshotKey = null;
if ($targetEmail !== '') {
    $st = local_db()->prepare(
        "SELECT id, agent_name, email, phone, market_center, state_code, license_exp
         FROM innovate_roster WHERE LOWER(TRIM(email))=? LIMIT 1"
    );
    $st->execute([$targetEmail]);
    $rosterRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    $profile     = load_agent_profile($targetEmail);
    $headshotKey = load_agent_latest_headshot($targetEmail);
}

// Not a valid/known agent — safe empty state, no fatal error either way.
$found = $rosterRow !== null || $profile !== null;

$name   = $profile['full_name']       ?? ($rosterRow['agent_name']    ?? $targetEmail);
$phone  = $profile['phone']           ?? ($rosterRow['phone']         ?? '');
$market = $rosterRow['market_center'] ?? ($profile['office_location'] ?? '');
$state  = $rosterRow['state_code']    ?? ($profile['state']           ?? '');
$brokerage = $profile['office_location'] ?? $market;
$licenseNum   = $profile['license_number'] ?? '';
$licenseState = $profile['license_state']  ?? '';
$licenseExp   = $profile['license_exp']    ?? ($rosterRow['license_exp'] ?? '');
$assignedCoach = trim($profile['coached_by'] ?? '');
$phoneDigits = preg_replace('/[^0-9+]/', '', $phone ?? '');

$initials = '';
foreach (preg_split('/\s+/', trim($name ?: '?')) as $part) { if ($part !== '') $initials .= mb_strtoupper(mb_substr($part, 0, 1)); }
$initials = mb_substr($initials ?: '?', 0, 2);

$notes = [];
if ($found && $targetEmail !== '') {
    $nst = local_db()->prepare("SELECT id, note, created_by, created_at FROM agent_notes WHERE email=? ORDER BY created_at DESC, id DESC");
    $nst->execute([$targetEmail]);
    $notes = $nst->fetchAll(PDO::FETCH_ASSOC);
}

// Phase 2A activity-only performance pilot. Signs the TARGET agent's
// email, not the viewing coach's -- this page's own is_super_admin()
// gate above already decides who may view whom; Advantage only answers
// "is this person in the pilot, and if so what's their activity data".
$perf = null;
if ($found && $targetEmail !== '') {
    // Check pilot membership with one call first -- the common case is
    // "not in the pilot" (9 of ~675 agents are), so don't spend three
    // more round trips finding that out.
    $summary = performance_api_get('summary', $targetEmail);
    if ($summary['ok'] && $summary['inPilot']) {
        $goals     = performance_api_get('goals', $targetEmail);
        $attention = performance_api_get('needs-attention', $targetEmail);
        $streaks   = performance_api_get('streaks', $targetEmail);
        $myStreak = 0;
        if ($streaks['ok'] && !empty($streaks['data']['agents'])) {
            foreach ($streaks['data']['agents'] as $a) {
                if (($a['userId'] ?? null) === ($streaks['data']['requestingUserId'] ?? null)) { $myStreak = (int)$a['currentStreak']; break; }
            }
        }
        $perf = [
            'week'      => $summary['data']['week']   ?? null,
            'goals'     => ($goals['ok'] && $goals['data']['goals'])   ? $goals['data']['goals']   : [],
            'reasons'   => ($attention['ok'])                          ? ($attention['data']['reasons'] ?? []) : [],
            'streak'    => $myStreak,
        ];
    }
}

// Coach Production (Step 6) -- entirely separate data source from the Phase
// 2A Activity Performance pilot above; deliberately not intertwined with it.
// Server-side, direct lib/coach_production.php calls (same pattern as this
// page already uses for $profile/$perf) rather than an HTTP round-trip to
// api/coach_production_summary.php, since this page is fully server-rendered
// already. No production calculations happen here -- just calls + display.
$production = null;
$productionMtd = null;
if ($rosterRow) {
    $prodDb = local_db();
    $mirrorHealth = coach_mirror_health($prodDb);
    $production = coach_selected_agent_production($prodDb, (int)$rosterRow['id'], 'ltm', [], null, $mirrorHealth);
    $productionMtd = coach_mtd_comparison($prodDb, (int)$rosterRow['id'], null, $mirrorHealth);
}

function cad_fmt_money(?float $v): string {
    if ($v === null) return '—';
    $sign = $v < 0 ? '-' : '';
    $abs = abs($v);
    if ($abs >= 1000000) return $sign . '$' . rtrim(rtrim(number_format($abs / 1000000, 1), '0'), '.') . 'M';
    if ($abs >= 1000) return $sign . '$' . round($abs / 1000) . 'K';
    return $sign . '$' . number_format($abs);
}
function cad_exact_money(?float $v): string { return $v === null ? '' : '$' . number_format($v); }
function cad_fmt_pct(?float $v): string { return $v === null ? '—' : (($v > 0 ? '+' : '') . number_format($v, 1) . '%'); }
function cad_trend_style(?float $v): string {
    if ($v === null) return 'color:var(--faint)';
    if ($v > 0) return 'color:var(--green-d)';
    if ($v < 0) return 'color:var(--red)';
    return 'color:var(--faint)';
}
// prior=0 is a real, common case -- never divide into Infinity/NaN.
function cad_mtd_delta_text(?float $current, ?float $prior): string {
    if ($current === null || $prior === null) return '—';
    if ($prior == 0.0) return $current == 0.0 ? 'No activity yet' : 'New vs. no prior-year activity';
    $pct = (($current - $prior) / $prior) * 100;
    return ($pct >= 0 ? '↑ ' : '↓ ') . number_format(abs($pct), 0) . '% vs same period last year';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($found ? $name : 'Agent Detail') ?> — Coach Dashboard — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .bo-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
    .cad-header-row{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
    .cad-avatar-img{width:56px;height:56px;border-radius:50%;object-fit:cover;flex-shrink:0;border:1px solid var(--border)}
    .cad-avatar-fallback{width:56px;height:56px;border-radius:50%;background:#e8f5d0;color:#5b8e0d;font-size:19px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .cad-meta-row{display:flex;flex-wrap:wrap;gap:4px 16px;font-size:12.5px;color:var(--muted);margin-top:4px}
    .cad-meta-row span.empty{color:var(--faint);font-style:italic}
    .cad-actions{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0 20px}
    .cad-action-btn{padding:8px 16px;border:1px solid var(--green-d);border-radius:20px;background:#fff;color:var(--green-d);font-size:12px;font-weight:800;cursor:pointer;text-decoration:none;white-space:nowrap;display:inline-flex;align-items:center;gap:5px}
    .cad-action-btn:hover{background:var(--green);color:#111}
    .cad-action-btn.disabled{border-color:var(--border);color:var(--faint);pointer-events:none;background:#fafafa}
    .dg-section{grid-column:1/-1;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--faint);margin-top:12px;padding-top:10px;border-top:1px solid var(--border)}
    .dg-section:first-child{margin-top:0;padding-top:0;border-top:none}
    .dg-field{display:flex;flex-direction:column;gap:2px}
    .dg-label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--ink)}
    .dg-value{font-size:12.5px;color:var(--muted)}
    .dg-value.empty{color:var(--faint);font-style:italic}
    .cad-contract-stage{font-size:13px;font-weight:700;color:var(--faint);margin-bottom:6px}
    .coach-progress-track{height:6px;background:var(--border);border-radius:999px;overflow:hidden;margin-bottom:6px}
    .coach-progress-fill{height:100%;background:var(--green);border-radius:999px}
    .cad-stub{padding:20px 0;text-align:center;color:var(--faint);font-size:13px}
    .note-compose{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}
    .note-compose textarea{padding:9px 11px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;min-height:60px;resize:vertical}
    .notes-list{display:flex;flex-direction:column;gap:10px}
    .note-card{border:1px solid var(--border);border-radius:8px;padding:10px 14px;background:#fafbfa}
    .note-meta{font-size:11px;color:var(--faint);margin-bottom:4px}
    .note-body{font-size:13px;white-space:pre-wrap}
    .empty-state{text-align:center;padding:40px;color:var(--faint);font-size:14px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('coach_dashboard', $agent); ?>
  <div class="content">
    <header class="content-top">
      <?php if ($found): ?>
      <div class="cad-header-row">
        <?php if ($headshotKey): ?>
          <img class="cad-avatar-img" src="api/intake.php?action=headshot&key=<?= urlencode($headshotKey) ?>&thumb=1" alt="" loading="lazy">
        <?php else: ?>
          <div class="cad-avatar-fallback"><?= h($initials) ?></div>
        <?php endif; ?>
        <div>
          <div class="bo-eyebrow">Coach Dashboard &middot; Agent Detail</div>
          <div class="content-title"><?= h($name) ?></div>
          <div class="cad-meta-row">
            <span><?= $brokerage ? h($brokerage) : '<span class="empty">No brokerage on file</span>' ?></span>
            <span><?= $state ? h($state) : '<span class="empty">No market on file</span>' ?></span>
            <span><?= $licenseNum ? h($licenseNum . ($licenseState ? " ($licenseState)" : '')) : '<span class="empty">No license on file</span>' ?></span>
            <span><?= $phone ? h($phone) : '<span class="empty">No phone on file</span>' ?></span>
            <span><?= $targetEmail !== '' ? h($targetEmail) : '<span class="empty">No email on file</span>' ?></span>
            <span>Coach: <?= $assignedCoach !== '' ? h($assignedCoach) : '<span class="empty">Unassigned</span>' ?></span>
          </div>
        </div>
      </div>
      <?php else: ?>
      <div>
        <div class="bo-eyebrow">Coach Dashboard &middot; Agent Detail</div>
        <div class="content-title">Agent Detail</div>
      </div>
      <?php endif; ?>
      <a href="coach_dashboard.php" class="btn-detail-link">&larr; Back to Coach Dashboard</a>
    </header>
    <main class="wrap" style="max-width:1100px">

    <?php if (!$found): ?>
      <div class="card"><div class="empty-state">No agent selected, or this agent could not be found.<br>Return to the <a href="coach_dashboard.php">Coach Dashboard</a> and select an agent from the list.</div></div>
    <?php else: ?>

      <div class="cad-actions">
        <?php if ($targetEmail !== ''): ?><a class="cad-action-btn" href="mailto:<?= h($targetEmail) ?>">Email</a><?php else: ?><span class="cad-action-btn disabled">Email</span><?php endif; ?>
        <?php if ($phoneDigits !== ''): ?><a class="cad-action-btn" href="sms:<?= h($phoneDigits) ?>">Text</a><?php else: ?><span class="cad-action-btn disabled">Text</span><?php endif; ?>
        <?php if ($phoneDigits !== ''): ?><a class="cad-action-btn" href="tel:<?= h($phoneDigits) ?>">Call</a><?php else: ?><span class="cad-action-btn disabled">Call</span><?php endif; ?>
        <span class="cad-action-btn disabled" title="Task tracking doesn't exist yet in AgentEdge">Add Task</span>
        <button type="button" class="cad-action-btn" onclick="document.getElementById('cad-note-compose').scrollIntoView({behavior:'smooth'});document.getElementById('cad-note-text').focus()">Add Note</button>
      </div>

      <div class="grid2">
        <div class="card">
          <h2>Coaching Contract</h2>
          <div class="cad-contract-stage">No coaching contract on file</div>
          <div class="coach-progress-track"><div class="coach-progress-fill" style="width:0%"></div></div>
          <div class="detail-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:6px 24px">
            <div class="dg-field"><span class="dg-label">Month</span><span class="dg-value empty">— of —</span></div>
            <div class="dg-field"><span class="dg-label">Contract End</span><span class="dg-value empty">—</span></div>
            <div class="dg-field"><span class="dg-label">Sessions Used</span><span class="dg-value empty">—</span></div>
            <div class="dg-field"><span class="dg-label">Sessions Purchased</span><span class="dg-value empty">—</span></div>
          </div>
        </div>

        <div class="card">
          <h2>Sessions &amp; Goals</h2>
          <div class="detail-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:6px 24px">
            <div class="dg-field"><span class="dg-label">Next Session</span><span class="dg-value empty">Not scheduled</span></div>
            <div class="dg-field"><span class="dg-label">Last Coaching Touch</span><span class="dg-value empty">—</span></div>
          </div>
          <div class="cad-stub">No coaching goals on file yet.</div>
        </div>

        <div class="card">
          <h2>Activity Performance <span style="font-size:10px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.05em">(pilot)</span></h2>
          <?php if ($perf === null): ?>
            <div class="cad-stub">This agent isn't part of the activity performance pilot yet.<br>Deal-based production (appointments/signed/closed) isn't tracked here yet either way — see the Phase 1 readiness report.</div>
          <?php else: ?>
            <div class="detail-grid" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px 24px;margin-bottom:14px">
              <div class="dg-field"><span class="dg-label">Dials (this week)</span><span class="dg-value"><?= $perf['week'] ? h($perf['week']['dials']) : '<span class="empty">—</span>' ?></span></div>
              <div class="dg-field"><span class="dg-label">Connects (this week)</span><span class="dg-value"><?= $perf['week'] ? h($perf['week']['connects']) : '<span class="empty">—</span>' ?></span></div>
              <div class="dg-field"><span class="dg-label">Current Streak</span><span class="dg-value"><?= h($perf['streak']) ?> day<?= $perf['streak'] === 1 ? '' : 's' ?></span></div>
            </div>
            <?php if ($perf['reasons']): ?>
              <div style="margin-bottom:12px">
                <?php foreach ($perf['reasons'] as $r): ?>
                  <div class="cad-stub" style="padding:8px 0;text-align:left;color:#7a5c00"><?= h($r['detail']) ?></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if ($perf['goals']): ?>
              <div class="dg-label" style="margin-bottom:6px">Goal Pacing</div>
              <?php foreach ($perf['goals'] as $g): $pct = $g['target'] > 0 ? min(100, round(100 * $g['actualToDate'] / $g['target'])) : 0; ?>
                <div style="margin-bottom:8px">
                  <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:3px">
                    <span style="text-transform:capitalize"><?= h(str_replace('_', ' ', $g['metric'])) ?> (<?= h($g['period']) ?>ly)</span>
                    <span style="color:var(--muted)"><?= h($g['actualToDate']) ?> / <?= h($g['target']) ?></span>
                  </div>
                  <div class="coach-progress-track"><div class="coach-progress-fill" style="width:<?= $pct ?>%<?= $g['behindPace'] ? ';background:#e0a030' : '' ?>"></div></div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="cad-stub">No activity goals set for this agent yet.</div>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <div class="card">
          <h2>Production</h2>
          <?php if (!$rosterRow): ?>
            <div class="cad-stub">No roster record for this agent — production can't be looked up.</div>
          <?php elseif ($production['production_status'] === 'unmatched'): ?>
            <div class="cad-stub">Production unavailable — agent identity not matched.</div>
          <?php elseif ($production['production_status'] === 'mirror_unavailable'): ?>
            <div class="cad-stub">Production mirror is currently unavailable.</div>
          <?php else:
            $closed = $production['closed'];
            $extra  = $production['ltm_extra'] ?? [];
            $active = $production['active_listings'];
            $mtd    = $productionMtd;
          ?>
            <div class="detail-grid" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px 24px;margin-bottom:8px">
              <div class="dg-field"><span class="dg-label">LTM Volume</span><span class="dg-value" title="<?= h(cad_exact_money($closed['volume'])) ?>"><?= h(cad_fmt_money($closed['volume'])) ?></span></div>
              <div class="dg-field"><span class="dg-label">LTM Transactions</span><span class="dg-value"><?= $closed['transactions'] !== null ? h($closed['transactions']) : '—' ?></span></div>
              <div class="dg-field"><span class="dg-label">LTM Sides</span><span class="dg-value"><?= $closed['sides'] !== null ? h($closed['sides']) : '—' ?></span></div>
              <div class="dg-field"><span class="dg-label">Avg Sale Price</span><span class="dg-value" title="<?= h(cad_exact_money($closed['average_sale_price'])) ?>"><?= h(cad_fmt_money($closed['average_sale_price'])) ?></span></div>
              <div class="dg-field"><span class="dg-label">Trend</span><span class="dg-value" style="<?= h(cad_trend_style($extra['trend_pct_12mo'] ?? null)) ?>"><?= h(cad_fmt_pct($extra['trend_pct_12mo'] ?? null)) ?></span></div>
              <div class="dg-field"><span class="dg-label">List / Buy Sides</span><span class="dg-value"><?= isset($extra['list_side_12mo']) ? h($extra['list_side_12mo']) : '—' ?> / <?= isset($extra['buy_side_12mo']) ? h($extra['buy_side_12mo']) : '—' ?></span></div>
            </div>
            <div class="dg-section">Current Active Listings</div>
            <div class="detail-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:6px 24px;margin-bottom:8px">
              <div class="dg-field"><span class="dg-label">Count</span><span class="dg-value"><?= $active !== null && $active['count'] !== null ? h($active['count']) : '—' ?></span></div>
              <div class="dg-field"><span class="dg-label">Volume</span><span class="dg-value" title="<?= h(cad_exact_money($active['volume'] ?? null)) ?>"><?= h(cad_fmt_money($active !== null ? $active['volume'] : null)) ?></span></div>
            </div>
            <?php
            $mtdCurrentVol = $mtd['current']['volume'] ?? null;
            $mtdPriorVol   = $mtd['prior_year']['volume'] ?? null;
            $mtdPct = null;
            if ($mtdCurrentVol !== null && $mtdPriorVol !== null && $mtdPriorVol > 0) {
                $mtdPct = ($mtdCurrentVol - $mtdPriorVol) / $mtdPriorVol * 100;
            }
            ?>
            <div class="dg-section">MTD (Closed Volume)</div>
            <div class="detail-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:6px 24px">
              <div class="dg-field"><span class="dg-label">This Month</span><span class="dg-value"><?= h(cad_fmt_money($mtdCurrentVol)) ?></span></div>
              <div class="dg-field"><span class="dg-label">vs Same Period Last Year</span><span class="dg-value" style="<?= h(cad_trend_style($mtdPct)) ?>"><?= h(cad_mtd_delta_text($mtdCurrentVol, $mtdPriorVol)) ?></span></div>
            </div>
            <?php if (!empty($extra['last_close_date'])): ?>
              <div class="cad-meta-row" style="margin-top:10px"><span>Last close: <?= h($extra['last_close_date']) ?></span></div>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <div class="card">
          <h2>Coaching Notes</h2>
          <div id="cad-note-compose" class="note-compose">
            <textarea id="cad-note-text" placeholder="Add a coaching note about this agent…"></textarea>
            <div style="display:flex;align-items:center;gap:10px">
              <button type="button" class="btn-save" style="padding:8px 18px" onclick="cadAddNote()">Save Note</button>
              <span id="cad-note-msg" class="form-msg"></span>
            </div>
          </div>
          <div class="notes-list" id="cad-notes-list">
            <?php if (!$notes): ?>
              <div class="cad-stub" id="cad-notes-empty">No notes yet.</div>
            <?php else: foreach ($notes as $n): ?>
              <div class="note-card">
                <div class="note-meta"><?= h($n['created_by']) ?> &mdash; <?= h($n['created_at']) ?></div>
                <div class="note-body"><?= h($n['note']) ?></div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>

    <?php endif; ?>

    </main>
  </div>
</div>
<script>
var CAD_EMAIL = <?= json_encode($targetEmail) ?>;
function cadAddNote() {
  var textEl = document.getElementById('cad-note-text');
  var msg = document.getElementById('cad-note-msg');
  var note = textEl.value.trim();
  if (!note || !CAD_EMAIL) return;
  msg.textContent = 'Saving…'; msg.className = 'form-msg';
  fetch('api/agent_notes.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({email: CAD_EMAIL, note: note})
  }).then(r => r.json()).then(res => {
    if (!res.ok) { msg.textContent = res.error || 'Save failed.'; msg.className = 'form-msg err'; return; }
    var list = document.getElementById('cad-notes-list');
    var emptyMsg = document.getElementById('cad-notes-empty');
    if (emptyMsg) emptyMsg.remove();
    var card = document.createElement('div');
    card.className = 'note-card';
    var meta = document.createElement('div'); meta.className = 'note-meta'; meta.textContent = res.created_by + ' — just now';
    var body = document.createElement('div'); body.className = 'note-body'; body.textContent = note;
    card.appendChild(meta); card.appendChild(body);
    list.insertBefore(card, list.firstChild);
    textEl.value = ''; msg.textContent = 'Saved ✓'; msg.className = 'form-msg ok';
  }).catch(() => { msg.textContent = 'Network error.'; msg.className = 'form-msg err'; });
}
</script>
</body>
</html>
