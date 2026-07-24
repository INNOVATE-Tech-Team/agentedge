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

function send_completion_email(string $email, string $name, string $token, array $cfg): void {
    $link    = 'https://agentedge.innovateonline.com/complete_profile.php?token=' . urlencode($token);
    $first   = $name ? explode(' ', trim($name))[0] : 'there';
    $subject = 'A few things are missing from your AgentEdge profile';
    $textBody = "Hi {$first},\n\n"
              . "A few required fields are still missing from your agent profile. "
              . "This link only asks for what's actually missing — everything else on file stays as-is:\n\n"
              . "{$link}\n\n"
              . "Thanks,\n— INNOVATE AgentEdge";
    send_email_sendgrid($email, $subject, $textBody, $cfg);
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
    send_completion_email($email, $name, $token, $cfg);

    echo json_encode(['ok' => true, 'sent' => 1]);
    exit;
}

if ($action === 'bulk_incomplete') {
    $rows = $db->query(
        "SELECT i.email, i.full_name
         FROM agent_intake i
         LEFT JOIN agent_admin aa ON aa.email = i.email
         WHERE COALESCE(aa.terminated_date, '') = ''"
    )->fetchAll(PDO::FETCH_ASSOC);

    $sent = 0;
    foreach ($rows as $r) {
        $email = strtolower(trim($r['email']));
        if ($email === '' || !get_missing_required_fields($email)) continue;
        $token = mint_completion_token($db, $email, $admin['email'] ?? '');
        send_completion_email($email, $r['full_name'] ?? '', $token, $cfg);
        $sent++;
    }

    echo json_encode(['ok' => true, 'sent' => $sent]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
