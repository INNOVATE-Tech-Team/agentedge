<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();

$db  = local_db();
$mcs = $db->query("SELECT * FROM market_centers WHERE enabled=1 ORDER BY state_code, sort_ord, name")->fetchAll(PDO::FETCH_ASSOC);

// Resource links grouped by slug
$linkRows    = $db->query("SELECT * FROM mc_resource_links WHERE enabled=1 AND url != '#' ORDER BY mc_slug, sort_ord")->fetchAll(PDO::FETCH_ASSOC);
$linksBySlug = [];
foreach ($linkRows as $lr) $linksBySlug[$lr['mc_slug']][] = $lr;

// CRM roster — for agent counts + leader/BIC name resolution
$c     = cfg();
$base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
$token = $c['crm_token'] ?? '';
$rurl  = $base . '/public/retention-roster' . ($token ? '?token=' . urlencode($token) : '');
$ctx   = stream_context_create(['http' => ['timeout' => 12, 'header' => "Accept: application/json\r\n"]]);
$raw   = @file_get_contents($rurl, false, $ctx);
$roster = ($raw !== false) ? (json_decode($raw, true) ?? []) : [];

$nameByEmail  = [];
$agentsBySlug = [];
foreach ($roster as $a) {
    $email = strtolower($a['email'] ?? '');
    $name  = $a['fullName'] ?? ($email ?: '');
    if ($email) $nameByEmail[$email] = $name;
    $mc = $a['marketCenter'] ?? '';
    if ($mc === '' && !empty($a['marketCenters'])) $mc = $a['marketCenters'][0]['name'] ?? '';
    if (!$mc) continue;
    $slug = slugify_mc($mc);
    $agentsBySlug[$slug][] = [
        'name'  => $name,
        'email' => $a['email'] ?? '',
        'phone' => $a['phone'] ?? '',
    ];
}

// Group MCs by state
$byState = [];
foreach ($mcs as $mc) {
    $byState[$mc['state_code']][] = $mc;
}
ksort($byState);

$stateNames = [
    'AL'=>'Alabama','AK'=>'Alaska','AZ'=>'Arizona','AR'=>'Arkansas','CA'=>'California',
    'CO'=>'Colorado','CT'=>'Connecticut','DE'=>'Delaware','FL'=>'Florida','GA'=>'Georgia',
    'HI'=>'Hawaii','ID'=>'Idaho','IL'=>'Illinois','IN'=>'Indiana','IA'=>'Iowa',
    'KS'=>'Kansas','KY'=>'Kentucky','LA'=>'Louisiana','ME'=>'Maine','MD'=>'Maryland',
    'MA'=>'Massachusetts','MI'=>'Michigan','MN'=>'Minnesota','MS'=>'Mississippi','MO'=>'Missouri',
    'MT'=>'Montana','NE'=>'Nebraska','NV'=>'Nevada','NH'=>'New Hampshire','NJ'=>'New Jersey',
    'NM'=>'New Mexico','NY'=>'New York','NC'=>'North Carolina','ND'=>'North Dakota','OH'=>'Ohio',
    'OK'=>'Oklahoma','OR'=>'Oregon','PA'=>'Pennsylvania','RI'=>'Rhode Island','SC'=>'South Carolina',
    'SD'=>'South Dakota','TN'=>'Tennessee','TX'=>'Texas','UT'=>'Utah','VT'=>'Vermont',
    'VA'=>'Virginia','WA'=>'Washington','WV'=>'West Virginia','WI'=>'Wisconsin','WY'=>'Wyoming',
];

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
function resolve_name(array $nameByEmail, string $email): string {
    if (!$email) return '';
    return $nameByEmail[strtolower($email)] ?? $email;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Market Centers — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .mc-search-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:24px}
    .mc-search-bar .search{flex:1;min-width:180px;max-width:340px}
    .state-section{margin-bottom:36px}
    .state-header{display:flex;align-items:baseline;gap:10px;margin-bottom:14px;padding-bottom:8px;border-bottom:2px solid var(--border)}
    .state-header h2{margin:0;font-size:17px;font-weight:800;color:var(--text)}
    .state-meta{font-size:12px;color:var(--faint)}
    .mc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
    .mc-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:18px 20px;display:flex;flex-direction:column;gap:10px;transition:box-shadow .15s}
    .mc-card:hover{box-shadow:0 2px 12px rgba(0,0,0,.08)}
    .mc-card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:8px}
    .mc-name{font-size:15px;font-weight:800;color:var(--text);line-height:1.25}
    .mc-state-chip{flex-shrink:0;font-size:11px;font-weight:700;background:#eef5e8;color:#5b8e0d;padding:2px 8px;border-radius:12px;margin-top:1px}
    .mc-people{display:flex;flex-direction:column;gap:4px}
    .mc-person{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text)}
    .mc-role-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
    .dot-leader{background:#82C112}
    .dot-bic{background:#f0a030}
    .mc-role-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);min-width:42px}
    .mc-count{display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--faint)}
    .mc-count-num{font-size:14px;font-weight:800;color:var(--text)}
    .mc-links{display:flex;flex-wrap:wrap;gap:6px}
    .mc-link-btn{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:#f5f5f5;border:1px solid #e0e0e0;border-radius:5px;font-size:11px;font-weight:700;color:#444;text-decoration:none;transition:background .12s}
    .mc-link-btn:hover{background:#e8f0ff;color:#2255cc;border-color:#b3c8f5}
    .mc-link-btn svg{flex-shrink:0}
    .mc-footer{margin-top:auto;padding-top:6px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:8px}
    .btn-view-agents{padding:6px 14px;background:var(--green);color:#111;border:0;border-radius:5px;font-size:11px;font-weight:800;cursor:pointer;text-transform:uppercase;letter-spacing:.04em}
    .btn-view-agents:hover{background:var(--green-d,#5b8e0d);color:#fff}
    .btn-view-agents:disabled{background:#e0e0e0;color:#aaa;cursor:default}

    /* No MCs state */
    .mc-empty{text-align:center;padding:60px 20px;color:var(--faint);font-size:14px}

    /* Agent modal */
    #mc-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:1000}
    #mc-modal-overlay.open{display:flex}
    #mc-modal{background:#fff;border-radius:12px;width:min(540px,95vw);max-height:82vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.18)}
    #mc-modal-head{padding:18px 20px 14px;border-bottom:1px solid var(--border);flex-shrink:0;display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
    #mc-modal-title{font-size:16px;font-weight:800;margin:0}
    #mc-modal-sub{font-size:12px;color:var(--faint);margin-top:2px}
    .mc-modal-close{background:none;border:none;font-size:22px;cursor:pointer;color:#888;line-height:1;padding:0 2px;flex-shrink:0}
    #mc-modal-body{overflow-y:auto;flex:1}
    .mc-agent-row{display:flex;align-items:center;gap:12px;padding:10px 20px;border-top:1px solid var(--border)}
    .mc-agent-row:first-child{border-top:none}
    .mc-agent-avatar{width:34px;height:34px;border-radius:50%;background:#eef5e8;color:#5b8e0d;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;text-transform:uppercase}
    .mc-agent-info{flex:1;min-width:0}
    .mc-agent-name{font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .mc-agent-contact{font-size:11px;color:var(--faint);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .mc-modal-empty{padding:32px 20px;text-align:center;color:var(--faint);font-size:13px}
    .mc-modal-search{padding:10px 20px 0;flex-shrink:0}
    .mc-modal-search input{width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;box-sizing:border-box}
    .mc-modal-search input:focus{outline:2px solid var(--green);border-color:var(--green)}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('market_centers', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Market Centers</div>
      <div class="mc-search-bar">
        <input id="mc-search" class="search" type="search" placeholder="Search market center or state…"
               oninput="filterMCCards(this.value)">
      </div>
    </header>
    <main class="wrap">
      <?php if (!$mcs): ?>
        <div class="mc-empty">No market centers have been set up yet. A super admin can add them via <strong>Back Office → Market Centers</strong>.</div>
      <?php else: ?>

      <?php foreach ($byState as $stateCode => $stateMCs): ?>
        <?php
          $stateName = $stateNames[$stateCode] ?? $stateCode;
          $totalAgents = 0;
          foreach ($stateMCs as $mc) {
              $totalAgents += count($agentsBySlug[$mc['slug']] ?? []);
          }
        ?>
        <div class="state-section" data-state="<?= h($stateCode) ?>">
          <div class="state-header">
            <h2><?= h($stateName) ?></h2>
            <span class="state-meta">
              <?= count($stateMCs) ?> market center<?= count($stateMCs) !== 1 ? 's' : '' ?>
              <?php if ($totalAgents > 0): ?>
                &middot; <?= $totalAgents ?> agent<?= $totalAgents !== 1 ? 's' : '' ?>
              <?php endif; ?>
            </span>
          </div>
          <div class="mc-grid">
          <?php foreach ($stateMCs as $mc):
            $slug       = $mc['slug'];
            $leaderName = resolve_name($nameByEmail, $mc['mc_leader_email']);
            $bicName    = resolve_name($nameByEmail, $mc['bic_email']);
            $agents     = $agentsBySlug[$slug] ?? [];
            $agentCount = count($agents);
            $links      = $linksBySlug[$slug] ?? [];
          ?>
            <div class="mc-card" data-name="<?= h(strtolower($mc['name'])) ?>" data-state="<?= h(strtolower($stateCode)) ?>" data-statename="<?= h(strtolower($stateName)) ?>">
              <div class="mc-card-header">
                <div class="mc-name"><?= h($mc['name']) ?></div>
                <?php if ($stateCode): ?>
                <span class="mc-state-chip"><?= h($stateCode) ?></span>
                <?php endif; ?>
              </div>

              <?php if ($leaderName || $bicName): ?>
              <div class="mc-people">
                <?php if ($leaderName): ?>
                <div class="mc-person">
                  <span class="mc-role-dot dot-leader"></span>
                  <span class="mc-role-label">Leader</span>
                  <span><?= h($leaderName) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($bicName): ?>
                <div class="mc-person">
                  <span class="mc-role-dot dot-bic"></span>
                  <span class="mc-role-label">BIC</span>
                  <span><?= h($bicName) ?></span>
                </div>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <?php if ($links): ?>
              <div class="mc-links">
                <?php foreach ($links as $link): ?>
                <a class="mc-link-btn" href="<?= h($link['url']) ?>" target="_blank" rel="noopener">
                  <svg width="10" height="10" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M5 1H1v10h10V7M7 1h4v4M11 1L5 7"/>
                  </svg>
                  <?= h($link['label']) ?>
                </a>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>

              <div class="mc-footer">
                <span class="mc-count">
                  <span class="mc-count-num"><?= $agentCount ?></span>
                  agent<?= $agentCount !== 1 ? 's' : '' ?>
                </span>
                <button class="btn-view-agents"
                        <?= $agentCount === 0 ? 'disabled' : '' ?>
                        onclick='openMCModal(<?= json_encode($mc['name']) ?>, <?= json_encode($stateCode) ?>, <?= json_encode($agents) ?>)'>
                  View Agents
                </button>
              </div>
            </div>
          <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <?php endif; ?>
    </main>
  </div>
</div>

<!-- Agent modal -->
<div id="mc-modal-overlay" onclick="if(event.target===this)closeMCModal()">
  <div id="mc-modal">
    <div id="mc-modal-head">
      <div>
        <div id="mc-modal-title"></div>
        <div id="mc-modal-sub"></div>
      </div>
      <button class="mc-modal-close" onclick="closeMCModal()">×</button>
    </div>
    <div class="mc-modal-search">
      <input type="search" id="mc-agent-search" placeholder="Search agents…" oninput="filterModalAgents(this.value)">
    </div>
    <div id="mc-modal-body"></div>
  </div>
</div>

<script>
let _modalAgents = [];

function openMCModal(mcName, stateCode, agents) {
  _modalAgents = agents;
  document.getElementById('mc-modal-title').textContent = mcName;
  document.getElementById('mc-modal-sub').textContent   = stateCode + ' · ' + agents.length + ' agent' + (agents.length !== 1 ? 's' : '');
  document.getElementById('mc-agent-search').value      = '';
  renderModalAgents(agents);
  document.getElementById('mc-modal-overlay').classList.add('open');
}

function closeMCModal() {
  document.getElementById('mc-modal-overlay').classList.remove('open');
}

function renderModalAgents(agents) {
  const body = document.getElementById('mc-modal-body');
  if (!agents.length) {
    body.innerHTML = '<div class="mc-modal-empty">No agents found.</div>';
    return;
  }
  const sorted = [...agents].sort((a, b) => a.name.localeCompare(b.name));
  body.innerHTML = sorted.map(a => {
    const initials = a.name.split(' ').map(w => w[0] || '').slice(0,2).join('').toUpperCase() || '?';
    const contact  = [a.email, a.phone].filter(Boolean).join(' · ');
    return `<div class="mc-agent-row">
      <div class="mc-agent-avatar">${esc(initials)}</div>
      <div class="mc-agent-info">
        <div class="mc-agent-name">${esc(a.name || 'Agent')}</div>
        ${contact ? `<div class="mc-agent-contact">${esc(contact)}</div>` : ''}
      </div>
    </div>`;
  }).join('');
}

function filterModalAgents(q) {
  const lq = q.toLowerCase();
  renderModalAgents(lq ? _modalAgents.filter(a =>
    (a.name  || '').toLowerCase().includes(lq) ||
    (a.email || '').toLowerCase().includes(lq)
  ) : _modalAgents);
}

function filterMCCards(q) {
  const lq = q.toLowerCase().trim();
  document.querySelectorAll('.mc-card').forEach(card => {
    const hit = !lq ||
      card.dataset.name.includes(lq) ||
      card.dataset.state.includes(lq) ||
      card.dataset.statename.includes(lq);
    card.style.display = hit ? '' : 'none';
  });
  // Hide state section headers when all cards in a state are hidden
  document.querySelectorAll('.state-section').forEach(sec => {
    const visible = sec.querySelectorAll('.mc-card:not([style*="display: none"])').length;
    sec.style.display = visible ? '' : 'none';
  });
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMCModal(); });
</script>
</body>
</html>
