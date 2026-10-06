<?php
// Admin action: email an agent (or every agent with an incomplete profile) a
// link to fill in just their missing required fields.
// POST {action:'single', email}     → one agent
// POST {action:'bulk_incomplete'}   → every active agent currently missing something
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/agent_profile.php';
require_once __DIR__ . '/../lib/notifications.php';
header('Content-Type: application/json');

$admin = current_agent();
if (!$admin || !is_admin()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admins only']); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

function mint_completion_token(PDO $db, string $email, string $createdBy): string {
    $token = bin2hex(random_bytes(24));
    $db->prepare("INSERT INTO profile_completion_tokens (token, email, created_by) VALUES (?, ?, ?)")
       ->execute([$token, $email, $createdBy]);
    return $token;
}

function send_completion_email(string $email, string $name, string $token, array $cfg, array $missing = []): void {
    $link    = 'https://agents.innovateonline.com/complete_profile.php?token=' . urlencode($token);
    $first   = $name ? explode(' ', trim($name))[0] : 'there';
    $subject = 'A few things are missing from your AgentEdge profile';

    $fieldItems = implode('', array_map(
        fn($f) => '<li style="margin-bottom:5px">' . htmlspecialchars($f['label'], ENT_QUOTES) . '</li>',
        $missing
    ));
    $fieldListHtml = $fieldItems !== ''
        ? '<ul style="margin:0 0 22px;padding-left:20px;color:#333;font-size:14px;line-height:1.6">' . $fieldItems . '</ul>'
        : '';

    $htmlBody = <<<HTML
<div style="background:#f4f5f6;padding:32px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif">
  <div style="max-width:480px;margin:0 auto">
    <div style="background:#111111;border-radius:10px 10px 0 0;padding:18px 24px">
      <div style="color:#ffffff;font-size:16px;font-weight:700;letter-spacing:.02em">INNOVATE <span style="color:#82C112">AgentEdge</span></div>
    </div>
    <div style="background:#ffffff;border-radius:0 0 10px 10px;padding:28px 24px;box-shadow:0 2px 10px rgba(0,0,0,.06)">
      <p style="margin:0 0 16px;font-size:15px;color:#111111">Hi {$first},</p>
      <p style="margin:0 0 18px;font-size:14px;color:#333333;line-height:1.6">A few required fields are still missing from your agent profile. This link only asks for what's actually missing — everything else on file stays as-is.</p>
      {$fieldListHtml}
      <div style="text-align:center;margin:26px 0">
        <a href="{$link}" style="display:inline-block;background:#82C112;color:#111111;font-weight:700;font-size:14px;text-decoration:none;padding:12px 28px;border-radius:8px">Complete My Profile</a>
      </div>
      <p style="margin:0;font-size:12px;color:#999999;line-height:1.5">If the button doesn't work, copy this link into your browser:<br><a href="{$link}" style="color:#5b8e0d">{$link}</a></p>
    </div>
    <p style="text-align:center;color:#aaaaaa;font-size:11px;margin-top:16px">— INNOVATE Real Estate</p>
  </div>
</div>
HTML;

    send_email_sendgrid($email, $subject, $htmlBody, $cfg, true);
}

$db  = local_db();
$cfg = cfg();

if ($action === 'single') {
    $email = strtolower(trim($body['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid email']);
        exit;
    }
    $missing = get_missing_required_fields($email);
    if (!$missing) {
        echo json_encode(['ok' => false, 'error' => 'This agent\'s profile is already complete.']);
        exit;
    }
    $st = $db->prepare("SELECT full_name FROM agent_intake WHERE email = ?");
    $st->execute([$email]);
    $name = $st->fetchColumn() ?: '';

    $token = mint_completion_token($db, $email, $admin['email'] ?? '');
    send_completion_email($email, $name, $token, $cfg, $missing);

    echo json_encode(['ok' => true, 'sent' => 1]);
    exit;
}

if ($action === 'bulk_incomplete') {
    $rows = $db->query(
        "SELECT i.email, i.full_name
         FROM agent_intake i
         LEFT JOIN agent_admin aa ON LOWER(aa.email) = i.email
         WHERE COALESCE(aa.terminated_date, '') = ''"
    )->fetchAll(PDO::FETCH_ASSOC);

    $sent = 0;
    foreach ($rows as $r) {
        $email = strtolower(trim($r['email']));
        if ($email === '') continue;
        $missing = get_missing_required_fields($email);
        if (!$missing) continue;
        $token = mint_completion_token($db, $email, $admin['email'] ?? '');
        send_completion_email($email, $r['full_name'] ?? '', $token, $cfg, $missing);
        $sent++;
    }

    echo json_encode(['ok' => true, 'sent' => $sent]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
