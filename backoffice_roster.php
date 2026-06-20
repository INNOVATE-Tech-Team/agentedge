<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/nav.php';
$agent = require_login();
$perms = current_perms();
if (empty($perms['isAdmin'])) {
    header('Location: index.php'); exit;
}

$today  = date('Y-m-d');
$warn60 = date('Y-m-d', strtotime('+60 days'));

// Pull active roster rows only, group by state then market_center.
$rows = local_db()
    ->query("SELECT * FROM innovate_roster WHERE active=1 ORDER BY state_code, market_center, agent_name")
    ->fetchAll(PDO::FETCH_ASSOC);

$byState = [];
foreach ($rows as $r) {
    $st = $r['state_code'];
    $mc = $r['market_center'] ?: '—';
    $byState[$st][$mc][] = $r;
}

// Unique agent count (agents in multiple states count once).
$uniqueTotal = (int)local_db()
    ->query("SELECT COUNT(DISTINCT agent_name) FROM innovate_roster WHERE active=1")
    ->fetchColumn();

// Summary counts per state (unique within each state).
$stateMeta = [];
foreach ($byState as $st => $mcs) {
    $total = 0; $exp = 0; $warn = 0; $seen = [];
    foreach ($mcs as $mc => $agents) {
        foreach ($agents as $a) {
            $key = strtolower($a['agent_name']);
            if (isset($seen[$key])) continue;
            $seen[$key] = true; $total++;
            if ($a['license_exp'] && $a['license_exp'] <= $today) $exp++;
            elseif ($a['license_exp'] && $a['license_exp'] <= $warn60) $warn++;
        }
    }
    $stateMeta[$st] = ['total'=>$total,'mc_count'=>count($mcs),'expired'=>$exp,'expiring'=>$warn];
}

$stateNames = [
    'FL'=>'Florida','GA'=>'Georgia','SC'=>'South Carolina','NC'=>'North Carolina',
    'TN'=>'Tennessee','VA'=>'Virginia','MD'=>'Maryland','DE'=>'Delaware',
    'NJ'=>'New Jersey','PA'=>'Pennsylvania','OH'=>'Ohio','MA'=>'Massachusetts',
    'RI'=>'Rhode Island','NH'=>'New Hampshire',
];
$stateTiers = ['FL'=>1,'VA'=>1,'DE'=>1,'RI'=>1,'NH'=>1,'OH'=>1,'NC'=>2,'GA'=>2,'PA'=>2,'SC'=>3,'MD'=>3,'TN'=>3,'NJ'=>3,'MA'=>3];
$tierLabel  = [1=>'Auto',2=>'Purchase',3=>'FOIA'];
$tierClass  = [1=>'tier1',2=>'tier2',3=>'tier3'];

$activeState = $_GET['state'] ?? (array_key_first($byState) ?: 'SC');
if (!isset($byState[$activeState])) $activeState = array_key_first($byState) ?: 'SC';

// MC list for the active state (for the add-agent datalist)
$mcList = [];
if (isset($byState[$activeState])) {
    $mcList = array_keys($byState[$activeState]);
    if (($i = array_search('—', $mcList)) !== false) unset($mcList[$i]);
    $mcList = array_values($mcList);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Agent Roster — AgentEdge</title>
<link rel="stylesheet" href="assets/app.css">
<style>
.bo-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
.roster-summary{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.rs-tile{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px 18px;min-width:120px}
.rs-tile .rs-num{font-size:26px;font-weight:800;line-height:1.1}
.rs-tile .rs-lbl{font-size:11px;color:var(--faint);font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-top:2px}
.rs-tile.red   .rs-num{color:var(--red,#c0392b)}
.rs-tile.amber .rs-num{color:#c87800}
.rs-tile.green .rs-num{color:var(--green-d,#5b8e0d)}
#prod-loading{font-size:11px;color:var(--faint);font-style:italic;align-self:center}
.state-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:24px}
.state-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px 16px;
            cursor:pointer;transition:border-color .15s,box-shadow .15s;text-decoration:none;color:inherit;display:block}
.state-card:hover{border-color:var(--green);box-shadow:0 2px 8px rgba(0,0,0,.06)}
.state-card.active{border-color:var(--green);box-shadow:0 0 0 3px rgba(130,193,18,.18)}
.sc-code{font-size:28px;font-weight:900;letter-spacing:-.01em;line-height:1;margin-bottom:2px}
.sc-name{font-size:11px;color:var(--faint);font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px}
.sc-stats{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.sc-stat{font-size:12px;color:var(--muted)}
.sc-stat strong{color:var(--ink);font-weight:700}
.tier-badge{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;padding:2px 7px;border-radius:4px;margin-left:auto}
.tier1{background:#e8f5e9;color:#2e7d32}
.tier2{background:#fff3e0;color:#e65100}
.tier3{background:#fce4ec;color:#c62828}
.exp-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:3px;vertical-align:middle}
.exp-dot.red{background:#c0392b}
.exp-dot.amber{background:#c87800}
.detail-panel{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden}
.detail-header{padding:14px 18px 12px;border-bottom:1px solid var(--border);
               display:flex;align-items:baseline;gap:12px;flex-wrap:wrap}
.detail-title{font-size:18px;font-weight:800}
.detail-sub{font-size:12px;color:var(--faint)}
.mc-heading{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;
            color:var(--faint);padding:10px 18px 6px;border-top:1px solid var(--border);
            background:#fafbfa;display:flex;align-items:center;gap:10px}
.mc-heading span{flex:1}
.agent-table{width:100%;border-collapse:collapse;font-size:13px}
.agent-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;
                color:var(--faint);padding:8px 18px;text-align:left;white-space:nowrap}
.agent-table td{padding:8px 18px;border-top:1px solid var(--border);vertical-align:middle}
.agent-table tr:hover td{background:#f8faf5}
.exp-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;
           padding:2px 8px;border-radius:4px;white-space:nowrap}
.exp-badge.expired {background:#fdecea;color:#c0392b}
.exp-badge.expiring{background:#fff3e0;color:#c87800}
.exp-badge.ok      {background:#e8f5e9;color:#2e7d32}
.exp-badge.none    {background:#f0f0f0;color:#999;font-weight:400}
.prod-vol  {font-weight:700;color:#111;white-space:nowrap}
.prod-deals{font-size:11px;color:var(--muted);white-space:nowrap}
.prod-none {color:var(--faint);font-size:11px}

/* Remove button */
.btn-remove{background:none;border:none;color:var(--faint);font-size:14px;cursor:pointer;
            padding:2px 6px;border-radius:4px;line-height:1;opacity:.5;transition:opacity .15s}
.btn-remove:hover{opacity:1;color:var(--red,#c0392b);background:#fdecea}

/* Add-agent button */
.btn-add-agent{font-size:11px;font-weight:700;padding:4px 12px;background:var(--green);color:#111;
               border:0;border-radius:6px;cursor:pointer;white-space:nowrap;text-decoration:none}
.btn-add-agent:hover{background:var(--green-d,#5b8e0d);color:#fff}

/* Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;
               align-items:center;justify-content:center;z-index:999}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:12px;width:min(440px,95vw);padding:24px;
       max-height:90vh;overflow-y:auto;position:relative}
.modal h3{margin:0 0 4px;font-size:16px;font-weight:800}
.modal .modal-sub{font-size:12px;color:var(--faint);margin-bottom:18px}
.modal-close{position:absolute;top:14px;right:16px;background:none;border:none;
             font-size:20px;cursor:pointer;color:#888;line-height:1}
.mf-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
          color:var(--faint);display:block;margin-bottom:4px;margin-top:14px}
.mf-label:first-of-type{margin-top:0}
.mf-input{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:7px;
          font-size:13px;background:#fafafa;box-sizing:border-box}
.mf-input:focus{outline:2px solid var(--green);border-color:var(--green)}
.mf-btns{display:flex;gap:8px;margin-top:20px}
.mf-save{flex:1;padding:10px;background:var(--green);color:#111;border:0;border-radius:7px;
         font-weight:800;font-size:13px;cursor:pointer}
.mf-save:hover{background:var(--green-d,#5b8e0d);color:#fff}
.mf-cancel{padding:10px 16px;border:1px solid var(--border);background:#fff;color:#555;
           font-size:13px;border-radius:7px;cursor:pointer}
.mf-err{font-size:12px;color:var(--red,#c0392b);margin-top:8px;display:none}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('backoffice_roster', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="bo-eyebrow">Back Office</div>
      <div class="content-title">Agent Roster</div>
    </div>
    <div class="content-hello" style="display:flex;align-items:center;gap:12px">
      <?= $uniqueTotal ?> unique agents across <?= count($byState) ?> states
      <a href="backoffice_roster_changes.php" class="btn-add-agent" style="background:#f0f0f0;color:#555">
        Weekly Changes →
      </a>
    </div>
  </div>
  <div class="wrap">

    <!-- Summary bar -->
    <div class="roster-summary">
      <div class="rs-tile">
        <div class="rs-num"><?= $uniqueTotal ?></div>
        <div class="rs-lbl">Unique Agents</div>
      </div>
      <div class="rs-tile">
        <div class="rs-num"><?= count($byState) ?></div>
        <div class="rs-lbl">States</div>
      </div>
      <?php $totalExp = array_sum(array_column($stateMeta,'expired')); $totalWarn = array_sum(array_column($stateMeta,'expiring')); ?>
      <?php if ($totalExp): ?>
      <div class="rs-tile red">
        <div class="rs-num"><?= $totalExp ?></div>
        <div class="rs-lbl">Expired Licenses</div>
      </div>
      <?php endif; ?>
      <?php if ($totalWarn): ?>
      <div class="rs-tile amber">
        <div class="rs-num"><?= $totalWarn ?></div>
        <div class="rs-lbl">Expiring &lt;60 Days</div>
      </div>
      <?php endif; ?>
      <span id="prod-loading">Loading production…</span>
      <div class="rs-tile green" id="prod-vol-tile" style="display:none">
        <div class="rs-num" id="prod-vol-num">—</div>
        <div class="rs-lbl">LTM Volume</div>
      </div>
      <div class="rs-tile" id="prod-deals-tile" style="display:none">
        <div class="rs-num" id="prod-deals-num">—</div>
        <div class="rs-lbl">LTM Deals</div>
      </div>
    </div>

    <!-- State cards -->
    <div class="state-grid">
      <?php foreach ($byState as $st => $mcs): ?>
      <?php $m = $stateMeta[$st]; $tier = $stateTiers[$st] ?? 0; ?>
      <a class="state-card<?= $st===$activeState?' active':'' ?>" href="?state=<?= urlencode($st) ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between">
          <div class="sc-code"><?= htmlspecialchars($st) ?></div>
          <?php if ($tier): ?>
          <span class="tier-badge <?= $tierClass[$tier] ?>"><?= $tierLabel[$tier] ?></span>
          <?php endif; ?>
        </div>
        <div class="sc-name"><?= htmlspecialchars($stateNames[$st] ?? $st) ?></div>
        <div class="sc-stats">
          <span class="sc-stat"><strong><?= $m['total'] ?></strong> agents</span>
          <?php if ($m['mc_count']>1): ?><span class="sc-stat"><strong><?= $m['mc_count'] ?></strong> MCs</span><?php endif; ?>
          <?php if ($m['expired']): ?>
          <span class="sc-stat" style="color:#c0392b"><span class="exp-dot red"></span><?= $m['expired'] ?> expired</span>
          <?php endif; ?>
          <?php if ($m['expiring']): ?>
          <span class="sc-stat" style="color:#c87800"><span class="exp-dot amber"></span><?= $m['expiring'] ?> expiring</span>
          <?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Detail panel -->
    <?php if (isset($byState[$activeState])): ?>
    <?php $m = $stateMeta[$activeState]; $tier = $stateTiers[$activeState] ?? 0; ?>
    <div class="detail-panel">
      <div class="detail-header">
        <div class="detail-title"><?= htmlspecialchars($stateNames[$activeState] ?? $activeState) ?> (<?= htmlspecialchars($activeState) ?>)</div>
        <?php if ($tier): ?>
        <span class="tier-badge <?= $tierClass[$tier] ?>"><?= $tierLabel[$tier] ?> Commission Data</span>
        <?php endif; ?>
        <div style="margin-left:auto;display:flex;align-items:center;gap:10px">
          <div class="detail-sub">
            <?= $m['total'] ?> agents &nbsp;·&nbsp; <?= $m['mc_count'] ?> MC<?= $m['mc_count']!==1?'s':'' ?>
            <?php if ($m['expired']): ?>&nbsp;·&nbsp;<span style="color:#c0392b;font-weight:700"><?= $m['expired'] ?> expired</span><?php endif; ?>
            <?php if ($m['expiring']): ?>&nbsp;·&nbsp;<span style="color:#c87800;font-weight:700"><?= $m['expiring'] ?> expiring</span><?php endif; ?>
          </div>
          <button class="btn-add-agent" onclick="openAddModal()">+ Add Agent</button>
        </div>
      </div>

      <?php foreach ($byState[$activeState] as $mc => $agents): ?>
      <div>
        <div class="mc-heading">
          <span><?= htmlspecialchars($mc) ?> — <?= count($agents) ?> agent<?= count($agents)!==1?'s':'' ?></span>
        </div>
        <table class="agent-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Volume</th>
              <th>Deals</th>
              <?php if ($activeState==='SC'): ?><th>License Expires</th><?php endif; ?>
              <th style="width:40px"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($agents as $a): ?>
            <tr data-agent="<?= htmlspecialchars($a['agent_name']) ?>" data-roster-id="<?= $a['id'] ?>">
              <td><?= htmlspecialchars($a['agent_name']) ?></td>
              <td class="prod-cell-vol"><span class="prod-none">—</span></td>
              <td class="prod-cell-deals"><span class="prod-none">—</span></td>
              <?php if ($activeState==='SC'): ?>
              <td>
                <?php
                $exp = $a['license_exp'];
                if (!$exp)           echo '<span class="exp-badge none">—</span>';
                elseif ($exp<=$today) echo '<span class="exp-badge expired">Expired ' . htmlspecialchars($exp) . '</span>';
                elseif ($exp<=$warn60) echo '<span class="exp-badge expiring">Expiring ' . htmlspecialchars($exp) . '</span>';
                else                  echo '<span class="exp-badge ok">' . htmlspecialchars($exp) . '</span>';
                ?>
              </td>
              <?php endif; ?>
              <td>
                <button class="btn-remove" title="Remove from roster"
                        onclick="removeAgent(<?= $a['id'] ?>, <?= json_encode($a['agent_name']) ?>)">✕</button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div><!-- /wrap -->
</div><!-- /content -->
</div><!-- /layout -->

<!-- Add Agent Modal -->
<div class="modal-overlay" id="addModalOverlay">
  <div class="modal">
    <button class="modal-close" onclick="closeAddModal()">×</button>
    <h3>Add Agent to Roster</h3>
    <div class="modal-sub">Agent will appear in the <?= htmlspecialchars($stateNames[$activeState] ?? $activeState) ?> roster and be logged for onboarding.</div>
    <label class="mf-label">Agent Name</label>
    <input id="mf-name" class="mf-input" type="text" placeholder="Full name" maxlength="120" autocomplete="off">
    <label class="mf-label">State</label>
    <select id="mf-state" class="mf-input">
      <?php foreach ($stateNames as $code => $name): ?>
      <option value="<?= $code ?>"<?= $code===$activeState?' selected':'' ?>><?= htmlspecialchars($name) ?> (<?= $code ?>)</option>
      <?php endforeach; ?>
    </select>
    <label class="mf-label">Market Center</label>
    <input id="mf-mc" class="mf-input" type="text" placeholder="e.g. Myrtle Beach" list="mc-datalist" maxlength="80" autocomplete="off">
    <datalist id="mc-datalist">
      <?php foreach ($mcList as $mc): ?>
      <option value="<?= htmlspecialchars($mc) ?>">
      <?php endforeach; ?>
    </datalist>
    <label class="mf-label">License Expiry <span style="font-weight:400;text-transform:none;letter-spacing:0">(optional)</span></label>
    <input id="mf-exp" class="mf-input" type="date">
    <div class="mf-err" id="mf-err"></div>
    <div class="mf-btns">
      <button class="mf-save" id="mf-save" onclick="saveNewAgent()">Add to Roster</button>
      <button class="mf-cancel" onclick="closeAddModal()">Cancel</button>
    </div>
  </div>
</div>

<script>
const ACTIVE_STATE = <?= json_encode($activeState) ?>;
const STATE_URL    = 'backoffice_roster.php?state=' + encodeURIComponent(ACTIVE_STATE);

// ── Add agent modal ──────────────────────────────────────────────────────────
function openAddModal() {
    document.getElementById('addModalOverlay').classList.add('open');
    document.getElementById('mf-name').focus();
}
function closeAddModal() {
    document.getElementById('addModalOverlay').classList.remove('open');
    document.getElementById('mf-err').style.display = 'none';
}
document.getElementById('addModalOverlay').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeAddModal();
});

// Update MC datalist when state changes
document.getElementById('mf-state').addEventListener('change', function() {
    fetch('api/roster_agent.php', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'mcs_for_state', state_code: this.value})
    }).then(r=>r.json()).then(d=>{
        const dl = document.getElementById('mc-datalist');
        dl.innerHTML = (d.mcs||[]).map(m=>`<option value="${esc(m)}">`).join('');
    });
});

function saveNewAgent() {
    const name  = document.getElementById('mf-name').value.trim();
    const state = document.getElementById('mf-state').value;
    const mc    = document.getElementById('mf-mc').value.trim();
    const exp   = document.getElementById('mf-exp').value;
    const err   = document.getElementById('mf-err');
    err.style.display = 'none';
    if (!name) { err.textContent = 'Agent name is required.'; err.style.display = ''; return; }

    const btn = document.getElementById('mf-save');
    btn.disabled = true; btn.textContent = 'Saving…';

    fetch('api/roster_agent.php', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'add', agent_name:name, state_code:state, market_center:mc, license_exp:exp})
    })
    .then(r=>r.json())
    .then(d=>{
        btn.disabled = false; btn.textContent = 'Add to Roster';
        if (!d.ok) { err.textContent = d.error || 'Error saving.'; err.style.display = ''; return; }
        closeAddModal();
        // Reload the page to show the new agent in the correct state/MC
        window.location.href = 'backoffice_roster.php?state=' + encodeURIComponent(state);
    })
    .catch(()=>{ btn.disabled=false; btn.textContent='Add to Roster'; err.textContent='Network error.'; err.style.display=''; });
}

// ── Remove agent ─────────────────────────────────────────────────────────────
function removeAgent(id, name) {
    if (!confirm('Remove ' + name + ' from the roster?\n\nThis is logged and can be reviewed in Weekly Changes. The agent can be restored from the changes report.')) return;
    fetch('api/roster_agent.php', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'remove', id})
    })
    .then(r=>r.json())
    .then(d=>{
        if (d.ok) {
            const row = document.querySelector('tr[data-roster-id="'+id+'"]');
            if (row) {
                row.style.transition='opacity .3s';
                row.style.opacity='0';
                setTimeout(()=>row.remove(), 320);
            }
        }
    });
}

// ── Production enrichment ────────────────────────────────────────────────────
function normName(n) {
    return (n||'').toLowerCase().replace(/[^a-z ]/g,' ').replace(/\s+/g,' ').trim();
}
function lookupProd(name, map) {
    const n = normName(name);
    if (map[n]) return map[n];
    const parts = n.split(' ').filter(p=>p.length>1);
    if (parts.length>1 && map[parts.join(' ')]) return map[parts.join(' ')];
    const words = n.split(' ').filter(p=>p.length>0);
    if (words.length>2 && map[words[0]+' '+words[words.length-1]]) return map[words[0]+' '+words[words.length-1]];
    return null;
}
function fmtVol(v) {
    if (!v||v<1000) return '—';
    if (v>=1e9) return '$'+(v/1e9).toFixed(1)+'B';
    if (v>=1e6) return '$'+(v/1e6).toFixed(1)+'M';
    if (v>=1e3) return '$'+(v/1e3).toFixed(0)+'K';
    return '$'+Math.round(v).toLocaleString();
}
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

fetch('api/backoffice_production.php',{credentials:'same-origin'})
    .then(r=>r.json())
    .then(d=>{
        const loading=document.getElementById('prod-loading');
        if (loading) loading.style.display='none';
        if (!d.ok) return;
        if (d.total_volume>0){ document.getElementById('prod-vol-num').textContent=fmtVol(d.total_volume); document.getElementById('prod-vol-tile').style.display=''; }
        if (d.total_deals>0) { document.getElementById('prod-deals-num').textContent=d.total_deals.toLocaleString(); document.getElementById('prod-deals-tile').style.display=''; }
        const map=d.agents||{};
        document.querySelectorAll('tr[data-agent]').forEach(row=>{
            const prod=lookupProd(row.dataset.agent, map);
            const vt=row.querySelector('.prod-cell-vol'), dt=row.querySelector('.prod-cell-deals');
            if (!vt||!dt) return;
            if (prod&&(prod.volume>0||prod.deals>0)){
                vt.innerHTML='<span class="prod-vol">'+fmtVol(prod.volume)+'</span>';
                dt.innerHTML='<span class="prod-deals">'+(prod.deals||0)+'</span>';
            }
        });
    })
    .catch(()=>{ const l=document.getElementById('prod-loading'); if(l)l.style.display='none'; });
</script>
</body>
</html>
