<?php
// Finance backoffice — read-only log of every commission check submission
// (any method), fed by commission_submit.php / api/commission_action.php.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/roles.php';
$agent = require_login();
if (!is_admin()) { header('Location: index.php'); exit; }

$db = local_db();

$methodLabels = [
    'ach_requested'  => 'ACH / Wire requested',
    'wire_requested' => 'Wire requested',
    'dropoff'        => 'Dropped off',
    'mail'           => 'Mailed',
    'upload'         => 'Scanned upload',
];

$totalCount  = (int)$db->query("SELECT COUNT(*) FROM commission_check_submissions")->fetchColumn();
$uploadCount = (int)$db->query("SELECT COUNT(*) FROM commission_check_submissions WHERE method='upload'")->fetchColumn();
$last7d      = (int)$db->query("SELECT COUNT(*) FROM commission_check_submissions WHERE submitted_at >= datetime('now','-7 days')")->fetchColumn();

$rows = $db->query("
    SELECT id, agent_email, agent_name, loop_id, loop_name, method, office_location,
           check_original, dotloop_ok, email_sent, notes, submitted_at
    FROM commission_check_submissions
    ORDER BY submitted_at DESC
    LIMIT 300
")->fetchAll(PDO::FETCH_ASSOC);

function fmt_dt(string $dt): string {
    $ts = strtotime($dt);
    return $ts ? date('M j, Y g:ia', $ts) : $dt;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Commission Checks — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .cc-tiles{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:22px}
    .cc-tile{background:#fff;border-radius:10px;border:1px solid #eee;padding:14px 18px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
    .cc-tile-val{font-size:28px;font-weight:800;color:#111;line-height:1}
    .cc-tile-sub{font-size:11px;color:#888;margin-top:3px}
    .cc-tile-label{font-size:12px;font-weight:700;color:#555;margin-bottom:6px}
    .cc-section{background:#fff;border-radius:10px;border:1px solid #eee;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:22px;overflow:hidden}
    .cc-section-head{padding:14px 18px;border-bottom:1px solid #f0f0f0;font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px}
    .cc-section-head span{font-size:11px;font-weight:600;color:#999;margin-left:auto}
    .cc-table{width:100%;border-collapse:collapse}
    .cc-table th{padding:9px 14px;text-align:left;font-size:11px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #f0f0f0;white-space:nowrap}
    .cc-table td{padding:9px 14px;font-size:13px;color:#333;border-bottom:1px solid #fafafa;vertical-align:middle}
    .cc-table tr:last-child td{border-bottom:0}
    .cc-table tr:hover td{background:#fafcf7}
    .cc-name{font-weight:600;color:#111}
    .cc-email{font-size:11px;color:#888}
    .cc-badge{display:inline-block;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700}
    .cc-b-ach{background:#e8f5e9;color:#2e7d32}
    .cc-b-wire{background:#e3f2fd;color:#1565c0}
    .cc-b-dropoff{background:#fdf0e3;color:#a8720f}
    .cc-b-mail{background:#f3e8ff;color:#7c3aed}
    .cc-b-upload{background:#fee2e2;color:#b91c1c}
    .cc-dl-ok{color:#2e7d32;font-size:11px;font-weight:700}
    .cc-dl-fail{color:#b91c1c;font-size:11px;font-weight:700}
    .cc-empty{padding:28px;text-align:center;color:#bbb;font-size:13px;font-style:italic}
    @media(max-width:800px){.cc-tiles{grid-template-columns:repeat(1,1fr)}}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_commission_checks', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Commission Checks</div>
    </header>
    <main class="wrap">

      <div class="cc-tiles">
        <div class="cc-tile">
          <div class="cc-tile-label">Total Submissions</div>
          <div class="cc-tile-val"><?= number_format($totalCount) ?></div>
          <div class="cc-tile-sub">all time</div>
        </div>
        <div class="cc-tile">
          <div class="cc-tile-label">Scanned Uploads</div>
          <div class="cc-tile-val"><?= number_format($uploadCount) ?></div>
          <div class="cc-tile-sub">pushed to DotLoop when possible</div>
        </div>
        <div class="cc-tile">
          <div class="cc-tile-label">Last 7 Days</div>
          <div class="cc-tile-val"><?= number_format($last7d) ?></div>
          <div class="cc-tile-sub">new submissions</div>
        </div>
      </div>

      <div class="cc-section">
        <div class="cc-section-head">
          Submissions
          <span>last 300</span>
        </div>
        <?php if ($rows): ?>
        <table class="cc-table">
          <thead>
            <tr>
              <th>Submitted</th>
              <th>Agent</th>
              <th>Transaction</th>
              <th>Method</th>
              <th>Detail</th>
              <th>DotLoop</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
            <?php
              $badgeClass = [
                  'ach_requested'  => 'cc-b-ach',
                  'wire_requested' => 'cc-b-wire',
                  'dropoff'        => 'cc-b-dropoff',
                  'mail'           => 'cc-b-mail',
                  'upload'         => 'cc-b-upload',
              ][$r['method']] ?? '';
              $detail = '—';
              if ($r['method'] === 'dropoff' && $r['office_location']) $detail = htmlspecialchars($r['office_location']);
              if ($r['method'] === 'upload' && $r['check_original']) $detail = htmlspecialchars($r['check_original']);
            ?>
            <tr>
              <td style="white-space:nowrap"><?= htmlspecialchars(fmt_dt($r['submitted_at'])) ?></td>
              <td>
                <div class="cc-name"><?= htmlspecialchars($r['agent_name'] ?: $r['agent_email']) ?></div>
                <div class="cc-email"><?= htmlspecialchars($r['agent_email']) ?></div>
              </td>
              <td><?= htmlspecialchars($r['loop_name']) ?></td>
              <td><span class="cc-badge <?= $badgeClass ?>"><?= htmlspecialchars($methodLabels[$r['method']] ?? $r['method']) ?></span></td>
              <td><?= $detail ?></td>
              <td>
                <?php if ($r['method'] === 'upload'): ?>
                  <?php if ($r['dotloop_ok']): ?>
                    <span class="cc-dl-ok">✓ Uploaded</span>
                  <?php else: ?>
                    <span class="cc-dl-fail" title="<?= htmlspecialchars($r['notes'] ?? '') ?>">✗ Failed</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span style="color:#ccc">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <div class="cc-empty">No commission check submissions yet.</div>
        <?php endif; ?>
      </div>

    </main>
  </div>
</div>
</body>
</html>
