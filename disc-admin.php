<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/disc-db.php';

$agent = require_login();

$results = disc_all();

$style_colors = ['D'=>'#C0392B','I'=>'#E67E22','S'=>'#5b8e0d','C'=>'#2C9CC9'];
$style_names  = ['D'=>'Driver','I'=>'Influencer','S'=>'Supporter','C'=>'Analyzer'];

$proto       = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$assess_url  = $proto . '://' . $_SERVER['HTTP_HOST'] . '/disc.php';

// Style breakdown counts
$breakdown = ['D'=>0,'I'=>0,'S'=>0,'C'=>0];
foreach ($results as $r) {
    if (isset($breakdown[$r['primary_style']])) $breakdown[$r['primary_style']]++;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DISC Results — AgentEdge Back Office</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-brand">
    <span class="brand">INNOVATE</span> <span class="brand-edge">AgentEdge</span>
  </div>
  <div class="topbar-right">
    <span class="who"><?= htmlspecialchars($agent['name']) ?></span>
    <a class="logout" href="index.php">Dashboard</a>
    <a class="logout" href="logout.php">Sign out</a>
  </div>
</header>

<div class="wrap">

  <!-- Header row -->
  <div class="disc-admin-header">
    <div>
      <h1 style="margin:0 0 4px;font-size:22px">DISC Assessments</h1>
      <div style="font-size:13px;color:var(--muted)"><?= count($results) ?> completed</div>
    </div>
    <div class="disc-admin-actions">
      <button class="disc-copy-btn" onclick="copyLink(this)">&#128279; Copy Assessment Link</button>
      <a href="disc.php" class="disc-copy-btn disc-copy-btn-ghost">Take Assessment</a>
    </div>
  </div>

  <div id="copy-msg" class="banner" style="display:none;margin-bottom:16px">
    Assessment link copied! Share it with agents or recruits: <strong><?= htmlspecialchars($assess_url) ?></strong>
  </div>

  <!-- Style breakdown tiles -->
  <?php if (!empty($results)): ?>
  <div class="disc-breakdown">
    <?php foreach ($breakdown as $k => $n): ?>
      <div class="disc-bd-tile" style="border-top:3px solid <?= $style_colors[$k] ?>">
        <div class="disc-bd-letter" style="color:<?= $style_colors[$k] ?>"><?= $k ?></div>
        <div class="disc-bd-count"><?= $n ?></div>
        <div class="disc-bd-label"><?= $style_names[$k] ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Results table -->
  <?php if (empty($results)): ?>
    <div class="card"><div class="network-empty">No assessments completed yet. Share the link to get started.</div></div>
  <?php else: ?>
    <div class="card" style="padding:0;overflow:hidden">
      <div style="overflow-x:auto">
        <table class="tx disc-admin-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Role</th>
              <th>Style</th>
              <th>Scores&nbsp;<span style="font-weight:400;opacity:.6">D&nbsp;/&nbsp;I&nbsp;/&nbsp;S&nbsp;/&nbsp;C</span></th>
              <th>Completed</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($results as $r): ?>
              <?php $sc = $style_colors[$r['primary_style']]; $sn = $style_names[$r['primary_style']]; ?>
              <tr>
                <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                <td class="disc-email-cell"><?= htmlspecialchars($r['email']) ?></td>
                <td class="disc-email-cell"><?= htmlspecialchars($r['phone'] ?? '') ?: '<span style="color:#ccc">—</span>' ?></td>
                <td class="disc-email-cell"><?= htmlspecialchars($r['role'] ?? '') ?: '<span style="color:#ccc">—</span>' ?></td>
                <td>
                  <span class="disc-style-dot" style="background:<?= $sc ?>"><?= $r['primary_style'] ?></span>
                  <span class="disc-style-name-sm"><?= $sn ?></span>
                  <?php if ($r['secondary_style']): ?>
                    <span class="disc-style-sec">/&nbsp;<?= $r['secondary_style'] ?></span>
                  <?php endif; ?>
                </td>
                <td class="disc-scores-cell">
                  <?= (int)$r['score_d'] ?>&nbsp;/&nbsp;<?= (int)$r['score_i'] ?>&nbsp;/&nbsp;<?= (int)$r['score_s'] ?>&nbsp;/&nbsp;<?= (int)$r['score_c'] ?>
                </td>
                <td class="disc-date-cell"><?= date('M j, Y', strtotime($r['completed_at'])) ?></td>
                <td>
                  <a href="disc-result.php?token=<?= urlencode($r['token']) ?>"
                     class="disc-view-link" target="_blank">View</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

</div>

<script>
var ASSESS_URL = <?= json_encode($assess_url) ?>;
function copyLink(btn) {
  navigator.clipboard.writeText(ASSESS_URL).then(function() {
    var msg = document.getElementById('copy-msg');
    msg.style.display = 'block';
    setTimeout(function(){ msg.style.display = 'none'; }, 4000);
  });
}
</script>
</body>
</html>
