<?php
// DotLoop — connect / disconnect page.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/dotloop.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

$agent = require_login();
$email = $agent['email'];

$connected = dotloop_is_connected($email);
$tokens    = $connected ? dotloop_get_tokens($email) : null;

$errMsg = '';
if (isset($_GET['error'])) {
    $detail = !empty($_GET['detail']) ? ' (' . htmlspecialchars(urldecode($_GET['detail']), ENT_QUOTES) . ')' : '';
    $errMsg = match($_GET['error']) {
        'state_mismatch'   => 'OAuth state mismatch — please try connecting again.',
        'oauth_failed'     => 'Could not complete DotLoop authorization.' . $detail,
        'profile_failed'   => 'Connected but failed to fetch your DotLoop profile.' . $detail,
        default            => 'An error occurred. Please try again.' . $detail,
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DotLoop — AgentEdge</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app-shell">
<?php render_sidebar('dotloop', $agent); ?>
<main class="main-content">
  <div class="page-header">
    <h1 class="page-title">DotLoop Integration</h1>
  </div>

  <?php if ($errMsg): ?>
  <div class="notice notice-error" style="max-width:520px;margin-bottom:18px;padding:12px 16px;background:#fff3f3;border:1px solid #ffb3b3;border-radius:8px;color:#c0392b;font-size:13px;">
    <?= h($errMsg) ?>
  </div>
  <?php endif; ?>

  <?php if ($connected): ?>
  <div style="max-width:520px;">
    <div style="background:white;border:1px solid #E6E7E8;border-radius:12px;padding:28px;">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
        <span style="width:10px;height:10px;background:#82C112;border-radius:50%;display:inline-block;"></span>
        <span style="font-weight:700;font-size:15px;">DotLoop Connected</span>
      </div>
      <p style="font-size:13px;color:#666;margin:0 0 8px;">
        Your DotLoop account is linked. AgentEdge can read and update your transaction loops.
      </p>
      <?php if (!empty($tokens['profile_id'])): ?>
      <p style="font-size:12px;color:#aaa;margin:0 0 20px;">DotLoop Profile ID: <?= h($tokens['profile_id']) ?></p>
      <?php endif; ?>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a href="dotloop.php" style="padding:9px 18px;background:#82C112;color:white;border-radius:6px;font-weight:700;font-size:13px;text-decoration:none;">View Transactions</a>
        <button
          onclick="disconnectDotloop()"
          style="padding:9px 18px;background:white;color:#c0392b;border:1px solid #ffb3b3;border-radius:6px;font-weight:700;font-size:13px;cursor:pointer;">
          Disconnect DotLoop
        </button>
      </div>
    </div>
  </div>

  <script>
  function disconnectDotloop() {
    if (!confirm('Disconnect your DotLoop account? You can reconnect at any time.')) return;
    fetch('api/dotloop_action.php?action=disconnect', {method:'POST'})
      .then(r => r.json())
      .then(d => {
        if (d.ok) location.href = 'dotloop_connect.php';
        else alert('Error: ' + (d.error || 'Unknown error'));
      })
      .catch(() => alert('Request failed. Please try again.'));
  }
  </script>

  <?php else: ?>
  <div class="dl-connect-cta">
    <svg width="48" height="48" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin-bottom:16px;">
      <rect width="48" height="48" rx="10" fill="#f0f7e6"/>
      <path d="M24 14 L34 24 L24 34 L14 24 Z" stroke="#82C112" stroke-width="2.5" fill="none"/>
      <circle cx="24" cy="24" r="4" fill="#82C112"/>
    </svg>
    <h2>Connect Your DotLoop Account</h2>
    <p>Link DotLoop to AgentEdge to view your transaction loops, track commission details, closing dates, and access documents — all in one place.</p>
    <?php if (empty(cfg()['dotloop_client_id'])): ?>
      <p style="font-size:13px;color:#c0392b;background:#fff3f3;border:1px solid #ffb3b3;border-radius:8px;padding:12px;">
        DotLoop API credentials are not configured.<br>
        Add <code>dotloop_client_id</code> and <code>dotloop_client_secret</code> to <code>config.php</code>.
      </p>
    <?php else: ?>
      <a href="dotloop_oauth_start.php" style="display:inline-block;padding:12px 28px;background:#82C112;color:white;border-radius:8px;font-weight:700;font-size:14px;text-decoration:none;">
        Connect DotLoop Account
      </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</main>
</div>
</body>
</html>
