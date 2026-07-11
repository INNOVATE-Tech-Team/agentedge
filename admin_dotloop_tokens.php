<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/dotloop.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$success = '';
$error   = '';

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) die('Invalid CSRF token.');

    $action = $_POST['action'] ?? '';

    if ($action === 'seed') {
        $email        = strtolower(trim($_POST['agent_email'] ?? ''));
        $accessToken  = trim($_POST['access_token']  ?? '');
        $refreshToken = trim($_POST['refresh_token'] ?? '');

        if (!$email || !$accessToken || !$refreshToken) {
            $error = 'Email, access token, and refresh token are all required.';
        } else {
            // Auto-fetch profile_id from DotLoop API
            $profileId = null;
            $ctx = stream_context_create(['http' => [
                'timeout' => 10,
                'header'  => "Authorization: Bearer {$accessToken}\r\nAccept: application/json\r\n",
                'ignore_errors' => true,
            ]]);
            $raw = @file_get_contents('https://api-gateway.dotloop.com/public/v2/profile', false, $ctx);
            if ($raw !== false) {
                $d = json_decode($raw, true);
                $profileId = $d['data'][0]['id'] ?? null;
            }

            if (!$profileId) {
                $error = 'Could not fetch DotLoop profile ID — check that the access token is valid and not expired.';
            } else {
                $expiresAt = time() + 43200; // 12 hours
                local_db()->prepare(
                    "INSERT OR REPLACE INTO dotloop_tokens
                         (agent_email, profile_id, access_token, refresh_token, expires_at)
                     VALUES (?, ?, ?, ?, ?)"
                )->execute([$email, (string)$profileId, $accessToken, $refreshToken, $expiresAt]);
                $success = "DotLoop connected for {$email} (Profile ID: {$profileId}).";
            }
        }
    }

    if ($action === 'disconnect') {
        $email = strtolower(trim($_POST['agent_email'] ?? ''));
        if ($email) {
            local_db()->prepare("DELETE FROM dotloop_tokens WHERE agent_email = ?")->execute([$email]);
            $success = "Disconnected DotLoop for {$email}.";
        }
    }
}

// ── Load connected agents ─────────────────────────────────────────────────────
$connected = local_db()->query(
    "SELECT agent_email, profile_id, expires_at FROM dotloop_tokens ORDER BY agent_email"
)->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DotLoop Token Manager — AgentEdge</title>
<link rel="stylesheet" href="assets/app.css">
<style>
.dt-section { background:#fff; border:1px solid #E6E7E8; border-radius:12px; padding:24px; margin-bottom:24px; }
.dt-section h2 { font-size:15px; font-weight:700; margin:0 0 16px; }
.dt-table { width:100%; border-collapse:collapse; font-size:13px; }
.dt-table th { text-align:left; padding:8px 12px; background:#f5f5f5; font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#888; border-bottom:1px solid #eee; }
.dt-table td { padding:10px 12px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.dt-table tr:last-child td { border-bottom:none; }
.badge-on  { display:inline-block; padding:2px 8px; background:#eef5e8; color:#3a6b1a; border-radius:10px; font-size:11px; font-weight:700; }
.badge-off { display:inline-block; padding:2px 8px; background:#f5f5f5; color:#888; border-radius:10px; font-size:11px; font-weight:700; }
.form-row { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
.form-field { display:flex; flex-direction:column; gap:4px; flex:1; min-width:200px; }
.form-field label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#888; }
.form-field input { padding:8px 10px; border:1px solid #ddd; border-radius:6px; font-size:13px; font-family:monospace; }
.btn-seed { padding:9px 20px; background:#82C112; color:white; border:none; border-radius:6px; font-weight:700; font-size:13px; cursor:pointer; }
.btn-disconnect { padding:5px 12px; background:white; color:#c0392b; border:1px solid #ffb3b3; border-radius:6px; font-size:12px; font-weight:700; cursor:pointer; }
.notice-ok  { padding:10px 16px; background:#eef5e8; border:1px solid #c3e09a; border-radius:8px; color:#3a6b1a; font-size:13px; margin-bottom:16px; }
.notice-err { padding:10px 16px; background:#fff3f3; border:1px solid #ffb3b3; border-radius:8px; color:#c0392b; font-size:13px; margin-bottom:16px; }
.hint { font-size:12px; color:#999; margin-top:6px; line-height:1.5; }
</style>
</head>
<body>
<div class="app-shell">
<?php render_sidebar('admin_dotloop_tokens', $agent); ?>
<main class="main-content">

<div class="page-header">
  <h1 class="page-title">DotLoop Token Manager</h1>
</div>

<?php if ($success): ?>
<div class="notice-ok"><?= h($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="notice-err"><?= h($error) ?></div>
<?php endif; ?>

<!-- ── Seed New Token ───────────────────────────────────────────────────────── -->
<div class="dt-section">
  <h2>Seed DotLoop Connection for an Agent</h2>
  <p class="hint" style="margin-bottom:16px;">
    Use Postman or Insomnia to authenticate as the agent using the
    <strong>Authorization Code</strong> flow (see the DotLoop Quick Start Guide).
    Auth URL: <code>https://auth.dotloop.com/oauth/authorize</code> &nbsp;|&nbsp;
    Token URL: <code>https://auth.dotloop.com/oauth/token</code><br>
    Paste the <strong>access_token</strong> and <strong>refresh_token</strong> from the response below.
    The profile ID is fetched automatically.
  </p>
  <form method="POST">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="seed">
    <div class="form-row">
      <div class="form-field">
        <label>Agent Email (AgentEdge login email)</label>
        <input type="email" name="agent_email" required placeholder="agent@innovateonline.com">
      </div>
    </div>
    <div class="form-row">
      <div class="form-field">
        <label>Access Token</label>
        <input type="text" name="access_token" required placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
      </div>
      <div class="form-field">
        <label>Refresh Token</label>
        <input type="text" name="refresh_token" required placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
      </div>
    </div>
    <button type="submit" class="btn-seed">Connect DotLoop</button>
  </form>
</div>

<!-- ── Connected Agents ────────────────────────────────────────────────────── -->
<div class="dt-section">
  <h2>Connected Agents (<?= count($connected) ?>)</h2>
  <?php if (empty($connected)): ?>
  <p style="color:#aaa;font-size:13px;">No agents connected yet.</p>
  <?php else: ?>
  <table class="dt-table">
    <thead>
      <tr>
        <th>Agent Email</th>
        <th>Profile ID</th>
        <th>Token Expires</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($connected as $row):
      $expired = (int)$row['expires_at'] < time();
      $expiryStr = date('M j, Y g:ia', (int)$row['expires_at']);
    ?>
    <tr>
      <td><?= h($row['agent_email']) ?></td>
      <td style="font-family:monospace;font-size:12px;"><?= h($row['profile_id']) ?></td>
      <td style="font-size:12px;color:<?= $expired ? '#c0392b' : '#555' ?>"><?= h($expiryStr) ?><?= $expired ? ' <strong>(expired — will auto-refresh)</strong>' : '' ?></td>
      <td><span class="badge-on">Connected</span></td>
      <td>
        <form method="POST" onsubmit="return confirm('Disconnect DotLoop for <?= h($row['agent_email']) ?>?')">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="disconnect">
          <input type="hidden" name="agent_email" value="<?= h($row['agent_email']) ?>">
          <button type="submit" class="btn-disconnect">Disconnect</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

</main>
</div>
</body>
</html>
