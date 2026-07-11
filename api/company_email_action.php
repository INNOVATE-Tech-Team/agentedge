<?php
ob_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../lib/notifications.php';
require_once __DIR__ . '/../lib/company_email.php';
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

if ($action === 'send' || $action === 'schedule') {
    $audience    = trim($body['audience']        ?? '');
    $mcSlug      = trim($body['target_mc_slug']  ?? '');
    $targetEmail = strtolower(trim($body['target_email'] ?? ''));
    $subject     = trim($body['subject']          ?? '');
    $html        = trim($body['body']             ?? '');
    $hasText     = trim(strip_tags($html)) !== '';
    $sendAt      = trim($body['send_at']           ?? '');

    if (!$subject || !$hasText) { echo json_encode(['ok'=>false,'error'=>'Subject and message are required']); exit; }

    $err = ce_validate_audience($audience, $mcSlug, $targetEmail);
    if ($err) { echo json_encode(['ok'=>false,'error'=>$err]); exit; }

    $recipients = ce_resolve_recipients($audience, $mcSlug, $targetEmail);

    if ($action === 'schedule') {
        $ts = $sendAt ? strtotime($sendAt) : false;
        if ($ts === false) { echo json_encode(['ok'=>false,'error'=>'Valid send date/time required']); exit; }
        if ($ts <= time()) { echo json_encode(['ok'=>false,'error'=>'Scheduled time must be in the future']); exit; }

        $db->prepare(
            "INSERT INTO scheduled_emails (sender_email, sender_role, audience, target_mc_slug, target_email, subject, body, send_at, recipient_count)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([$me, my_role(), $audience, $mcSlug, $targetEmail, $subject, $html, gmdate('Y-m-d H:i:s', $ts), count($recipients)]);

        echo json_encode(['ok'=>true, 'scheduled'=>true, 'recipients'=>count($recipients)]);
        exit;
    }

    $sigHtml = ce_signature_html($me, $agent['name'] ?? $me, $_SERVER['HTTP_HOST']);
    $ins = $db->prepare("INSERT INTO notification_queue (recipient, channel, subject, body, phone, is_html) VALUES (?, 'email', ?, ?, '', 1)");
    foreach ($recipients as $r) {
        $personalized = ce_apply_merge_vars($html, $r['name']);
        $ins->execute([$r['email'], $subject, $personalized . $sigHtml]);
    }

    $db->prepare(
        "INSERT INTO company_emails (sender_email, sender_role, audience, target_mc_slug, subject, body, recipient_count)
         VALUES (?,?,?,?,?,?,?)"
    )->execute([$me, my_role(), $audience, $mcSlug, $subject, $html, count($recipients)]);

    echo json_encode(['ok'=>true, 'recipients'=>count($recipients)]);
    dispatch_notification_queue();
    exit;
}

if ($action === 'cancel_scheduled') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
    $stmt = is_admin()
        ? $db->prepare("UPDATE scheduled_emails SET status='canceled' WHERE id=? AND status='pending'")
        : $db->prepare("UPDATE scheduled_emails SET status='canceled' WHERE id=? AND status='pending' AND sender_email=?");
    is_admin() ? $stmt->execute([$id]) : $stmt->execute([$id, $me]);
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'get_signature') {
    $s = $db->prepare("SELECT title, phone, calendar_url, website_url FROM email_signatures WHERE email=?");
    $s->execute([$me]);
    $row = $s->fetch(PDO::FETCH_ASSOC) ?: ['title'=>'','phone'=>'','calendar_url'=>'','website_url'=>''];
    echo json_encode(array_merge(['ok'=>true], $row));
    exit;
}

if ($action === 'save_signature') {
    $title = trim($body['title']        ?? '');
    $phone = trim($body['phone']        ?? '');
    $cal   = trim($body['calendar_url'] ?? '');
    $web   = trim($body['website_url']  ?? '');
    $db->prepare(
        "INSERT INTO email_signatures (email, title, phone, calendar_url, website_url, updated_at)
         VALUES (?,?,?,?,?,datetime('now'))
         ON CONFLICT(email) DO UPDATE SET
           title=excluded.title, phone=excluded.phone, calendar_url=excluded.calendar_url,
           website_url=excluded.website_url, updated_at=excluded.updated_at"
    )->execute([$me, $title, $phone, $cal, $web]);
    echo json_encode(['ok'=>true]);
    exit;
}

// Roster for the single-person picker's datalist. Non-admins only see people
// in their own Market Center(s) — same scoping ce_validate_audience enforces.
if ($action === 'roster_list') {
    $mine = my_mc_slugs();
    $out  = [];
    foreach (ce_fetch_crm_roster() as $a) {
        $email = strtolower(trim($a['email'] ?? ''));
        if (!$email) continue;
        $mc   = $a['marketCenter'] ?? '';
        $slug = $mc ? slugify_mc($mc) : '';
        if (!is_admin() && (!$slug || !in_array($slug, $mine, true))) continue;
        $out[] = ['name' => $a['fullName'] ?? '', 'email' => $email, 'marketCenter' => $mc];
    }
    echo json_encode(['ok'=>true, 'agents'=>$out]);
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

if ($action === 'scheduled_list') {
    if (is_admin()) {
        $rows = $db->query(
            "SELECT id, sender_email, audience, target_mc_slug, target_email, subject, send_at, recipient_count
             FROM scheduled_emails WHERE status='pending' ORDER BY send_at"
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $s = $db->prepare(
            "SELECT id, sender_email, audience, target_mc_slug, target_email, subject, send_at, recipient_count
             FROM scheduled_emails WHERE status='pending' AND sender_email=? ORDER BY send_at"
        );
        $s->execute([$me]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['ok'=>true, 'rows'=>$rows]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
