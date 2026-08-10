<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
if (!can_send_company_email()) { header('Location: index.php'); exit; }
require_once __DIR__ . '/local_db.php';

$optOuts = local_db()->query(
    "SELECT np.email, np.updated_at, r.agent_name, r.market_center
     FROM notification_prefs np
     LEFT JOIN innovate_roster r ON LOWER(r.email) = np.email AND r.active = 1
     WHERE np.notify_email = 0
     ORDER BY np.updated_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Email Opt-Outs — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px}
    .section-title{font-size:16px;font-weight:900;color:#111}
    .oo-table{width:100%;border-collapse:collapse}
    .oo-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;padding:8px 12px;text-align:left;border-bottom:2px solid #eee;white-space:nowrap}
    .oo-table td{padding:12px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle;font-size:13px}
    .oo-table tr:last-child td{border-bottom:none}
    .oo-table tr:hover td{background:#fafafa}
    .oo-empty{text-align:center;color:#bbb;padding:40px;font-size:13px}
  </style>
</head>
<body>
  <div class="layout">
    <?php render_sidebar('bo_opt_outs', $agent); ?>
    <div class="content">
      <header class="content-top">
        <div class="content-title">Email Opt-Outs</div>
      </header>
      <main class="wrap">
        <div class="card" style="padding:20px 24px">
          <div class="section-header">
            <div class="section-title">Agents Opted Out of Company Emails</div>
          </div>
          <?php if (!$optOuts): ?>
          <div class="oo-empty">No agents have opted out of company emails.</div>
          <?php else: ?>
          <table class="oo-table">
            <thead>
              <tr><th>Name</th><th>Email</th><th>Market Center</th><th>Opted Out</th></tr>
            </thead>
            <tbody>
              <?php foreach ($optOuts as $o): ?>
              <tr>
                <td><?= htmlspecialchars($o['agent_name'] ?: '—') ?></td>
                <td><?= htmlspecialchars($o['email']) ?></td>
                <td><?= htmlspecialchars($o['market_center'] ?: '—') ?></td>
                <td><?= htmlspecialchars($o['updated_at']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </main>
    </div>
  </div>
</body>
</html>
