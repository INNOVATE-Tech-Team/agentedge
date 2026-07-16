<?php
// Forgot-password request + confirm — the local replacement for Perfex's
// admin/authentication/forgot_password page. Also doubles as the "set your
// initial password" step for agents provisioned directly in AgentEdge with
// no Perfex account to fall back on. Writes to agent_passwords, the same
// table attempt_login() (auth.php) already checks first on every sign-in.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/notifications.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST required']); exit; }

$action = $_GET['action'] ?? '';
$in     = json_decode(file_get_contents('php://input'), true) ?: [];

if ($action === 'request') {
    $email = strtolower(trim($in['email'] ?? ''));
    // Always return the same response regardless of whether the email is
    // known, so this endpoint can't be used to enumerate valid accounts.
    $generic = ['ok' => true, 'message' => 'If that email has an AgentEdge account, a reset link is on its way.'];
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode($generic); exit; }

    $known = false;
    try {
        $rs = local_db()->prepare("SELECT 1 FROM agent_passwords WHERE email=?");
        $rs->execute([$email]);
        $known = (bool)$rs->fetchColumn();
    } catch (\Throwable $e) {}
    if (!$known) {
        try {
            $rs = local_db()->prepare("SELECT 1 FROM agent_roles WHERE email=?");
            $rs->execute([$email]);
            $known = (bool)$rs->fetchColumn();
        } catch (\Throwable $e) {}
    }
    if (!$known) {
        // db_one_safe: a dead/retired Perfex connection must be treated as
        // "email not known" (same generic response below), not a crash.
        $u = db_one_safe("SELECT staffid FROM tblstaff WHERE email=? LIMIT 1", [$email]);
        $known = (bool)$u;
    }

    if ($known) {
        $token = bin2hex(random_bytes(32));
        local_db()->prepare(
            "INSERT INTO password_reset_tokens (token, email, expires_at) VALUES (?, ?, datetime('now', '+1 hour'))"
        )->execute([$token, $email]);

        $base     = rtrim((string)(cfg()['app_base_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'agentedge.innovateonline.com'))), '/');
        $resetUrl = $base . '/reset_password.php?token=' . urlencode($token);
        $body = '<p>Click the link below to set a new AgentEdge password. This link expires in 1 hour and can only be used once.</p>'
              . '<p><a href="' . htmlspecialchars($resetUrl) . '">Set a new password</a></p>'
              . "<p>If you didn't request this, you can safely ignore this email.</p>";
        queue_email_to([$email], 'Reset your AgentEdge password', $body);
    }

    echo json_encode($generic);
    dispatch_notification_queue();
    exit;
}

if ($action === 'confirm') {
    $token   = trim($in['token'] ?? '');
    $new     = (string)($in['new_password'] ?? '');
    $confirm = (string)($in['confirm_password'] ?? '');

    if ($token === '') { echo json_encode(['ok' => false, 'error' => 'Missing token.']); exit; }
    if ($new === '' || $new !== $confirm) { echo json_encode(['ok' => false, 'error' => "Passwords don't match."]); exit; }
    if (strlen($new) < 8) { echo json_encode(['ok' => false, 'error' => 'Password must be at least 8 characters.']); exit; }

    $st = local_db()->prepare("SELECT * FROM password_reset_tokens WHERE token=?");
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row || $row['used_at'] || strtotime($row['expires_at']) < time()) {
        echo json_encode(['ok' => false, 'error' => 'This reset link is invalid or has expired. Request a new one.']);
        exit;
    }

    $email = $row['email'];
    local_db()->prepare(
        "INSERT INTO agent_passwords (email, password_hash, updated_at)
         VALUES (?, ?, datetime('now'))
         ON CONFLICT(email) DO UPDATE SET password_hash=excluded.password_hash, updated_at=excluded.updated_at"
    )->execute([$email, password_hash($new, PASSWORD_BCRYPT)]);

    local_db()->prepare("UPDATE password_reset_tokens SET used_at=datetime('now') WHERE token=?")->execute([$token]);

    // Resolve identity (name/photo/staffid) the exact same way a normal login
    // would — tblstaff first, then innovate_roster — rather than duplicating
    // that logic here. The password we just stored will match immediately.
    $agent = attempt_login($email, $new);
    if (!$agent) {
        // Should be unreachable (we just wrote the hash attempt_login checks
        // first), but never leave the agent stuck on a "success" response
        // with no session.
        echo json_encode(['ok' => false, 'error' => 'Password saved, but sign-in failed — please try signing in normally.']);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['agent'] = $agent;
    unset($_SESSION['perms']);
    log_login_event($agent['email'], $agent['name'] ?? '', 'password_reset');

    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
