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

$db            = local_db();
$msg           = '';
$err           = '';
$link          = '';
$status        = null;
$emailResults  = null;
$emailNewAddr  = '';

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

// Mints a 24h/single-use password-setup token and emails it, returning the
// link — shared by the "send a link" action below and the optional
// "also send a login link" step after a Change Login Email.
function mint_and_send_setup_link(PDO $db, string $email, string $fromEmail, string $fromName): string {
    $token = bin2hex(random_bytes(32));
    $db->prepare(
        "INSERT INTO password_reset_tokens (token, email, expires_at) VALUES (?, ?, datetime('now', '+24 hours'))"
    )->execute([$token, $email]);

    $base = rtrim((string)(cfg()['app_base_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'agentedge.innovateonline.com'))), '/');
    $link = $base . '/reset_password.php?token=' . urlencode($token);

    $body = '<p>An INNOVATE admin has set up (or reset) your AgentEdge login.</p>'
          . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Set your AgentEdge password</a></p>'
          . '<p>This link expires in 24 hours and can only be used once.</p>';
    queue_email_to([$email], 'Set your AgentEdge password', $body, $fromEmail, $fromName);
    process_notification_queue();

    return $link;
}

// An agent's identity is split across every table below, each independently
// keyed by email — there's no single place that renames it, which is exactly
// what caused a real incident (a roster edit changed one table, leaving the
// agent's actual login credential — and several others — stuck on the old
// email, locking them out entirely). This renames it everywhere at once.
//
// Scans every local table for an 'email' or 'agent_email' column rather than
// a hardcoded list, so a table added later is covered automatically. Tables
// with a unique/primary-key constraint on that column (agent_intake,
// agent_passwords, agent_extra, agent_roles, agent_admin, email_signatures)
// will fail the UPDATE if the new email already has an unrelated row there —
// caught per-table and reported as a conflict needing a manual look, rather
// than silently overwriting someone else's data or aborting the whole rename.
// Perfex's tblstaff is deliberately NOT touched — it's a separate production
// CRM system, not local to AgentEdge; if an agent still needs the legacy
// Perfex login fallback updated too, that's a manual, separate step.
function agent_email_rename(PDO $db, string $old, string $new): array {
    $results = [];
    $tables = $db->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $t) {
        $emailCol = null;
        foreach ($db->query('PRAGMA table_info("' . $t . '")')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (in_array($c['name'], ['email', 'agent_email'], true)) { $emailCol = $c['name']; break; }
        }
        if (!$emailCol) continue;

        $chk = $db->prepare('SELECT count(*) FROM "' . $t . '" WHERE "' . $emailCol . '" = ?');
        $chk->execute([$old]);
        $count = (int)$chk->fetchColumn();
        if ($count === 0) continue; // nothing to rename in this table — don't clutter the report

        try {
            $db->prepare('UPDATE "' . $t . '" SET "' . $emailCol . '" = ? WHERE "' . $emailCol . '" = ?')
               ->execute([$new, $old]);
            $results[] = ['table' => $t, 'status' => 'renamed', 'count' => $count];
        } catch (\Throwable $e) {
            $results[] = ['table' => $t, 'status' => 'conflict', 'count' => $count, 'error' => $e->getMessage()];
        }
    }

    return $results;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_link') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Enter a valid email address.';
    } else {
        $status = agent_login_status($db, $email);
        $link   = mint_and_send_setup_link($db, $email, $agent['email'], $agent['name'] ?? '');
        $msg    = "Link generated and emailed to $email.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_email') {
    $oldEmail = strtolower(trim($_POST['old_email'] ?? ''));
    $newEmail = strtolower(trim($_POST['new_email'] ?? ''));
    if (!filter_var($oldEmail, FILTER_VALIDATE_EMAIL) || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $err = 'Enter two valid email addresses.';
    } elseif ($oldEmail === $newEmail) {
        $err = 'Old and new email must be different.';
    } else {
        $emailResults = agent_email_rename($db, $oldEmail, $newEmail);
        $emailNewAddr = $newEmail;
        if (!$emailResults) {
            $err = "No records found for $oldEmail — nothing to rename.";
        } else {
            $msg = "Renamed $oldEmail to $newEmail.";
            if (!empty($_POST['send_link'])) {
                $link = mint_and_send_setup_link($db, $newEmail, $agent['email'], $agent['name'] ?? '');
                $msg .= " A password-setup link was emailed to $newEmail.";
            }
        }
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
        <h3>Change an Agent's Login Email</h3>
        <p class="vd-sub">An agent's identity is split across several tables (profile, roster, password, notification prefs, etc.), each keyed independently by email. Editing it in just one place (e.g. the roster) leaves the others on the old email — this renames it everywhere at once.</p>
        <form method="post">
          <input type="hidden" name="action" value="change_email">
          <div class="form-row">
            <div><label class="fl" style="font-size:11px;font-weight:700;display:block;margin-bottom:3px">Current (old) email</label>
              <input name="old_email" type="email" required placeholder="old@example.com" style="width:220px"></div>
            <div><label class="fl" style="font-size:11px;font-weight:700;display:block;margin-bottom:3px">New email</label>
              <input name="new_email" type="email" required placeholder="new@example.com" style="width:220px"></div>
            <button class="btn btn-green" type="submit">Rename Everywhere</button>
          </div>
          <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#555;margin-top:10px">
            <input type="checkbox" name="send_link" value="1">
            Also email a password-setup link to the new address (use this if they're locked out, not just moving addresses)
          </label>
        </form>

        <?php if ($emailResults !== null): ?>
          <table style="margin-top:16px">
            <thead><tr><th>Table</th><th>Result</th></tr></thead>
            <tbody>
            <?php foreach ($emailResults as $r): ?>
              <tr>
                <td><code><?= h($r['table']) ?></code></td>
                <td>
                  <?php if ($r['status'] === 'renamed'): ?>
                    <span class="status-ok">✓ Renamed <?= (int)$r['count'] ?> row<?= $r['count'] === 1 ? '' : 's' ?></span>
                  <?php else: ?>
                    <span class="status-warn">⚠ Skipped — <?= h($emailNewAddr) ?> already has a row here. Needs a manual look (possible duplicate profile).</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <p class="vd-sub" style="margin-top:8px">Perfex's legacy staff record (tblstaff) is a separate CRM system and isn't touched by this — only matters if this agent still relies on the old Perfex login fallback.</p>
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
              <td><?= h(fmt_dt_et($p['expires_at'])) ?></td>
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
