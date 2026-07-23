<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/notifications.php';

$agent = require_login();
if (!is_admin()) { header('Location: index.php'); exit; }

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

$db     = local_db();
$msg    = '';
$err    = '';
$link   = '';
$status = null;

// Looks up whether an email has a working login anywhere in the current
// (post-Perfex) system — used both to show the admin a diagnosis and to
// decide whether to warn that this is a brand-new agent.
function agent_login_status(PDO $db, string $email): array {
    $pw = $db->prepare("SELECT 1 FROM agent_passwords WHERE email=?");
    $pw->execute([$email]);

    $role = $db->prepare("SELECT 1 FROM agent_roles WHERE email=?");
    $role->execute([$email]);

    $roster = $db->prepare("SELECT agent_name FROM innovate_roster WHERE LOWER(TRIM(email))=? AND active=1 LIMIT 1");
    $roster->execute([$email]);

    return [
        'has_password' => (bool)$pw->fetchColumn(),
        'has_role'     => (bool)$role->fetchColumn(),
        'roster_name'  => $roster->fetchColumn() ?: '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_link') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Enter a valid email address.';
    } else {
        $status = agent_login_status($db, $email);

        $token = bin2hex(random_bytes(32));
        $db->prepare(
            "INSERT INTO password_reset_tokens (token, email, expires_at) VALUES (?, ?, datetime('now', '+24 hours'))"
        )->execute([$token, $email]);

        $base = rtrim((string)(cfg()['app_base_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'agentedge.innovateonline.com'))), '/');
        $link = $base . '/reset_password.php?token=' . urlencode($token);

        $body = '<p>An INNOVATE admin has set up (or reset) your AgentEdge login.</p>'
              . '<p><a href="' . h($link) . '">Set your AgentEdge password</a></p>'
              . '<p>This link expires in 24 hours and can only be used once.</p>';
        queue_email_to([$email], 'Set your AgentEdge password', $body, $agent['email'], $agent['name'] ?? '');
        process_notification_queue();

        $msg = "Link generated and emailed to $email.";
    }
}

if (($_POST['action'] ?? '') === 'revoke' && !empty($_POST['token'])) {
    $db->prepare("UPDATE password_reset_tokens SET used_at=datetime('now') WHERE token=?")->execute([$_POST['token']]);
    $msg = 'Link revoked.';
}

$pending = $db->query(
    "SELECT token, email, expires_at FROM password_reset_tokens
     WHERE used_at IS NULL AND expires_at > datetime('now')
     ORDER BY expires_at DESC LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Agent Login Access — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .vd-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px}
    .vd-card h3{margin:0 0 4px;font-size:15px;font-weight:700}
    .vd-card .vd-sub{margin:0 0 14px;font-size:12px;color:#888}
    table{width:100%;border-collapse:collapse;font-size:13px}
    th{text-align:left;padding:6px 10px;border-bottom:2px solid #e5e7eb;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#888}
    td{padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:top}
    tr:hover td{background:#f9faf8}
    .form-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
    .form-row input{padding:6px 10px;border:1px solid #ccc;border-radius:5px;font-size:13px;font-family:inherit}
    .btn{padding:7px 14px;border-radius:5px;border:1px solid #ccc;background:#fff;cursor:pointer;font-size:13px}
    .btn-green{background:#82C112;border-color:#5b8e0d;color:#fff;font-weight:600}
    .btn-danger{border-color:#e74c3c;color:#e74c3c}
    .btn-danger:hover{background:#e74c3c;color:#fff}
    .msg{background:#f0fde8;border:1px solid #82C112;color:#3a6b00;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:13px}
    .msg-err{background:#fff0f0;border:1px solid #f5c6c6;color:#c00;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:13px}
    .status-line{font-size:12px;margin:4px 0}
    .status-ok{color:#3a6b0d}
    .status-warn{color:#a07221}
    .link-box{display:flex;gap:8px;align-items:center;margin-top:10px}
    .link-box input{flex:1;font-family:monospace;font-size:12px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_agent_login', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Agent Login Access</div>
      <div class="content-hello">Send any agent a link to set (or reset) their AgentEdge password directly — for agents who can't log in and whose "Forgot password?" isn't finding an account (e.g. not yet migrated off the old Perfex fallback).</div>
    </header>
    <main class="wrap">
      <?php if ($msg): ?><div class="msg"><?= h($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="msg-err"><?= h($err) ?></div><?php endif; ?>

      <div class="vd-card">
        <h3>Send a password-setup link</h3>
        <p class="vd-sub">Works for any email — existing agents resetting a forgotten password, or brand-new agents who don't have AgentEdge credentials yet.</p>
        <form method="post">
          <input type="hidden" name="action" value="send_link">
          <div class="form-row">
            <div><label class="fl" style="font-size:11px;font-weight:700;display:block;margin-bottom:3px">Agent email</label>
              <input name="email" type="email" required placeholder="agent@innovateonline.com" style="width:280px"></div>
            <button class="btn btn-green" type="submit">Generate &amp; Email Link</button>
          </div>
        </form>

        <?php if ($status !== null): ?>
          <div style="margin-top:14px">
            <div class="status-line <?= $status['has_password'] ? 'status-ok' : 'status-warn' ?>">
              <?= $status['has_password'] ? '✓ Already has an AgentEdge password on file (this link will reset it).' : '⚠ No AgentEdge password on file yet — this will be their first one.' ?>
            </div>
            <div class="status-line <?= $status['has_role'] ? 'status-ok' : 'status-warn' ?>">
              <?= $status['has_role'] ? '✓ Has a role assignment.' : '⚠ No role assignment in Role Assignments — defaults to plain agent.' ?>
            </div>
            <div class="status-line <?= $status['roster_name'] ? 'status-ok' : 'status-warn' ?>">
              <?= $status['roster_name'] ? '✓ Found on the roster as "' . h($status['roster_name']) . '".' : '⚠ Not found on the active roster — confirm this agent has actually onboarded.' ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($link): ?>
          <div class="link-box">
            <input type="text" readonly value="<?= h($link) ?>" onclick="this.select()">
            <button class="btn" type="button" onclick="navigator.clipboard.writeText('<?= h($link) ?>')">Copy</button>
          </div>
          <p class="vd-sub" style="margin-top:6px">Already emailed above — copy this if you'd rather hand it to them directly (Slack, text) in case email delivery is in question. Expires in 24 hours, single use.</p>
        <?php endif; ?>
      </div>

      <div class="vd-card">
        <h3>Pending links</h3>
        <p class="vd-sub">Unused, unexpired setup/reset links.</p>
        <?php if ($pending): ?>
        <table>
          <thead><tr><th>Email</th><th>Expires</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($pending as $p): ?>
            <tr>
              <td><?= h($p['email']) ?></td>
              <td><?= h($p['expires_at']) ?></td>
              <td>
                <form method="post" style="display:inline" onsubmit="return confirm('Revoke this link?')">
                  <input type="hidden" name="action" value="revoke">
                  <input type="hidden" name="token" value="<?= h($p['token']) ?>">
                  <button class="btn btn-danger" type="submit">Revoke</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?><p style="color:#aaa;font-size:13px">No pending links.</p><?php endif; ?>
      </div>
    </main>
  </div>
</div>
</body>
</html>
