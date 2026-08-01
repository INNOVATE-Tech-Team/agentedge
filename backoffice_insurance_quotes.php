<?php
// Loops where DotLoop's native "Insurance Quote Request" Detail field is Yes —
// see lib/dotloop.php's dotloop_extract_insurance_quote()/dotloop_sync_company_loops().
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/dotloop.php';

$agent = require_login();
if (!is_admin()) { http_response_code(403); exit('Forbidden'); }

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

$db = local_db();
$rows = $db->query(
    "SELECT loop_id, name, deal_stage, dl_updated, loop_url, insurance_quote_notified_at
     FROM dotloop_loops
     WHERE insurance_quote_requested = 'Yes'
     ORDER BY dl_updated DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$participantsByLoop = [];
if ($rows) {
    $placeholders = implode(',', array_fill(0, count($rows), '?'));
    $loopIds = array_column($rows, 'loop_id');
    $stmt = $db->prepare(
        "SELECT loop_id, name, email FROM dotloop_loop_participants WHERE loop_id IN ({$placeholders}) AND role LIKE '%buyer%'"
    );
    $stmt->execute($loopIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $participantsByLoop[$p['loop_id']][] = $p;
    }
}

$stageLabels = [
    'ACTIVE_LISTING' => 'Active',
    'UNDER_CONTRACT' => 'Pending',
    'SOLD'           => 'Closed',
    'WITHDRAWN'      => 'Cancelled',
];

function fmt_date(mixed $val): string {
    if (!$val) return '—';
    $ts = strtotime((string)$val);
    return $ts ? date('M j, Y', $ts) : h((string)$val);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Insurance Quote Requests — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .iq-hero{background:linear-gradient(135deg,#1a1a1a 0%,#2d3a1e 100%);border-radius:12px;padding:24px 28px;color:white;margin-bottom:20px}
    .iq-hero-title{font-size:20px;font-weight:900;margin:0 0 4px}
    .iq-hero-sub{font-size:12px;color:rgba(255,255,255,.65);margin:0}
    .iq-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #e5e5e5;border-radius:12px;overflow:hidden}
    .iq-table th{text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#999;padding:10px 14px;border-bottom:1px solid #eee;background:#fafafa}
    .iq-table td{padding:12px 14px;border-bottom:1px solid #f5f5f5;vertical-align:top}
    .iq-table tr:last-child td{border-bottom:none}
    .iq-stage{display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:800;text-transform:uppercase;background:#f0f7e6;color:#3a6b1a}
    .iq-buyer{display:block;font-size:12px}
    .iq-buyer-email{color:#888;font-size:11px}
    .iq-notified{color:#3a6b1a;font-weight:700;font-size:11px}
    .iq-pending{color:#c0392b;font-weight:700;font-size:11px}
    .iq-empty{color:#aaa;font-size:13px;padding:40px 0;text-align:center}
    .iq-link{color:#82C112;font-weight:700;font-size:12px;text-decoration:none}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_insurance_quotes', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Insurance Quote Requests</div>
    </header>
    <main class="wrap">

      <div class="iq-hero">
        <div class="iq-hero-title">Carolina Property Insurance Leads</div>
        <p class="iq-hero-sub">Every transaction where the buyer's side answered "Yes" to DotLoop's Insurance Quote Request field. A new "Yes" auto-emails <?= h(dotloop_insurance_notify_email()) ?> — this list is the full running record.</p>
      </div>

      <?php if (!$rows): ?>
      <div class="iq-empty">No transactions currently flagged for an insurance quote.</div>
      <?php else: ?>
      <table class="iq-table">
        <thead>
          <tr>
            <th>Transaction</th>
            <th>Stage</th>
            <th>Buyer(s)</th>
            <th>Updated</th>
            <th>Notified</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $buyers = $participantsByLoop[$r['loop_id']] ?? [];
          ?>
          <tr>
            <td><?= h($r['name'] ?: 'Unnamed Loop') ?></td>
            <td><span class="iq-stage"><?= h($stageLabels[$r['deal_stage']] ?? $r['deal_stage']) ?></span></td>
            <td>
              <?php if (!$buyers): ?>
                <span style="color:#aaa;">—</span>
              <?php else: foreach ($buyers as $b): ?>
                <span class="iq-buyer"><?= h($b['name'] ?: $b['email']) ?> <span class="iq-buyer-email">(<?= h($b['email']) ?>)</span></span>
              <?php endforeach; endif; ?>
            </td>
            <td><?= fmt_date($r['dl_updated']) ?></td>
            <td>
              <?php if ($r['insurance_quote_notified_at']): ?>
                <span class="iq-notified">Sent <?= fmt_date($r['insurance_quote_notified_at']) ?></span>
              <?php else: ?>
                <span class="iq-pending">Not yet</span>
              <?php endif; ?>
            </td>
            <td><?php if ($r['loop_url']): ?><a class="iq-link" href="<?= h($r['loop_url']) ?>" target="_blank" rel="noopener">View in DotLoop</a><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

    </main>
  </div>
</div>
</body>
</html>
