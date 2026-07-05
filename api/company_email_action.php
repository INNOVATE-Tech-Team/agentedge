<?php
ob_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../lib/notifications.php';
ini_set('display_errors', '0');
ob_clean();
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
if (!can_send_company_email()) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
$db     = local_db();
$me     = strtolower(trim($agent['email'] ?? ''));

// Pull the full agent roster from the CRM — this is the authoritative "every
// agent" list (agent_roles/notification_prefs only cover agents who've logged
// into AgentEdge or been explicitly assigned a role, which is a much smaller set).
function ce_fetch_crm_roster(): array {
    $c     = cfg();
    $base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
    $token = $c['crm_token'] ?? '';
    $url   = $base . '/public/retention-roster' . ($token ? '?token=' . urlencode($token) : '');
    $ctx   = stream_context_create(['http' => ['timeout' => 15, 'header' => "Accept: application/json\r\n"]]);
    $raw   = @file_get_contents($url, false, $ctx);
    return ($raw !== false) ? (json_decode($raw, true) ?? []) : [];
}

// Recipients for a given audience. 'admin' stays on the local agent_roles table
// (small, curated). 'all' / 'mc' pull from the CRM roster so reach is complete,
// then honor any local email opt-out from notification_prefs.
function ce_resolve_recipients(string $audience, string $mcSlug): array {
    $db = local_db();

    if ($audience === 'admin') {
        $rows = resolve_notification_recipients('admin', '', '');
        return array_column(array_filter($rows, fn($r) => (int)$r['notify_email'] === 1), 'email');
    }

    $roster = ce_fetch_crm_roster();
    $emails = [];
    foreach ($roster as $a) {
        $email = strtolower(trim($a['email'] ?? ''));
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

        if ($audience === 'mc') {
            $mc = $a['marketCenter'] ?? '';
            if ($mc === '' && !empty($a['marketCenters'])) $mc = $a['marketCenters'][0]['name'] ?? '';
            if (!$mc || slugify_mc($mc) !== $mcSlug) continue;
        }
        $emails[$email] = true;
    }

    // Honor local opt-outs.
    $optOut = $db->query("SELECT email FROM notification_prefs WHERE notify_email=0")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($optOut as $o) unset($emails[strtolower(trim($o))]);

    return array_keys($emails);
}

if ($action === 'send') {
    $audience = trim($body['audience']        ?? '');
    $mcSlug   = trim($body['target_mc_slug']  ?? '');
    $subject  = trim($body['subject']          ?? '');
    $text     = trim($body['body']             ?? '');

    if (!$subject || !$text) { echo json_encode(['ok'=>false,'error'=>'Subject and message are required']); exit; }
    if (!in_array($audience, ['all','admin','mc'], true)) { echo json_encode(['ok'=>false,'error'=>'Invalid audience']); exit; }

    if ($audience === 'mc') {
        if (!$mcSlug) { echo json_encode(['ok'=>false,'error'=>'Market Center required']); exit; }
        if (!is_admin() && !in_array($mcSlug, my_mc_slugs(), true)) {
            echo json_encode(['ok'=>false,'error'=>'You can only email a Market Center you lead']); exit;
        }
    } elseif (!is_admin()) {
        echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit;
    }

    $recipients = ce_resolve_recipients($audience, $mcSlug);
    $fullBody   = $text . "\n\n— " . ($agent['name'] ?? $me) . "\nINNOVATE Real Estate";

    $ins = $db->prepare("INSERT INTO notification_queue (recipient, channel, subject, body, phone) VALUES (?, 'email', ?, ?, '')");
    foreach ($recipients as $email) {
        $ins->execute([$email, $subject, $fullBody]);
    }

    $db->prepare(
        "INSERT INTO company_emails (sender_email, sender_role, audience, target_mc_slug, subject, body, recipient_count)
         VALUES (?,?,?,?,?,?,?)"
    )->execute([$me, my_role(), $audience, $mcSlug, $subject, $text, count($recipients)]);

    echo json_encode(['ok'=>true, 'recipients'=>count($recipients)]);
    dispatch_notification_queue();
    exit;
}

if ($action === 'history') {
    if (is_admin()) {
        $rows = $db->query(
            "SELECT sender_email, audience, target_mc_slug, subject, recipient_count, sent_at
             FROM company_emails ORDER BY sent_at DESC LIMIT 50"
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $s = $db->prepare(
            "SELECT sender_email, audience, target_mc_slug, subject, recipient_count, sent_at
             FROM company_emails WHERE sender_email=? ORDER BY sent_at DESC LIMIT 50"
        );
        $s->execute([$me]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['ok'=>true, 'rows'=>$rows]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
