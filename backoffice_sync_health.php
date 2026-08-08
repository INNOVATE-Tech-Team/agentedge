<?php
// Cross-system email sync health: does each active agent's tblstaff email
// also appear in Darwin (darwin_cap_progress.agent_email) and DotLoop
// (dotloop_loop_participants.email)? All three need to agree for the
// AgentEdge <-> Darwin <-> DotLoop integrations to actually find an agent's
// data. Excludes known non-agent tblstaff rows (teams, vendors, placeholder
// test accounts) via sync_health_is_noise() below, since those were never
// going to match and aren't real gaps.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/dotloop.php';

$agent = require_login();
if (!is_admin()) { http_response_code(403); exit('Forbidden'); }

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

/**
 * True for tblstaff rows that are known not to be an individual agent (a
 * team display name, a vendor/partner contact, or a placeholder test
 * account) — these will never have a matching Darwin/DotLoop email, and
 * that's expected, not a sync gap worth chasing.
 */
function sync_health_is_noise(string $name, string $email): bool {
    $email = strtolower(trim($email));
    $name  = strtolower(trim($name));

    $noiseEmailSuffixes = ['@carolinapropertyinsurance.com', '@jeffcasterline.com', 'yopmail.com'];
    foreach ($noiseEmailSuffixes as $suffix) {
        if (str_ends_with($email, $suffix)) return true;
    }
    if (in_array($email, ['email@email.com', 'demo@demo.com'], true)) return true;
    if (str_starts_with($email, 'unkown') || str_starts_with($email, 'unknown') || str_starts_with($email, 'inactive')) return true;

    $teamNameMarkers = ['team', 'group', 'properties', 'llc', ' & ', ' and '];
    foreach ($teamNameMarkers as $marker) {
        if (str_contains($name, $marker)) return true;
    }
    return false;
}

$db = local_db();

$dotloopEmails = array_flip(array_map(
    fn($e) => strtolower(trim($e)),
    $db->query("SELECT DISTINCT email FROM dotloop_loop_participants")->fetchAll(PDO::FETCH_COLUMN)
));
$darwinEmails = array_flip(array_map(
    fn($e) => strtolower(trim($e)),
    $db->query("SELECT DISTINCT agent_email FROM darwin_cap_progress WHERE agent_email != ''")->fetchAll(PDO::FETCH_COLUMN)
));

$staff = db_query_safe("SELECT email, firstname, lastname FROM tblstaff WHERE active=1", []);

$buckets = ['darwin_only' => [], 'dotloop_only' => [], 'both' => [], 'neither' => []];
foreach ($staff as $s) {
    $email = trim($s['email']);
    $name  = trim($s['firstname'] . ' ' . $s['lastname']);
    if (sync_health_is_noise($name, $email)) continue;

    $e         = strtolower($email);
    $inDarwin  = isset($darwinEmails[$e]);
    $inDotloop = isset($dotloopEmails[$e]);
    $row       = ['name' => $name, 'email' => $email];

    if ($inDarwin && $inDotloop) $buckets['both'][] = $row;
    elseif ($inDarwin) $buckets['darwin_only'][] = $row;
    elseif ($inDotloop) $buckets['dotloop_only'][] = $row;
    else $buckets['neither'][] = $row;
}

$tab = $_GET['tab'] ?? 'darwin_only';
$tabs = [
    'darwin_only'  => 'Missing from DotLoop',
    'dotloop_only' => 'Missing from Darwin',
    'neither'      => 'In Neither',
    'both'         => 'Fully Synced',
];
if (!isset($tabs[$tab])) $tab = 'darwin_only';
$rows = $buckets[$tab];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Sync Health — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .sh-hero{background:linear-gradient(135deg,#1a1a1a 0%,#2d3a1e 100%);border-radius:12px;padding:24px 28px;color:white;margin-bottom:20px}
    .sh-hero-title{font-size:20px;font-weight:900;margin:0 0 4px}
    .sh-hero-sub{font-size:12px;color:rgba(255,255,255,.65);margin:0}
    .sh-tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
    .sh-tab{padding:8px 16px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;color:#555;background:#fff;border:1px solid #e0e0e0}
    .sh-tab:hover{border-color:#82C112}
    .sh-tab.active{background:#82C112;border-color:#82C112;color:#000}
    .sh-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #e5e5e5;border-radius:12px;overflow:hidden}
    .sh-table th{text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#999;padding:10px 14px;border-bottom:1px solid #eee;background:#fafafa}
    .sh-table td{padding:10px 14px;border-bottom:1px solid #f5f5f5}
    .sh-table tr:last-child td{border-bottom:none}
    .sh-empty{color:#aaa;font-size:13px;padding:40px 0;text-align:center}
    .sh-note{font-size:11px;color:#999;margin-top:10px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_sync_health', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Sync Health</div>
    </header>
    <main class="wrap">

      <div class="sh-hero">
        <div class="sh-hero-title">AgentEdge / Darwin / DotLoop Email Sync</div>
        <p class="sh-hero-sub">Every active agent's tblstaff email needs to match Darwin's agent_email and DotLoop's participant email for those integrations to find their data. Known non-agent tblstaff rows (teams, vendors, test accounts) are excluded.</p>
      </div>

      <div class="sh-tabs">
        <?php foreach ($tabs as $key => $label): ?>
        <a class="sh-tab<?= $tab === $key ? ' active' : '' ?>" href="?tab=<?= h($key) ?>">
          <?= h($label) ?> (<?= count($buckets[$key]) ?>)
        </a>
        <?php endforeach; ?>
      </div>

      <?php if (!$rows): ?>
      <div class="sh-empty">No agents in this category.</div>
      <?php else: ?>
      <table class="sh-table">
        <thead><tr><th>Agent</th><th>Email</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= h($r['name']) ?></td>
            <td><?= h($r['email']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <p class="sh-note">"In Neither" mostly reflects agents with no closed production and no synced DotLoop activity yet — expected for new or inactive agents, not necessarily a sync problem.</p>

    </main>
  </div>
</div>
</body>
</html>
