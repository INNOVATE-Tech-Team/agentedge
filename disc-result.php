<?php
require __DIR__ . '/disc-db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$token = $_GET['token'] ?? '';
if (!$token) { header('Location: disc.php'); exit; }

$row = disc_by_token($token);
if (!$row) { header('Location: disc.php'); exit; }

$p    = DISC_PROFILES[$row['primary_style']];
$sec  = $row['secondary_style'] ? DISC_PROFILES[$row['secondary_style']] : null;
$tot  = $row['score_d'] + $row['score_i'] + $row['score_s'] + $row['score_c'];
$pct  = fn(int $n): int => $tot > 0 ? (int)round($n / $tot * 100) : 0;

$bars = [
    'D' => ['label'=>'Dominance',        'score'=>(int)$row['score_d'], 'color'=>'#C0392B'],
    'I' => ['label'=>'Influence',        'score'=>(int)$row['score_i'], 'color'=>'#E67E22'],
    'S' => ['label'=>'Steadiness',       'score'=>(int)$row['score_s'], 'color'=>'#5b8e0d'],
    'C' => ['label'=>'Conscientiousness','score'=>(int)$row['score_c'], 'color'=>'#2C9CC9'],
];

$blend_desc = null;
if ($sec && isset($p['combos'][$row['secondary_style']])) {
    $blend_desc = $p['combos'][$row['secondary_style']];
}

$style_names = ['D'=>'Dominance','I'=>'Influence','S'=>'Steadiness','C'=>'Conscientiousness'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Your DISC Profile — INNOVATE AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-brand">
    <span class="brand">INNOVATE</span> <span class="brand-edge">AgentEdge</span>
  </div>
  <div class="topbar-right">
    <a class="logout" href="disc.php">Retake</a>
  </div>
</header>

<div class="disc-wrap disc-result-wrap">

  <!-- ── HERO ──────────────────────────────────────── -->
  <div class="dr-hero" style="background:<?= $p['bg'] ?>;border-color:<?= $p['color'] ?>33">
    <div class="dr-badge" style="background:<?= $p['color'] ?>"><?= $row['primary_style'] ?></div>
    <div class="dr-hero-text">
      <div class="dr-style-name"><?= htmlspecialchars($p['name']) ?></div>
      <div class="dr-tagline"><?= htmlspecialchars($p['tagline']) ?></div>
      <?php if ($sec): ?>
        <div class="dr-blend-tag" style="border-color:<?= $sec['color'] ?>;color:<?= $sec['color'] ?>">
          <?= $row['primary_style'].$row['secondary_style'] ?> Blend &mdash; <?= htmlspecialchars($p['name']) ?> / <?= htmlspecialchars($sec['name']) ?>
        </div>
      <?php endif; ?>
      <div class="dr-who">Result for <?= htmlspecialchars($row['name']) ?></div>
    </div>
  </div>

  <!-- ── SCORE BARS ────────────────────────────────── -->
  <div class="card dr-section">
    <div class="dr-section-label">Your DISC Scores</div>
    <div class="disc-bars">
      <?php foreach ($bars as $key => $bar): ?>
        <div class="disc-bar-row">
          <div class="disc-bar-label" style="color:<?= $bar['color'] ?>"><?= $bar['label'] ?></div>
          <div class="disc-bar-track">
            <div class="disc-bar-fill" style="width:<?= $pct($bar['score']) ?>%;background:<?= $bar['color'] ?>"></div>
          </div>
          <div class="disc-bar-pct"><?= $pct($bar['score']) ?>%</div>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="dr-bar-note">Scores show how each dimension showed up relative to your total responses. Most people have one clear primary style — a higher secondary score means both are meaningfully present in how you work.</p>
  </div>

  <!-- ── YOUR PROFILE ──────────────────────────────── -->
  <div class="card dr-section">
    <div class="dr-section-label">Your Profile</div>
    <?php foreach ($p['narrative'] as $para): ?>
      <p class="dr-narrative"><?= htmlspecialchars($para) ?></p>
    <?php endforeach; ?>
  </div>

  <!-- ── BLEND (if secondary) ──────────────────────── -->
  <?php if ($blend_desc && $sec): ?>
  <div class="card dr-section dr-blend-card" style="border-left:4px solid <?= $sec['color'] ?>">
    <div class="dr-section-label">Your <?= $row['primary_style'].$row['secondary_style'] ?> Blend</div>
    <p class="dr-narrative"><?= htmlspecialchars($blend_desc) ?></p>
  </div>
  <?php endif; ?>

  <!-- ── AT YOUR BEST ──────────────────────────────── -->
  <div class="card dr-section">
    <div class="dr-two-col">
      <div>
        <div class="dr-section-label">Strengths</div>
        <div class="disc-pills dr-pills-gap">
          <?php foreach ($p['strengths'] as $s): ?>
            <span class="disc-pill" style="border-color:<?= $p['color'] ?>44;color:<?= $p['color'] ?>"><?= htmlspecialchars($s) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <div>
        <div class="dr-section-label">Motivated by</div>
        <div class="disc-pills dr-pills-gap">
          <?php foreach ($p['motivated_by'] as $m): ?>
            <span class="disc-pill"><?= htmlspecialchars($m) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ── IN REAL ESTATE ────────────────────────────── -->
  <div class="card dr-section">
    <div class="dr-section-label">In Real Estate</div>
    <ul class="dr-bullets">
      <?php foreach ($p['re_bullets'] as $b): ?>
        <li style="--bullet-color:<?= $p['color'] ?>"><?= htmlspecialchars($b) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <!-- ── COMMUNICATION BLUEPRINT ───────────────────── -->
  <div class="card dr-section">
    <div class="dr-section-label">Your Communication Blueprint</div>
    <p class="dr-narrative"><?= htmlspecialchars($p['comm_style']) ?></p>
  </div>

  <!-- ── WORKING WITH CLIENT STYLES ────────────────── -->
  <div class="card dr-section">
    <div class="dr-section-label">Working with Different Client Styles</div>
    <p class="dr-sub-note">Not every client communicates the way you do. Here's how to read and adapt to each DISC type you'll encounter.</p>
    <div class="dr-client-grid">
      <?php foreach ($p['client_tips'] as $style => $tip): ?>
        <?php $sc = $bars[$style]['color']; ?>
        <div class="dr-client-card" style="border-top:3px solid <?= $sc ?>">
          <div class="dr-client-header">
            <span class="disc-style-dot" style="background:<?= $sc ?>"><?= $style ?></span>
            <span class="dr-client-name" style="color:<?= $sc ?>"><?= DISC_PROFILES[$style]['name'] ?></span>
          </div>
          <p class="dr-client-tip"><?= htmlspecialchars($tip) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── GROWTH EDGE ────────────────────────────────── -->
  <div class="card dr-section">
    <div class="dr-section-label">Your Growth Edge</div>
    <div class="dr-growth-box" style="border-color:<?= $p['color'] ?>;background:<?= $p['bg'] ?>">
      <div class="dr-growth-icon" style="color:<?= $p['color'] ?>">&#9650;</div>
      <p class="dr-growth-text"><?= htmlspecialchars($p['growth_edge']) ?></p>
    </div>
  </div>

  <!-- ── ACTIONS ────────────────────────────────────── -->
  <div class="dr-actions">
    <button class="disc-btn" onclick="copyLink()">Copy Result Link</button>
    <a href="disc.php" class="disc-btn-ghost">Retake Assessment</a>
  </div>
  <div id="copy-msg" class="disc-copy-msg">Link copied to clipboard!</div>

</div>

<script>
function copyLink() {
  navigator.clipboard.writeText(window.location.href).then(function() {
    var msg = document.getElementById('copy-msg');
    msg.style.display = 'block';
    setTimeout(function(){ msg.style.display = 'none'; }, 2500);
  });
}
</script>
</body>
</html>
